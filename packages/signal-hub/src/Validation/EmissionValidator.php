<?php
	
	namespace Quellabs\SignalHub\Validation;
	
	/**
	 * Signal emission type validator
	 */
	class EmissionValidator {
		
		/**
		 * Validates that emission arguments match the signal's declared types
		 * @param array $args Arguments being emitted
		 * @param array $expectedTypes Types declared for this signal
		 * @throws \Exception If argument types or count mismatch
		 */
		public static function validateEmission(array $args, array $expectedTypes): void {
			// Must provide exactly the declared number of arguments
			if (count($args) !== count($expectedTypes)) {
				throw new \Exception(
					"Argument count mismatch: signal expects " . count($expectedTypes) .
					" arguments, got " . count($args)
				);
			}
			
			// Validate each argument type
			foreach ($args as $index => $arg) {
				$expectedType = $expectedTypes[$index];
				$actualType = self::getActualType($arg);
				
				// Check if actual type can satisfy expected type
				if (!TypeValidator::isCompatible($actualType, $expectedType)) {
					throw new \Exception(
						"Type mismatch for argument #{$index}: " .
						"expected '{$expectedType}', got '{$actualType}'"
					);
				}
			}
		}
		
		/**
		 * Get the type name of a value in a format compatible with type strings
		 * @param mixed $value The value to get the type of
		 * @return string The type name normalized to match type hint format
		 */
		private static function getActualType(mixed $value): string {
			if (is_object($value)) {
				return get_class($value);
			}
			
			// Map PHP's gettype() to type hint names
			return match(gettype($value)) {
				'integer' => 'int',
				'double' => 'float',
				'boolean' => 'bool',
				'NULL' => 'null',
				default => gettype($value),
			};
		}
	}