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
		 * @return array Schema in format [0 => 'int', 1 => [...], 2 => 'string']
		 */
		public function generateSchemaFromTypes(array $parameterTypes): array {
			// Map each parameter type to its corresponding schema representation
			return array_map(function ($type) {
				return $this->generateTypeSchema($type);
			}, $parameterTypes);
		}
		
		/**
		 * Generate schema for a single type
		 * @param string $type The type name (e.g., 'int', 'Order', 'MyClass')
		 * @return string|array Returns string for primitives, array for classes
		 */
		private function generateTypeSchema(string $type): string|array {
			// Handle primitive types (int, string, bool, etc.)
			if ($this->isPrimitiveType($type)) {
				return $this->normalizeType($type);
			}
			
			// Handle class types using reflection
			if (class_exists($type)) {
				return $this->generateClassSchema($type);
			}
			
			// Fallback for unknown types - return 'mixed' as a safe default
			return 'mixed';
		}
		
		/**
		 * Uses PHP reflection to inspect a class and extract its data structure
		 * by examining public properties. This creates a schema that represents
		 * the actual data structure of the class when serialized.
		 * @param string $className Fully qualified class name
		 * @return array Schema array with property names as keys and types as values
		 */
		private function generateClassSchema(string $className): array {
			$schema = [];
			
			try {
				// Create reflection instance to inspect the class
				$reflection = new \ReflectionClass($className);
				
				// Get public properties and add them to schema
				// Only public properties are included as they represent the actual data structure
				// that would be serialized/transferred
				foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
					$fieldName = $property->getName();
					$schema[$fieldName] = $this->getPropertyType($property);
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