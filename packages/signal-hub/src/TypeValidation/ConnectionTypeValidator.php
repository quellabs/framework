<?php
	
	namespace Quellabs\SignalHub\TypeValidation;
	
	/**
	 * Connection type validator for signal-slot connections
	 */
	class ConnectionTypeValidator {
		/**
		 * Validates that a callable's parameters are compatible with signal parameters
		 * @param callable $receiver The callable to validate
		 * @param array $signalParameterTypes Expected signal parameter types
		 * @throws \Exception If types mismatch or parameter count differs
		 */
		public static function validateCallableConnection(callable $receiver, array $signalParameterTypes): void {
			// Create a reflection of the callable to inspect its parameters
			$slotReflection = new \ReflectionFunction($receiver);
			$slotParams = $slotReflection->getParameters();
			
			self::validateParameterCompatibility($signalParameterTypes, $slotParams);
		}
		
		/**
		 * Validates that an object method's parameters are compatible with signal parameters
		 * @param object $receiver The object containing the method
		 * @param string $slot The method name to validate
		 * @param array $signalParameterTypes Expected signal parameter types
		 * @throws \Exception If types mismatch, parameter count differs, or method doesn't exist
		 */
		public static function validateObjectMethodConnection(object $receiver, string $slot, array $signalParameterTypes): void {
			// Check if slot method exists on receiver
			if (!method_exists($receiver, $slot)) {
				throw new \Exception("Slot '{$slot}' does not exist on receiver.");
			}
			
			// Get reflection of slot method
			$slotReflection = new \ReflectionMethod($receiver, $slot);
			$slotParams = $slotReflection->getParameters();
			
			self::validateParameterCompatibility($signalParameterTypes, $slotParams);
		}
		
		/**
		 * Validates parameter compatibility between signal and slot parameters
		 * @param array $signalParameterTypes Signal parameter types
		 * @param array $slotParams Slot reflection parameters
		 * @throws \Exception If types mismatch or parameter count differs
		 */
		private static function validateParameterCompatibility(array $signalParameterTypes, array $slotParams): void {
			// Check parameter count
			if (count($signalParameterTypes) !== count($slotParams)) {
				throw new \Exception("Signal and slot parameter count mismatch.");
			}
			
			// Check type compatibility for each parameter
			for ($i = 0; $i < count($signalParameterTypes); $i++) {
				$signalType = $signalParameterTypes[$i];
				$slotType = $slotParams[$i]->getType();
				
				if ($slotType === null) {
					throw new \Exception("Slot parameter {$i} is not typed.");
				}
				
				// Fetch the name of the slot by casting to string.
				// This will call the __toString magic method.
				// We can't use ->getName() because it may not be present
				$slotTypeName = (string)$slotType;
				
				// Check type compatibility
				if (!TypeCompatibilityChecker::isCompatible($signalType, $slotTypeName)) {
					throw new \Exception("Type mismatch for parameter {$i} between signal ({$signalType}) and slot ({$slotTypeName}).");
				}
			}
		}
	}