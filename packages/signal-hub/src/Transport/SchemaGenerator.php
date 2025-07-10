<?php
	
	namespace Quellabs\SignalHub\Transport;
	
	/**
	 * Auto-generates schema from parameter types
	 *
	 * This class uses PHP reflection to automatically generate schema definitions
	 * from class types and primitive types.
	 */
	class SchemaGenerator {
		
		/**
		 * Generate a schema array from signal parameter types
		 * @param array $parameterTypes Array of parameter types like [int::class, Order::class, string::class]
		 * @param bool $publicOnly Whether to only include public properties (default: true)
		 * @return array Schema in format [0 => 'int', 1 => [...], 2 => 'string']
		 */
		public function extract(array $parameterTypes, bool $publicOnly = true): array {
			// Map each parameter type to its corresponding schema representation
			return array_map(function ($type) use ($publicOnly) {
				return $this->generateTypeSchema($type, $publicOnly);
			}, $parameterTypes);
		}
		
		/**
		 * Generate schema for a single type
		 * @param string $type The type name (e.g., 'int', 'Order', 'MyClass')
		 * @param bool $publicOnly Whether to only include public properties
		 * @return string|array Returns string for primitives, array for classes
		 */
		private function generateTypeSchema(string $type, bool $publicOnly): string|array {
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
		 * by examining properties based on visibility settings.
		 * @param string $className Fully qualified class name
		 * @param bool $publicOnly Whether to only include public properties
		 * @return array Schema array with property names as keys and types as values
		 */
		private function generateClassSchema(string $className, bool $publicOnly): array {
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
					
					// If the property type is a class, recursively generate its schema
					if ($this->isClassType($propertyType)) {
						$schema[$fieldName] = $this->generateClassSchema($propertyType, $publicOnly);
					} else {
						$schema[$fieldName] = $propertyType;
					}
				}
			} catch (\ReflectionException $e) {
				// If reflection fails (class doesn't exist, etc.), return error indicator
				// This helps with debugging schema generation issues
				$schema['_error'] = 'reflection_failed';
			}
			
			return $schema;
		}
		
		/**
		 * Check if a type is a primitive PHP type
		 * @param string $type The type name to check
		 * @return bool True if the type is a primitive type
		 */
		private function isPrimitiveType(string $type): bool {
			return in_array($type, ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'object', 'mixed']);
		}
		
		/**
		 * Check if a type represents a class that should be recursively processed
		 * @param string $type The type name to check
		 * @return bool True if the type is a class type
		 */
		private function isClassType(string $type): bool {
			// Skip union types for now (could be enhanced later)
			if (str_contains($type, '|')) {
				return false;
			}
			
			// Check if it's a class and not a primitive type
			return class_exists($type) && !$this->isPrimitiveType($type);
		}
		
		/**
		 * Get the type of a property using reflection
		 * @param \ReflectionProperty $property The property to examine
		 * @return string The property's type as a string
		 */
		private function getPropertyType(\ReflectionProperty $property): string {
			$type = $property->getType();
			
			// No type hint specified
			if ($type === null) {
				return 'mixed';
			}
			
			// Single named type (e.g., string, int, MyClass)
			if ($type instanceof \ReflectionNamedType) {
				return $this->normalizeType($type->getName());
			}
			
			// Union type (e.g., string|int|null)
			if ($type instanceof \ReflectionUnionType) {
				$types = array_map(fn($t) => $this->normalizeType($t->getName()), $type->getTypes());
				return implode('|', $types);
			}
			
			// Fallback for unknown type structures
			return 'mixed';
		}
		
		/**
		 * Converts type names to their canonical forms and handles class types.
		 * For class types, returns 'object' to maintain schema simplicity,
		 * though you could return the full class name if needed.
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
			
			// For class types, return 'object' for schema simplicity
			// Alternative: return $typeName if you want the full class name in schema
			if (class_exists($typeName)) {
				return 'object';
			}
			
			// Return original type name for everything else
			return $typeName;
		}
	}