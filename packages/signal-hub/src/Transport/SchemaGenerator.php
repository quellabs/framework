<?php
	
	namespace Quellabs\SignalHub\Transport;
	
	/**
	 * Auto-generates schema from parameter types
	 *
	 * This class uses PHP reflection to automatically generate schema definitions
	 * from class types and primitive types with recursive object handling.
	 */
	class SchemaGenerator {
		
		/**
		 * Track current processing path to detect circular references
		 * @var array
		 */
		private array $processingStack = [];
		
		/**
		 * Generate a schema array from signal parameter types
		 * @param array $parameterTypes Array of parameter types like [int::class, Order::class, string::class]
		 * @param bool $publicOnly Whether to only include public properties (default: true)
		 * @return array Schema in format [0 => 'int', 1 => [...], 2 => 'string']
		 * @throws \RuntimeException
		 */
		public function extract(array $parameterTypes, bool $publicOnly = true): array {
			// Reset processing stack for each new schema generation
			$this->processingStack = [];
			
			// Generate the schema array
			return array_map(function ($type) use ($publicOnly) {
				return $this->generateTypeSchema($type, $publicOnly);
			}, $parameterTypes);
		}
		
		/**
		 * Generate schema for a single type
		 * @param string $type The type name (e.g., 'int', 'Order', 'MyClass')
		 * @param bool $publicOnly Whether to only include public properties
		 * @return string|array Returns string for primitives, array for classes
		 * @throws \RuntimeException
		 */
		public function generateTypeSchema(string $type, bool $publicOnly): string|array {
			// Handle primitive types (int, string, bool, etc.)
			if ($this->isPrimitiveType($type)) {
				return $this->normalizeType($type);
			}
			
			// Handle class types using reflection
			if (class_exists($type)) {
				return $this->generateClassSchema($type, $publicOnly);
			}
			
			// Fallback for unknown types - return 'mixed' as a safe default
			return 'mixed';
		}
		
		/**
		 * Uses PHP reflection to inspect a class and extract its data structure
		 * by examining properties based on visibility settings. Now recursive!
		 * @param string $className Fully qualified class name
		 * @param bool $publicOnly Whether to only include public properties
		 * @return array Schema array with property names as keys and types as values
		 * @throws \RuntimeException
		 */
		private function generateClassSchema(string $className, bool $publicOnly): array {
			// Prevent infinite recursion by tracking processed classes
			if (in_array($className, $this->processingStack)) {
				throw new \RuntimeException("Circular reference detected for class: $className");
			}
			
			// Mark this class as being processed
			$this->processingStack[] = $className;
			
			// Create the schema for the class
			$schema = [];
			
			try {
				// Create reflection instance to inspect the class
				$reflection = new \ReflectionClass($className);
				
				// Determine which properties to include based on visibility setting
				$properties = $reflection->getProperties($publicOnly ? \ReflectionProperty::IS_PUBLIC : null);
				
				// Add properties to schema
				foreach ($properties as $property) {
					$fieldName = $property->getName();
					$propertyType = $this->getPropertyType($property);
					
					// Filter out parent class properties - only include properties declared in this class
					if ($property->getDeclaringClass()->getName() !== $className) {
						continue;
					}
					
					// Handle union types (e.g., "string|null", "int|string")
					if (str_contains($propertyType, '|')) {
						$schema[$fieldName] = $propertyType;
						continue;
					}
					
					// If the property type is primitive, store it directly
					if ($this->isPrimitiveType($propertyType)) {
						$schema[$fieldName] = $propertyType;
						continue;
					}

					// Handle class types
					$schema[$fieldName] = $this->generateClassSchema($propertyType, $publicOnly);
				}
			} catch (\ReflectionException $e) {
				// If reflection fails (class doesn't exist, etc.), return error indicator
				// This helps with debugging schema generation issues
				throw new \InvalidArgumentException("Can't fetch class data for {$className}: {$e->getMessage()}");
			} finally {
				array_pop($this->processingStack);
			}
			
			// Return the schema
			return $schema;
		}
		
		/**
		 * Check if a type is a primitive PHP type
		 * @param string $type The type name to check
		 * @return bool True if the type is a primitive type
		 */
		private function isPrimitiveType(string $type): bool {
			return in_array($type, ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'object', 'mixed', 'null']);
		}
		
		/**
		 * Get the property type using reflection
		 * @param \ReflectionProperty $property The property to examine
		 * @return string The property's type as a string
		 */
		private function getPropertyType(\ReflectionProperty $property): string {
			$type = $property->getType();
			
			// No type hint specified
			if ($type === null) {
				return 'mixed';
			}
			
			// Single named type (e.g., string, int, MyClass, ?string)
			if ($type instanceof \ReflectionNamedType) {
				$typeName = $type->getName();
				
				// Handle nullable types (?string becomes string|null)
				if ($type->allowsNull() && $typeName !== 'mixed') {
					return $typeName . '|null';
				}
				
				return $typeName;
			}
			
			// Union type (e.g., string|int|null)
			if ($type instanceof \ReflectionUnionType) {
				$types = array_map(fn($t) => $t->getName(), $type->getTypes());
				return implode('|', $types);
			}
			
			// Fallback for unknown type structures
			return 'mixed';
		}
		
		/**
		 * Converts type names to their canonical forms.
		 * @param string $typeName The type name to normalize
		 * @return string The normalized type name
		 */
		private function normalizeType(string $typeName): string {
			// Map alternative type names to canonical forms
			$typeMap = [
				'integer' => 'int',
				'boolean' => 'bool',
				'double'  => 'float'
			];
			
			// Return mapped type if it exists
			if (isset($typeMap[$typeName])) {
				return $typeMap[$typeName];
			}
			
			// Return original type name
			return $typeName;
		}
	}