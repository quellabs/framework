<?php
	
	namespace Quellabs\SignalHub\Transport;
	
	/**
	 * Validates data against schema definitions to ensure type safety
	 *
	 * This class validates that extracted data matches the expected schema structure
	 * and types, providing detailed error reporting for validation failures.
	 */
	class SchemaValidator {
		
		/**
		 * Track validation path for detailed error reporting
		 * @var array
		 */
		private array $validationPath = [];
		
		/**
		 * Validate data against schema
		 * @param array $data The data to validate (from SchemaDataExtractor)
		 * @param array $schema The schema to validate against (from SchemaGenerator)
		 * @return bool True if validation passes
		 * @throws \InvalidArgumentException If validation fails
		 */
		public function validate(array $data, array $schema): bool {
			// Reset validation path for each new validation
			$this->validationPath = [];
			
			// Validate each parameter in the schema
			foreach ($schema as $paramIndex => $paramSchema) {
				// Set current validation path
				$this->validationPath = [$paramIndex];
				
				// Check if data exists for this parameter
				if (!array_key_exists($paramIndex, $data)) {
					// Parameter is missing from data - this might be valid if it's optional
					continue;
				}
				
				// Validate parameter based on schema type
				if (is_array($paramSchema)) {
					// Parameter is an object - validate recursively
					$this->validateObject($data[$paramIndex], $paramSchema);
				} else {
					// Parameter is a primitive - validate type
					$this->validatePrimitive($data[$paramIndex], $paramSchema);
				}
			}
			
			return true;
		}
		
		/**
		 * Validate with detailed error reporting
		 * @param array $data The data to validate
		 * @param array $schema The schema to validate against
		 * @return array Array of validation errors (empty if valid)
		 */
		public function validateWithErrors(array $data, array $schema): array {
			$errors = [];
			
			try {
				$this->validate($data, $schema);
			} catch (\InvalidArgumentException $e) {
				$errors[] = $e->getMessage();
			}
			
			return $errors;
		}
		
		/**
		 * Validate a primitive value against its expected type
		 * @param mixed $value The value to validate
		 * @param string $expectedType The expected type from schema
		 * @throws \InvalidArgumentException If validation fails
		 */
		private function validatePrimitive(mixed $value, string $expectedType): void {
			// Fetch the type
			$actualType = $this->getActualType($value);
			
			// Handle special cases and type compatibility
			if (!$this->isTypeCompatible($actualType, $expectedType)) {
				$path = implode('.', $this->validationPath);
				
				throw new \InvalidArgumentException(
					"Type mismatch at {$path}: expected '{$expectedType}', got '{$actualType}'"
				);
			}
		}
		
		/**
		 * Validate an object/array against its schema
		 * @param mixed $data The data to validate
		 * @param array $schema The object schema
		 * @throws \InvalidArgumentException If validation fails
		 */
		private function validateObject(mixed $data, array $schema): void {
			// Check if data is an array (serialized object)
			if (!is_array($data)) {
				throw new \InvalidArgumentException(
					sprintf(
						"Expected object/array at %s, got '%s'",
						implode('.', $this->validationPath),
						$this->getActualType($data)
					)
				);
			}
			
			// Validate each property in the schema
			foreach ($schema as $propertyName => $propertySchema) {
				// Update validation path
				$currentPath = $this->validationPath;
				$this->validationPath[] = $propertyName;
				
				// Check if property exists in data
				if (!array_key_exists($propertyName, $data)) {
					throw new \InvalidArgumentException(
						sprintf(
							"Missing required property: %s",
							implode('.', $this->validationPath)
						)
					);
				}
				
				// Validate property based on its schema type
				if (is_array($propertySchema)) {
					// Nested object - validate recursively
					$this->validateObject($data[$propertyName], $propertySchema);
				} else {
					// Primitive property - validate type
					$this->validatePrimitive($data[$propertyName], $propertySchema);
				}
				
				// Restore the validation path
				$this->validationPath = $currentPath;
			}
		}
		
		/**
		 * Get the actual the type of the value for validation
		 * @param mixed $value The value to check
		 * @return string The type name
		 */
		private function getActualType(mixed $value): string {
			return match (true) {
				is_null($value) => 'null',
				is_bool($value) => 'bool',
				is_int($value) => 'int',
				is_float($value) => 'float',
				is_string($value) => 'string',
				is_array($value) => 'array',
				is_object($value) => 'object',
				default => 'unknown'
			};
		}
		
		/**
		 * Check if the actual type is compatible with the expected type
		 * @param string $actualType The actual type of the value
		 * @param string $expectedType The expected type from schema
		 * @return bool True if types are compatible
		 */
		private function isTypeCompatible(string $actualType, string $expectedType): bool {
			// First check: Exact match between actual and expected types
			// This is the most straightforward case - if types match exactly, they're compatible
			if ($actualType === $expectedType) {
				return true;
			}
			
			// Handle 'mixed' type - accepts anything
			// The 'mixed' type is a special case that accepts all types in PHP
			if ($expectedType === 'mixed') {
				return true;
			}
			
			// Handle union types (e.g., "string|int|null")
			// Union types allow multiple possible types separated by pipe characters
			if (str_contains($expectedType, '|')) {
				// Split the union type into individual allowed types
				$allowedTypes = explode('|', $expectedType);
				// Check if the actual type is one of the allowed types in the union
				return in_array($actualType, $allowedTypes);
			}
			
			// Handle type aliases - PHP has some legacy type names that map to modern ones
			// These aliases provide backward compatibility and alternative naming
			$typeAliases = [
				'integer' => 'int',     // 'integer' is an alias for 'int'
				'boolean' => 'bool',    // 'boolean' is an alias for 'bool'
				'double' => 'float'     // 'double' is an alias for 'float'
			];
			
			// Normalize both types using the aliases map
			// If a type isn't in the aliases map, it stays the same (null coalescing)
			$normalizedExpected = $typeAliases[$expectedType] ?? $expectedType;
			$normalizedActual = $typeAliases[$actualType] ?? $actualType;
			
			// Check if the normalized types match
			if ($normalizedActual === $normalizedExpected) {
				return true;
			}
			
			// Handle null values for nullable types
			// If the actual value is null and the expected type allows null somewhere,
			// then it's compatible (covers cases like "string|null" or standalone "null")
			if ($actualType === 'null' && str_contains($expectedType, 'null')) {
				return true;
			}
			
			// If none of the above conditions are met, the types are incompatible
			return false;
		}
		
		/**
		 * Check if data is valid against schema (non-throwing version)
		 * @param array $data The data to validate
		 * @param array $schema The schema to validate against
		 * @return bool True if valid, false otherwise
		 */
		public function isValid(array $data, array $schema): bool {
			try {
				return $this->validate($data, $schema);
			} catch (\InvalidArgumentException $e) {
				return false;
			}
		}
	}