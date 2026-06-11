<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\Canvas\Loom\Annotations\Column as ColumnAnnotation;
	use Quellabs\Canvas\Loom\Entity\EntityReader;
	
	/**
	 * Builds a resource node — the top-level form container.
	 */
	class Resource extends AbstractNode {
		
		/**
		 * Fields grouped by @Loom\Field group name, populated by EntityReader.
		 * Null key holds ungrouped fields.
		 * Only set when the Resource was created via makeFromEntity().
		 * @var array<string|null, Field[]>|null
		 */
		private ?array $groupedFields = null;
		
		/**
		 * Column annotations keyed by name, in declaration order.
		 * Only set when the Resource was created via makeFromEntity().
		 * @var array<string, ColumnAnnotation>|null
		 */
		private ?array $columnMap = null;
		
		/**
		 * @param string $id Form id, also used as WakaPAC component id
		 * @param string $action Form action URL
		 */
		protected function __construct(string $id, string $action) {
			$this->properties['id'] = $id;
			$this->properties['action'] = $action;
		}
		
		/**
		 * @param string $id Form id, also used as WakaPAC component id
		 * @param string $action Form action URL
		 */
		public static function make(string $id, string $action): static {
			return new static($id, $action);
		}

		/**
		 * Build a Resource from an entity's @Loom annotations.
		 * Fields are added in property declaration order.
		 * If the entity declares @Loom\\Column groups, call wrapInColumns()
		 * on the returned Resource to activate column layout; without that
		 * call the form renders flat.
		 * @param object|string $entity Entity instance or class name
		 * @param AnnotationReader $reader
		 * @return self
		 * @throws \InvalidArgumentException When @Loom\Resource annotation is missing
		 */
		public static function makeFromEntity(object|string $entity, AnnotationReader $reader): self {
			/** @var class-string|object $classOrObject */
			$classOrObject = is_string($entity) ? (class_exists($entity) ? $entity : throw new \InvalidArgumentException("Class '{$entity}' does not exist.")) : $entity;
			return (new EntityReader($reader))->read($classOrObject);
		}
		
		/**
		 * Distribute entity-derived fields into column containers.
		 * Only has effect when the Resource was created via makeFromEntity()
		 * and the entity declares @Loom\Column groups.
		 *
		 * Fields are placed into the column whose name matches their @Loom\Field group=.
		 * Ungrouped fields (no group= set) are prepended flat before the columns,
		 * preserving their declaration order.
		 *
		 * If a column name in $columnContainers has no matching @Loom\Column on the
		 * entity, it is still used — the width defaults to equal distribution.
		 *
		 * @param array<string, Column> $columnContainers Map of group name => Column builder
		 * @return static
		 * @throws \LogicException When called on a Resource not created via makeFromEntity()
		 */
		public function wrapInColumns(array $columnContainers): static {
			if ($this->groupedFields === null || $this->columnMap === null) {
				throw new \LogicException(
					'wrapInColumns() can only be called on a Resource created via makeFromEntity(). ' .
					'Use the builder API directly for manually constructed forms.'
				);
			}
			
			// Ungrouped fields stay flat above the columns
			$this->children = [];
			
			foreach ($this->groupedFields[null] ?? [] as $field) {
				$this->children[] = $field;
			}
			
			// Derive widths from @Loom\Column annotations; fall back to equal distribution
			$names = array_keys($columnContainers);
			$widths = $this->resolveWidths($names);
			
			// Build the Columns container and populate each Column
			$columns = Columns::make($widths);
			
			foreach ($columnContainers as $groupName => $column) {
				foreach ($this->groupedFields[$groupName] ?? [] as $field) {
					$column->add($field);
				}
				
				$columns->add($column);
			}
			
			$this->children[] = $columns;
			return $this;
		}
		
		/**
		 * Store grouped fields produced by EntityReader.
		 * Called internally by EntityReader — not part of the public API.
		 * @param array<string|null, Field[]> $groupedFields
		 * @return static
		 * @internal
		 */
		public function setGroupedFields(array $groupedFields): static {
			$this->groupedFields = $groupedFields;
			return $this;
		}
		
		/**
		 * Store the column map produced by EntityReader.
		 * Called internally by EntityReader — not part of the public API.
		 * @param array<string, ColumnAnnotation> $columnMap
		 * @return static
		 * @internal
		 */
		public function setColumnMap(array $columnMap): static {
			$this->columnMap = $columnMap;
			return $this;
		}
		
		/**
		 * Store entity field values extracted by EntityReader.
		 * These are used by FieldRenderer as a fallback when no explicit value
		 * is present in the data array passed to Loom::render().
		 * Called internally by EntityReader — not part of the public API.
		 * @param array<string, mixed> $data
		 * @return static
		 * @internal
		 */
		public function setEntityData(array $data): static {
			return $this->set('entity_data', $data);
		}

		/**
		 * Resolve column widths for the given group names.
		 * Uses @Loom\Column width values where available; distributes
		 * remaining width equally among columns with no declared width.
		 * @param string[] $names
		 * @return int[]
		 */
		private function resolveWidths(array $names): array {
			/** @var array<int, int|null> $widths */
			$widths = [];
			$missing = [];
			$used = 0;
			
			foreach ($names as $name) {
				if (isset($this->columnMap[$name])) {
					$w = $this->columnMap[$name]->getWidth();
					$widths[] = $w;
					$used += $w;
				} else {
					$widths[] = null;
					$missing[] = count($widths) - 1;
				}
			}
			
			// Distribute remaining width equally among undeclared columns
			if (!empty($missing)) {
				$share = max(1, (int)floor((100 - $used) / count($missing)));
				
				foreach ($missing as $idx) {
					$widths[$idx] = $share;
				}
			}
			
			// All null slots have been filled by $share; map to guarantee int[]
			/** @var int[] $result */
			$result = array_map(fn($w) => $w ?? 0, $widths);
			return $result;
		}
		
		/**
		 * Set the entity prefix used to scope HTML field name attributes.
		 * When set, field names are rendered as Prefix[fieldName] (e.g. PostEntity[title])
		 * so submitted data can be traced back to the originating entity.
		 * WakaPAC bindings, field ids, and value resolution are unaffected.
		 * Set automatically by EntityReader using the short class name.
		 * @param string $prefix Short class name, e.g. 'PostEntity'
		 * @return static
		 */
		public function entityPrefix(string $prefix): static {
			return $this->set('entity_prefix', $prefix);
		}

		/**
		 * Set the page title shown in the header
		 * @param string $title
		 * @return static
		 */
		public function title(string $title): static {
			return $this->set('title', $title);
		}
		
		/**
		 * Set the form method
		 * @param string $method GET, POST, PUT, PATCH or DELETE
		 * @return static
		 */
		public function method(string $method): static {
			return $this->set('method', $method);
		}
		
		/**
		 * Set the save button label
		 * @param string $label
		 * @return static
		 */
		public function saveLabel(string $label): static {
			return $this->set('save_label', $label);
		}
		
		/**
		 * Disable the save button
		 * @return static
		 */
		public function saveDisabled(): static {
			return $this->set('save_disabled', true);
		}
		
		/**
		 * Add a button to the resource header.
		 * Header buttons are hidden by default and can be shown via WakaPAC messages.
		 * @param Button $button
		 * @return static
		 */
		public function addHeaderButton(Button $button): static {
			$existing = $this->get('header_buttons');
			$buttons = is_array($existing) ? $existing : [];
			$buttons[] = $button;
			return $this->set('header_buttons', $buttons);
		}
		
		/**
		 * Enable WakaForm client-side validation for this resource.
		 * When set, buildScript() will emit a createForm() call with rules
		 * derived from the field definitions. Only fields with rules attached
		 * via Field::rules() are included in the createForm() schema.
		 * Requires wakaForm to be registered as a wakaPAC plugin on the page.
		 * @return static
		 */
		public function useWakaForm(): static {
			return $this->set('use_wakaform', true);
		}
		
		/**
		 * Build the node array for Loom::render()
		 * @return array<string, mixed>
		 */
		public function build(): array {
			return $this->toArray();
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'resource';
		}
		
	}