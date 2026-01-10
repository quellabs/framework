<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║   ██████╗ ██████╗      ██╗███████╗ ██████╗████████╗ ██████╗ ██╗   ██╗███████╗██╗      ║
	 * ║  ██╔═══██╗██╔══██╗     ██║██╔════╝██╔════╝╚══██╔══╝██╔═══██╗██║   ██║██╔════╝██║      ║
	 * ║  ██║   ██║██████╔╝     ██║█████╗  ██║        ██║   ██║   ██║██║   ██║█████╗  ██║      ║
	 * ║  ██║   ██║██╔══██╗██   ██║██╔══╝  ██║        ██║   ██║▄▄ ██║██║   ██║██╔══╝  ██║      ║
	 * ║  ╚██████╔╝██████╔╝╚█████╔╝███████╗╚██████╗   ██║   ╚██████╔╝╚██████╔╝███████╗███████╗ ║
	 * ║   ╚═════╝ ╚═════╝  ╚════╝ ╚══════╝ ╚═════╝   ╚═╝    ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝ ║
	 * ║                                                                                       ║
	 * ║  ObjectQuel - Powerful Object-Relational Mapping built on the Data Mapper pattern     ║
	 * ║                                                                                       ║
	 * ║  Clean separation between entities and persistence logic with an intuitive,           ║
	 * ║  object-oriented query language. Powered by CakePHP's robust database foundation.     ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
	namespace Quellabs\ObjectQuel;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	
	/**
	 * Immutable value object containing all metadata for a single entity.
	 */
	readonly class EntityMetadata {
		
		/**
		 * Private constructor - use EntityMetadata::fromClass() to create instances.
		 * @param string $className Fully qualified, normalized class name
		 * @param string $tableName Database table name from @Table annotation
		 * @param array<string, mixed> $properties Property names and their reflection info
		 * @param array<string, AnnotationCollection> $annotations Property name => annotation collection mapping
		 * @param array<string, string> $columnMap Property name => column name mapping
		 * @param array<string> $identifierKeys Property names that serve as primary keys
		 * @param array<string> $identifierColumns Column names that serve as primary keys
		 * @param array<string, array{name: string, column: Column, version: Version}> $versionColumns Properties with version tracking
		 * @param array<string, ManyToOne> $manyToOneRelations Property => ManyToOne annotation mapping
		 * @param array<string, OneToMany> $oneToManyRelations Property => OneToMany annotation mapping
		 * @param array<string, OneToOne> $oneToOneRelations Property => OneToOne annotation mapping
		 * @param array<Index|UniqueIndex> $indexes Index annotations from class level
		 * @param string|null $autoIncrementColumn Property name of auto-increment primary key (if any)
		 * @param array<string, array> $columnDefinitions Full column definitions for schema generation
		 */
		private function __construct(
			public string  $className,
			public string  $tableName,
			public array   $properties,
			public array   $annotations,
			public array   $columnMap,
			public array   $identifierKeys,
			public array   $identifierColumns,
			public array   $versionColumns,
			public array   $manyToOneRelations,
			public array   $oneToManyRelations,
			public array   $oneToOneRelations,
			public array   $indexes,
			public ?string $autoIncrementColumn,
			public array   $columnDefinitions,
		) {
		}
		
		/**
		 * Factory method to build EntityMetadata from a class using annotations.
		 * @param string $className Fully qualified, normalized class name
		 * @param AnnotationReader $annotationReader The annotation reader to use
		 * @return self A fully populated EntityMetadata instance
		 * @throws \ReflectionException If the class cannot be reflected
		 * @throws AnnotationReaderException
		 */
		public static function fromClass(string $className, AnnotationReader $annotationReader): self {
			// Get reflection for the entity class
			$reflection = new \ReflectionClass($className);
			
			// Extract table name from @Table annotation
			$tableName = self::extractTableName($className, $annotationReader);
			
			// Extract property-level information
			$properties = self::extractProperties($reflection);
			$annotations = self::extractPropertyAnnotations($className, $annotationReader, $properties);
			
			// Build derived metadata from annotations
			$columnMap = self::buildColumnMap($annotations);
			$identifierKeys = self::buildIdentifierKeys($annotations);
			$identifierColumns = self::buildIdentifierColumns($identifierKeys, $columnMap);
			$versionColumns = self::extractVersionColumns($annotations);
			
			// Extract relationship annotations
			$manyToOneRelations = self::extractRelations($annotations, ManyToOne::class);
			$oneToManyRelations = self::extractRelations($annotations, OneToMany::class);
			$oneToOneRelations = self::extractRelations($annotations, OneToOne::class);
			
			// Extract class-level annotations
			$indexes = self::extractIndexes($className, $annotationReader);
			
			// Determine auto-increment column
			$autoIncrementColumn = self::extractAutoIncrementColumn($annotations);
			
			// Build full column definitions for schema generation
			$columnDefinitions = self::extractColumnDefinitions($className, $annotations);
			
			// Create and return the immutable metadata object
			return new self(
				className: $className,
				tableName: $tableName,
				properties: $properties,
				annotations: $annotations,
				columnMap: $columnMap,
				identifierKeys: $identifierKeys,
				identifierColumns: $identifierColumns,
				versionColumns: $versionColumns,
				manyToOneRelations: $manyToOneRelations,
				oneToManyRelations: $oneToManyRelations,
				oneToOneRelations: $oneToOneRelations,
				indexes: $indexes,
				autoIncrementColumn: $autoIncrementColumn,
				columnDefinitions: $columnDefinitions,
			);
		}
		
		// ==================== Public API Methods ====================
		
		/**
		 * Retrieves the primary key of the entity.
		 * For composite primary keys, returns the first key.
		 * @return string|null The primary key property name, or null if no primary key exists
		 */
		public function getPrimaryKey(): ?string {
			return $this->identifierKeys[0] ?? null;
		}
		
		/**
		 * Retrieves all primary keys of the entity.
		 * @return array The primary keys
		 */
		public function getPrimaryKeys(): array {
			return $this->identifierKeys;
		}
		
		/**
		 * Check if this entity has an auto-increment primary key.
		 * An auto-increment key is one that is automatically generated by the database.
		 * @return bool True if the entity has an auto-increment primary key, false otherwise
		 */
		public function hasAutoIncrementPrimaryKey(): bool {
			return $this->autoIncrementColumn !== null;
		}
		
		/**
		 * Retrieve the ManyToOne dependencies for this entity.
		 * These represent entities that this entity has a foreign key reference to.
		 * @return ManyToOne[] Array of ManyToOne annotations
		 */
		public function getManyToOneDependencies(): array {
			return array_values($this->manyToOneRelations);
		}
		
		/**
		 * Retrieve the OneToOne dependencies where this entity is the owning side.
		 * Only returns OneToOne relations that have an inversedBy property set,
		 * indicating this entity owns the relationship.
		 * @return OneToOne[] Array of OneToOne annotations for owned relationships
		 */
		public function getOneToOneDependencies(): array {
			return array_filter($this->oneToOneRelations, fn($relation) => !empty($relation->getInversedBy()));
		}
		
		/**
		 * Obtains the database column name for a given property.
		 * @param string $property The entity property name
		 * @return string|null The corresponding column name, or null if property doesn't have a column mapping
		 */
		public function getColumnName(string $property): ?string {
			return $this->columnMap[$property] ?? null;
		}
		
		/**
		 * Obtains the entity property name for a given database column.
		 * @param string $columnName The database column name
		 * @return string|null The corresponding property name, or null if column doesn't map to a property
		 */
		public function getPropertyName(string $columnName): ?string {
			return array_flip($this->columnMap)[$columnName] ?? null;
		}
		
		/**
		 * Checks if a property is part of the entity's primary key.
		 * @param string $property The property name to check
		 * @return bool True if the property is a primary key, false otherwise
		 */
		public function isIdentifierKey(string $property): bool {
			return in_array($property, $this->identifierKeys, true);
		}
		
		/**
		 * Checks if a property has version tracking enabled.
		 * Version tracking is used for optimistic locking.
		 * @param string $property The property name to check
		 * @return bool True if the property has version tracking, false otherwise
		 */
		public function isVersioned(string $property): bool {
			return isset($this->versionColumns[$property]);
		}
		
		/**
		 * Normalizes the primary key into an array.
		 * This function checks if the given primary key is already an array.
		 * If not, it converts the primary key into an array with the proper key
		 * based on the entity's identifier keys.
		 * @param mixed $primaryKey The primary key to be normalized
		 * @return array A normalized representation of the primary key as an array
		 */
		public function formatPrimaryKeyAsArray(mixed $primaryKey): array {
			// If the primary key is already an array, return it directly
			if (is_array($primaryKey)) {
				return $primaryKey;
			}
			
			// Otherwise, get the first identifier key and create an array with the proper key and value
			return ($key = $this->identifierKeys[0] ?? null) ? [$key => $primaryKey] : [];
		}
		
		// ==================== Private Extraction Methods ====================
		
		/**
		 * Extract the table name from the @Table annotation.
		 *
		 * With the updated AnnotationCollection, array access returns an array of all
		 * matching annotations. We validate that exactly one @Table annotation exists.
		 *
		 * @param string $className The fully qualified class name
		 * @param AnnotationReader $annotationReader The annotation reader instance
		 * @return string The database table name
		 * @throws \RuntimeException|AnnotationReaderException If no @Table annotation is found or multiple exist
		 */
		private static function extractTableName(string $className, AnnotationReader $annotationReader): string {
			try {
				// Read the class annotations
				$classAnnotations = $annotationReader->getClassAnnotations($className);
				
				// Array access returns AnnotationCollection of all matching annotations
				$tableAnnotations = $classAnnotations[Table::class];
				
				// Show error when table has no @Table annotations
				if ($tableAnnotations->isEmpty()) {
					throw new \RuntimeException("Entity {$className} is missing required @Table annotation");
				}
				
				return $tableAnnotations->last()->getName();
			} catch (ParserException $e) {
				throw new \RuntimeException("Failed to parse annotations for {$className}: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Extract all properties from the entity class.
		 * Returns property names as keys with their ReflectionProperty objects as values.
		 * @param \ReflectionClass $reflection The reflection object for the entity
		 * @return array<string, \ReflectionProperty> Property name => ReflectionProperty mapping
		 */
		private static function extractProperties(\ReflectionClass $reflection): array {
			$properties = [];
			
			foreach ($reflection->getProperties() as $property) {
				$properties[$property->getName()] = $property;
			}
			
			return $properties;
		}
		
		/**
		 * Extract all annotations for all properties in the entity.
		 * @param string $className The fully qualified class name
		 * @param AnnotationReader $annotationReader The annotation reader instance
		 * @param array $properties The properties extracted from the entity
		 * @return array<string, AnnotationCollection> Property name => AnnotationCollection mapping
		 * @throws AnnotationReaderException
		 */
		private static function extractPropertyAnnotations(string $className, AnnotationReader $annotationReader, array $properties): array {
			$annotations = [];
			
			foreach (array_keys($properties) as $propertyName) {
				try {
					// Get all annotations for this property
					$propertyAnnotations = $annotationReader->getPropertyAnnotations($className, $propertyName);
					
					// Only store if there are annotations present
					if (!$propertyAnnotations->isEmpty()) {
						$annotations[$propertyName] = $propertyAnnotations;
					}
				} catch (ParserException $e) {
					// Skip properties with annotation parse errors
					continue;
				}
			}
			
			return $annotations;
		}
		
		/**
		 * Build the property => column name mapping from Column annotations.
		 * @param array $annotations The property annotations
		 * @return array<string, string> Property name => column name mapping
		 */
		private static function buildColumnMap(array $annotations): array {
			$result = [];
			
			foreach ($annotations as $propertyName => $collection) {
				// Array access returns array of all Column annotations
				$columnAnnotations = $collection[Column::class];
				
				// Check if any column annotation exist, if so add name to map
				// Use the last (and should be only) Column annotation
				if (!$columnAnnotations->isEmpty()) {
					$result[$propertyName] = $columnAnnotations->last()->getName();
				}
			}
			
			return $result;
		}
		
		/**
		 * Build the list of identifier (primary key) property names.
		 * @param array $annotations The property annotations
		 * @return array<string> Array of property names that are primary keys
		 */
		private static function buildIdentifierKeys(array $annotations): array {
			$identifierKeys = [];
			
			foreach ($annotations as $propertyName => $collection) {
				// Get all Column annotations for this property (returns AnnotationCollection)
				$columnAnnotations = $collection[Column::class];
				
				// If property has Column annotation(s) and the last one marks it as primary key, include it
				if (!$columnAnnotations->isEmpty() && $columnAnnotations->last()->isPrimaryKey()) {
					$identifierKeys[] = $propertyName;
				}
			}
			
			return $identifierKeys;
		}
		
		/**
		 * Build the list of identifier (primary key) column names.
		 * @param array $identifierKeys The identifier property names
		 * @param array $columnMap The property => column name mapping
		 * @return array<string> Array of column names that are primary keys
		 */
		private static function buildIdentifierColumns(array $identifierKeys, array $columnMap): array {
			return array_values(array_intersect_key($columnMap, array_flip($identifierKeys)));
		}
		
		/**
		 * Extract version columns used for optimistic locking.
		 * @param array $annotations The property annotations
		 * @return array<string, array{name: string, column: Column, version: Version}> Property name => version info mapping
		 */
		private static function extractVersionColumns(array $annotations): array {
			$versionColumns = [];
			
			foreach ($annotations as $propertyName => $collection) {
				// Array access returns arrays of matching annotations, last() returns null for empty collections
				$columnAnnotation = $collection[Column::class]->last();
				$versionAnnotation = $collection[Version::class]->last();
				
				if ($columnAnnotation && $versionAnnotation) {
					$versionColumns[$propertyName] = [
						'name'    => $columnAnnotation->getName(),
						'column'  => $columnAnnotation,
						'version' => $versionAnnotation,
					];
				}
			}
			
			return $versionColumns;
		}
		
		/**
		 * Extract relationship annotations of a specific type.
		 * @param array $annotations The property annotations
		 * @param class-string $relationType The relationship annotation class (ManyToOne, OneToMany, or OneToOne)
		 * @return array Property name => relationship annotation mapping
		 */
		private static function extractRelations(array $annotations, string $relationType): array {
			$result = [];
			
			foreach ($annotations as $propertyName => $collection) {
				// Array access returns array of matching annotations, last() returns null for empty collections
				$relationAnnotation = $collection[$relationType]->last();
				
				if ($relationAnnotation) {
					$result[$propertyName] = $relationAnnotation;
				}
			}
			
			return $result;
		}
		
		/**
		 * Extract index annotations from class level.
		 * @param string $className The fully qualified class name
		 * @param AnnotationReader $annotationReader The annotation reader instance
		 * @return array Array of Index and UniqueIndex annotation objects
		 * @throws AnnotationReaderException
		 */
		private static function extractIndexes(string $className, AnnotationReader $annotationReader): array {
			// Get all class-level annotations
			$classAnnotations = $annotationReader->getClassAnnotations($className);
			
			// Filter to only Index and UniqueIndex annotations
			return $classAnnotations->filter(function ($annotation) {
				return $annotation instanceof Index || $annotation instanceof UniqueIndex;
			})->toIndexedArray();
		}
		
		/**
		 * Find the auto-increment column if one exists.
		 *
		 * A column is considered auto-increment if it:
		 * 1. Has a Column annotation marked as primary key, AND
		 * 2. Either:
		 *    - Has a PrimaryKeyStrategy annotation with value 'identity', OR
		 *    - Has no PrimaryKeyStrategy annotation at all (defaulting to auto-increment)
		 *
		 * @param array $annotations The property annotations
		 * @return string|null The property name of the auto-increment column, or null if none exists
		 */
		private static function findAutoIncrementColumn(array $annotations): ?string {
			foreach ($annotations as $propertyName => $collection) {
				if (self::isIdentityColumn($collection)) {
					return $propertyName;
				}
			}
			
			return null;
		}
		
		/**
		 * Extract the auto-increment column if one exists.
		 * @param array $annotations The property annotations
		 * @return string|null The property name of the auto-increment column, or null if none exists
		 */
		private static function extractAutoIncrementColumn(array $annotations): ?string {
			foreach ($annotations as $propertyName => $collection) {
				if (self::isIdentityColumn($collection)) {
					return $propertyName;
				}
			}
			
			return null;
		}
		
		/**
		 * Extract full column definitions for schema generation.
		 * @param string $className The fully qualified class name
		 * @param array $annotations Pre-extracted annotations (for performance)
		 * @return array Column name => column definition mapping
		 */
		private static function extractColumnDefinitions(
			string           $className,
			array            $annotations
		): array {
			$definitions = [];
			
			try {
				// Create a reflection object for the provided class to inspect its properties
				$reflection = new \ReflectionClass($className);
				
				// Iterate through all properties of the class
				foreach ($reflection->getProperties() as $property) {
					$propertyName = $property->getName();
					
					// Skip if we don't have annotations for this property
					if (!isset($annotations[$propertyName])) {
						continue;
					}
					
					// Get the Column annotation for this property (last() returns null for empty collections)
					$columnAnnotation = $annotations[$propertyName][Column::class]->last();
					
					// Skip if no Column annotation
					if (!$columnAnnotation) {
						continue;
					}
					
					// Use the column name from the annotation
					$columnName = $columnAnnotation->getName();
					
					// Fetch the database column type
					$columnType = $columnAnnotation->getType();
					
					// Determine if this is an identity column
					$isIdentity = self::isIdentityColumn($annotations[$propertyName]);
					
					// Build a comprehensive array of column metadata
					$definitions[$columnName] = [
						'property_name' => $propertyName,                           // PHP property name
						'type'          => $columnType,                             // Database column type
						'php_type'      => $property->getType(),                    // PHP type (from reflection)
						
						// Get column limit from annotation or use default based on the column type
						'limit'         => $columnAnnotation->getLimit() ?? TypeMapper::getDefaultLimit($columnType),
						'nullable'      => $columnAnnotation->isNullable(),         // Whether column allows NULL values
						'unsigned'      => $columnAnnotation->isUnsigned(),         // Whether numeric column is unsigned
						'default'       => $columnAnnotation->getDefault(),         // Default value for the column
						'primary_key'   => $columnAnnotation->isPrimaryKey(),       // Whether column is a primary key
						'scale'         => $columnAnnotation->getScale(),           // Decimal scale (for numeric types)
						'precision'     => $columnAnnotation->getPrecision(),       // Decimal precision (for numeric types)
						
						// Whether this column is an auto-incrementing identity column
						'identity'      => $isIdentity,
						
						// Read enum values if this is an enum type
						'values'        => TypeMapper::getEnumCases($columnAnnotation->getEnumType())
					];
				}
			} catch (\ReflectionException $e) {
				// Silently handle reflection exceptions - return what we have so far
			}
			
			// Return the complete set of column definitions
			return $definitions;
		}
		
		/**
		 * Determines if a property represents an auto-increment column.
		 * @param AnnotationCollection $collection The annotations for a single property
		 * @return bool Returns true if the property is an auto-increment column, false otherwise
		 */
		private static function isIdentityColumn(AnnotationCollection $collection): bool {
			// Get the Column annotation (last() returns null for empty collections)
			$columnAnnotation = $collection[Column::class]->last();
			
			// Must be a primary key
			if (!$columnAnnotation || !$columnAnnotation->isPrimaryKey()) {
				return false;
			}
			
			// Get the PrimaryKeyStrategy annotation (last() returns null for empty collections)
			$strategyAnnotation = $collection[PrimaryKeyStrategy::class]->last();
			
			// Identity if no strategy (default) or explicitly set to identity
			return !$strategyAnnotation || $strategyAnnotation->getValue() === 'identity';
		}
	}