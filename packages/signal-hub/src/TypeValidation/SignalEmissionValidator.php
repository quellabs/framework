<?php
	
	namespace Quellabs\SignalHub\TypeValidation;
	
	/**
	 * Signal emission type validator
	 */
	class SignalEmissionValidator {
		
		/**
		 * Validates arguments against expected parameter types for signal emission
		 * @param array $args Arguments to validate
		 * @param array $expectedTypes Expected parameter types
		 * @throws \Exception If argument types or count mismatch
		 */
		public static function validateEmission(array $args, array $expectedTypes): void {
			// Check argument count
			if (count($args) !== count($expectedTypes)) {
				throw new \Exception("Argument count mismatch for signal emission.");
			}
			
			// Check argument types
			foreach ($args as $index => $arg) {
				$expectedType = $expectedTypes[$index];
				$actualType = is_object($arg) ? get_class($arg) : gettype($arg);
				
				if (!TypeCompatibilityChecker::isCompatible($actualType, $expectedType)) {
					throw new \Exception("Type mismatch for argument {$index} of signal emission: expected {$expectedType}, got {$actualType}.");
				}
			}
		}
	}