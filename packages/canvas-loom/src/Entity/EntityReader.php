<?php
	
	namespace Quellabs\Canvas\Loom\Entity;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\Canvas\Loom\Annotations\Column as ColumnAnnotation;
	use Quellabs\Canvas\Loom\Annotations\Field as FieldAnnotation;
	use Quellabs\Canvas\Loom\Annotations\Resource as ResourceAnnotation;
	use Quellabs\Canvas\Loom\Builder\Field;
	use Quellabs\Canvas\Loom\Builder\Resource;
	
	/**
	 * Reads @Loom annotations from an entity class and builds a Resource.
	 *
	 * Property declaration order is preserved — fields appear in the form
	 * in the same order they are declared in the entity class.
	 *
	 * Auto-skipped properties (no @Loom\Field annotation needed to exclude):
	 *   - Primary keys (@Orm\PrimaryKeyStrategy present on the property)
	 *   - Soft-delete sentinels (@Orm\SoftDelete present on the property)
	 *   - Source fields (@Orm\SourceField present on the property)
	 *   - Properties with no @Loom\Field annotation
	 *
	 * ORM type → default input type mapping:
	 *   string        → text
	 *   text          → textarea
	 *   integer/float → number
	 *   boolean       → toggle
	 *   datetime      → datetime-local
	 *   date          → date
	 *   enum          → select (options must be supplied by the controller)
	 *   json          → omitted unless @Loom\Field is present with an explicit input=
	 *   (relation)    → omitted unless @Loom\Field is present with an explicit input=
	 */
	class EntityReader {
		
		/** @var AnnotationReader */
		private AnnotationReader $annotationReader;
		
		/**
		 * ORM column type → Loom input type
		 * @var array<string, string>
		 */
		private array $typeMap = [
			'string'   => 'text',
			'text'     => 'textarea',
			'integer'  => 'number',
			'float'    => 'number',
			'boolean'  => 'toggle',
			'datetime' => 'datetime-local',
			'date'     => 'date',
			'enum'     => 'select',
		];
		
		/**
		 * ORM annotation FQCNs checked for auto-skip.
		 * Properties carrying any of these are excluded from the form
		 * even when @Loom\Field is present.
		 */
		private const SKIP_ANNOTATIONS = [
			'Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy',
			'Quellabs\\ObjectQuel\\Annotations\\Orm\\SoftDelete',
			'Quellabs\\ObjectQuel\\Annotations\\Orm\\SourceField',
		];
		
		/**
		 * @param AnnotationReader $annotationReader
		 */
		public function __construct(AnnotationReader $annotationReader) {
			$this->annotationReader = $annotationReader;
		}
		
		/**
		 * Read @Loom annotations from an entity and return a configured Resource builder.
		 * The returned Resource has fields added in property declaration order.
		 * Column group metadata is stored on the Resource for later use by wrapInColumns().
		 * @param class-string|object $entity Entity instance or class name
		 * @return Resource
		 * @throws \InvalidArgumentException When @Loom\Resource annotation is missing
		 * @throws \ReflectionException
		 */
		public function read(object|string $entity): Resource {
			$reflection = new \ReflectionClass($entity);
			
			// Read and validate the class-level @Loom\Resource annotation
			$resourceAnnotation = $this->readResourceAnnotation($reflection->getName());
			
			// Build the Resource
			$resource = Resource::make($resourceAnnotation->getId(), $resourceAnnotation->getAction())
				->title($resourceAnnotation->getTitle())
				->method($resourceAnnotation->getMethod())
				->entityPrefix($reflection->getShortName());
			
			// Read @Loom\Column annotations from the class and store on the Resource
			// so wrapInColumns() can reference them later.
			$columnAnnotations = $this->readColumnAnnotations($reflection->getName());
			$resource->setColumnMap($columnAnnotations);
			
			// Walk properties in declaration order, build Field builders, group them
			$groupedFields = $this->readFields($reflection, $entity);
			
			// Store fields grouped by their group name on the Resource.
			// wrapInColumns() will distribute them; a flat render just adds them all.
			$resource->setGroupedFields($groupedFields);
			
			// Extract entity field values via getters in the same pass, so render()
			// can populate field values from the entity without a second traversal.
			// Only done when an instance is provided — class-name-only calls have no values.
			if (is_object($entity)) {
				$resource->setEntityData($this->extractEntityData($entity, $groupedFields));
			}
			
			// Add all fields to the resource in order: ungrouped first (preserving
			// declaration order), then grouped fields in column declaration order.
			// This ensures a sensible flat render even when groups are defined.
			foreach ($groupedFields[null] ?? [] as $field) {
				$resource->add($field);
			}
			
			foreach ($columnAnnotations as $name => $_) {
				foreach ($groupedFields[$name] ?? [] as $field) {
					$resource->add($field);
				}
			}
			
			return $resource;
		}
		
		/**
		 * Read and return the @Loom\Resource annotation from the class.
		 * @param class-string $className
		 * @return ResourceAnnotation
		 * @throws \InvalidArgumentException When the annotation is missing
		 */
		private function readResourceAnnotation(string $className): ResourceAnnotation {
			$classAnnotations = $this->annotationReader->getClassAnnotations($className, ResourceAnnotation::class);
			
			/** @var ResourceAnnotation|null $resourceAnnotation */
			$resourceAnnotation = $classAnnotations->first();
			
			if ($resourceAnnotation === null) {
				throw new \InvalidArgumentException(
					"Class '{$className}' has no @Loom\\Resource annotation. " .
					"Add @Loom\\Resource(id=\"...\", action=\"...\") to the class docblock."
				);
			}
			
			return $resourceAnnotation;
		}
		
		/**
		 * Read all @Loom\Column annotations from the class, keyed by column name
		 * in the order they were declared.
		 * @param class-string $className
		 * @return array<string, ColumnAnnotation>
		 */
		private function readColumnAnnotations(string $className): array {
			$columnAnnotations = $this->annotationReader->getClassAnnotations($className, ColumnAnnotation::class);
			$columns = [];
			
			foreach ($columnAnnotations->all(ColumnAnnotation::class) as $column) {
				/** @var ColumnAnnotation $column */
				if ($column->getName() !== '') {
					$columns[$column->getName()] = $column;
				}
			}
			
			return $columns;
		}
		
		/**
		 * Walk the entity's properties in declaration order and build Field builders
		 * for each property that carries a @Loom\Field annotation and is not auto-skipped.
		 * Returns fields grouped by their group name, with null as the key for ungrouped fields.
		 * @param \ReflectionClass<object> $reflection
		 * @param object|string $entity
		 * @return array<string|null, Field[]>
		 */
		private function readFields(\ReflectionClass $reflection, object|string $entity): array {
			$grouped = [];
			$className = $reflection->getName();
			
			// getProperties() preserves declaration order
			foreach ($reflection->getProperties() as $property) {
				$propertyName = $property->getName();
				$propertyAnnotations = $this->annotationReader->getPropertyAnnotations($className, $propertyName);
				
				// Skip properties with no @Loom\Field annotation
				/** @var FieldAnnotation|null $fieldAnnotation */
				$fieldAnnotation = $propertyAnnotations->getFirst(FieldAnnotation::class);
				
				if ($fieldAnnotation === null) {
					continue;
				}
				
				// Skip auto-excluded properties (primary keys, soft deletes, source fields)
				if ($this->shouldSkip($propertyAnnotations)) {
					continue;
				}
				
				// Derive the input type from the ORM column type, then let the
				// @Loom\Field input= parameter override it
				$inputType = $this->resolveInputType($propertyAnnotations, $fieldAnnotation);
				
				// Skip properties we cannot map to any input type (e.g. json, relations
				// without an explicit input= on @Loom\Field)
				if ($inputType === null) {
					continue;
				}
				
				// Build the Field builder
				$field = $this->buildField($propertyName, $inputType, $fieldAnnotation, $propertyAnnotations);
				
				// Store under the group name (null = ungrouped)
				$group = $fieldAnnotation->getGroup();
				$grouped[$group][] = $field;
			}
			
			return $grouped;
		}
		
		/**
		 * Return true if the property should be excluded from the form regardless
		 * of whether @Loom\Field is present.
		 * @param \Quellabs\AnnotationReader\Collection\AnnotationCollection $annotations
		 * @return bool
		 */
		private function shouldSkip(\Quellabs\AnnotationReader\Collection\AnnotationCollection $annotations): bool {
			foreach (self::SKIP_ANNOTATIONS as $fqcn) {
				if ($annotations->getFirst($fqcn) !== null) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Resolve the input type for a field.
		 * Priority: @Loom\Field input= > ORM column type mapping.
		 * Returns null when no mapping can be determined (json, unrecognised types,
		 * relations without an explicit input=).
		 * @param \Quellabs\AnnotationReader\Collection\AnnotationCollection $propertyAnnotations
		 * @param FieldAnnotation $fieldAnnotation
		 * @return string|null
		 */
		private function resolveInputType(
			\Quellabs\AnnotationReader\Collection\AnnotationCollection $propertyAnnotations,
			FieldAnnotation $fieldAnnotation
		): ?string {
			// Explicit override on @Loom\Field always wins
			if ($fieldAnnotation->getInput() !== null) {
				return $fieldAnnotation->getInput();
			}
			
			// Derive from the ORM @Orm\Column type
			$ormColumn = $propertyAnnotations->getFirst('Quellabs\\ObjectQuel\\Annotations\\Orm\\Column');
			
			if (!($ormColumn instanceof \Quellabs\AnnotationReader\AnnotationInterface)) {
				// No ORM column annotation (e.g. a relation property) — cannot derive type
				return null;
			}
			
			$ormParams = $ormColumn->getParameters();
			$ormType = is_string($ormParams['type'] ?? null) ? $ormParams['type'] : null;
			
			return $this->typeMap[$ormType] ?? null;
		}
		
		/**
		 * Build a Field builder from a property name, resolved input type, and annotations.
		 * @param string $propertyName
		 * @param string $inputType
		 * @param FieldAnnotation $fieldAnnotation
		 * @param \Quellabs\AnnotationReader\Collection\AnnotationCollection $propertyAnnotations
		 * @return Field
		 */
		private function buildField(
			string $propertyName,
			string $inputType,
			FieldAnnotation $fieldAnnotation,
			\Quellabs\AnnotationReader\Collection\AnnotationCollection $propertyAnnotations
		): Field {
			$label = $fieldAnnotation->getLabel();
			
			// Instantiate the correct Field factory method.
			// datetime-local cannot be called via dynamic dispatch because hyphens
			// are not valid in PHP method names, so it gets an explicit case.
			$field = match ($inputType) {
				'richtext' => Field::richtext($propertyName, $label, $fieldAnnotation->getEditor() ?? 'jodit'),
				'datetime-local' => Field::datetimeLocal($propertyName, $label),
				default => Field::{$inputType}($propertyName, $label),
			};
			
			// Apply modifiers from @Loom\Field
			if ($fieldAnnotation->isRequired()) {
				$field->required();
			}
			
			if ($fieldAnnotation->isReadonly()) {
				$field->readonly();
			}
			
			if ($fieldAnnotation->isDisabled()) {
				$field->disabled();
			}
			
			if ($fieldAnnotation->getHint() !== null) {
				$field->hint($fieldAnnotation->getHint());
			}
			
			if ($fieldAnnotation->getPlaceholder() !== null) {
				$field->placeholder($fieldAnnotation->getPlaceholder());
			}
			
			if ($fieldAnnotation->getRows() !== null) {
				$field->rows($fieldAnnotation->getRows());
			}
			
			// Apply explicit choices from @Loom\Field — always takes precedence over
			// enum auto-population. Choices are stored as value => label pairs.
			$choices = $fieldAnnotation->getChoices();

			if ($choices !== null) {
				$options = [];

				foreach ($choices as $value => $label) {
					$options[] = ['value' => (string)$value, 'label' => (string)$label];
				}

				$field->options($options);
			}

			// Apply ORM-derived constraints (maxlength, enum options) only when no
			// input= override is set and no explicit choices were provided.
			if ($fieldAnnotation->getInput() === null && $choices === null) {
				$this->applyOrmConstraints($field, $inputType, $propertyAnnotations);
			}
			
			return $field;
		}
		
		/**
		 * Apply constraints derived from the ORM column annotation (e.g. maxlength from limit=).
		 * Only called when no explicit @Loom\Field input= override is in effect, so we don't
		 * apply text constraints to a field the developer has overridden to a different type.
		 * @param Field $field
		 * @param string $inputType
		 * @param \Quellabs\AnnotationReader\Collection\AnnotationCollection $propertyAnnotations
		 * @return void
		 */
		private function applyOrmConstraints(
			Field $field,
			string $inputType,
			\Quellabs\AnnotationReader\Collection\AnnotationCollection $propertyAnnotations
		): void {
			$ormColumn = $propertyAnnotations->getFirst('Quellabs\\ObjectQuel\\Annotations\\Orm\\Column');
			
			if (!($ormColumn instanceof \Quellabs\AnnotationReader\AnnotationInterface)) {
				return;
			}
			
			$ormParams = $ormColumn->getParameters();
			
			// Apply maxlength from ORM limit for string-based inputs
			if (in_array($inputType, ['text', 'email', 'tel', 'url'], true) && isset($ormParams['limit']) && is_numeric($ormParams['limit'])) {
				$field->maxlength((int)$ormParams['limit']);
			}

			// Auto-populate select options from the enum class for enum-typed columns.
			// The ORM annotation carries enumType= which is the fully qualified enum class name.
			// Options are derived from ::cases() using the backing value and raw case name.
			if ($inputType === 'select' && isset($ormParams['enumType'])) {
				$enumClass = $ormParams['enumType'];

				if (is_a($enumClass, \BackedEnum::class, true)) {
					$options = array_map(
						fn(\BackedEnum $case) => ['value' => $case->value, 'label' => $case->name],
						$enumClass::cases()
					);

					$field->options($options);
				}
			}
		}
		
		/**
		 * Extract field values from an entity instance by calling getters.
		 * Only extracts values for fields that were included in the form —
		 * properties that were skipped or unmapped are not read.
		 * Getter name is derived as get{PropertyName} e.g. $title -> getTitle().
		 * Properties with no matching getter are silently skipped.
		 * DateTime values are formatted to match the field's input type so the
		 * HTML input element receives the correct string format.
		 * @param object $entity
		 * @param array<string|null, Field[]> $groupedFields
		 * @return array<string, mixed>
		 */
		private function extractEntityData(object $entity, array $groupedFields): array {
			$data = [];
			
			foreach ($groupedFields as $fields) {
				foreach ($fields as $field) {
					$name = $field->get('name');
					
					if (!is_string($name)) {
						continue;
					}
					
					$getter = 'get' . ucfirst($name);
					
					if (!method_exists($entity, $getter)) {
						continue;
					}
					
					$value = $entity->$getter();
					
					// DateTime objects must be formatted to match the HTML input type.
					// The format is determined by the field's resolved input type so the
					// browser receives the exact string the input element expects.
					if ($value instanceof \DateTimeInterface) {
						$value = match ($field->get('input')) {
							'date' => $value->format('Y-m-d'),
							'time' => $value->format('H:i'),
							'datetime-local' => $value->format('Y-m-d\\TH:i'),
							'week' => $value->format('Y-\\WW'),
							'month' => $value->format('Y-m'),
							default => $value->format('Y-m-d\\TH:i'),
						};
					} elseif ($value instanceof \BackedEnum) {
						// Backed enums expose their backing value (string or int) which is
						// what the select option value and form submission both use.
						$value = $value->value;
					}
					
					$data[(string)$name] = $value;
				}
			}
			
			return $data;
		}
	}