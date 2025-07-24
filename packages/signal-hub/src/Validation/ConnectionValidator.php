<?php
	
	namespace Quellabs\SignalHub\Validation;
	
	/**
	 * Connection type validator for signal-slot connections
	 */
	class ConnectionValidator {
		
		/**
		 * Validates that a callable's parameters are compatible with signal parameters
		 * @param callable|array $receiver The callable to validate
		 * @param array $signalParameterTypes Expected signal parameter types
		 * @throws \Exception If types mismatch or parameter count differs
		 */
		public static function validateCallableConnection(callable|array $receiver, array $signalParameterTypes): void {
			if (is_array($receiver)) {
				self::validateArrayCallable($receiver, $signalParameterTypes);
			} elseif (is_string($receiver)) {
				self::validateStringCallable($receiver, $signalParameterTypes);
			} else {
				self::validateClosureCallable($receiver, $signalParameterTypes);
			}
		}
		
		/**
		 * Validates array callable ([$object, 'method'] or ['ClassName', 'staticMethod'])
		 * @param array $receiver The array callable to validate
		 * @param array $signalParameterTypes Expected signal parameter types
		 * @throws \Exception If types mismatch or parameter count differs
		 */
		private static function validateArrayCallable(array $receiver, array $signalParameterTypes): void {
			// Destructure the array callable into object/class and method name
			// $receiver[0] can be either an object instance or a class name string
			// $receiver[1] is always the method name string
			[$objectOrClass, $method] = $receiver;
			
			// Get the actual class name regardless of whether we have an object or class string
			// If it's an object, get its class name; if it's already a string, use it as-is
			$className = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;
			
			// Create a reflection object for the method to inspect its properties
			// This works for both instance methods (object) and static methods (class string)
			$slotReflection = new \ReflectionMethod($objectOrClass, $method);
			
			// Get all parameters of the target method for validation
			$slotParams = $slotReflection->getParameters();
			
			// Ensure the method is publicly accessible
			// Private and protected methods cannot be called from external contexts
			if ($slotReflection->isPrivate() || $slotReflection->isProtected()) {
				throw new \Exception("Cannot connect signal: Method {$className}::{$method}() must be public");
			}
			
			// Validate that the signal parameters are compatible with the slot method parameters
			// This checks parameter count, types, and other compatibility requirements
			self::validateParameterCompatibility($signalParameterTypes, $slotParams);
		}
		
		/**
		 * Validates string callable ('function_name' or 'ClassName::staticMethod')
		 * @param string $receiver The string callable to validate
		 * @param array $signalParameterTypes Expected signal parameter types
		 * @throws \Exception If types mismatch or parameter count differs
		 */
		private static function validateStringCallable(string $receiver, array $signalParameterTypes): void {
			// String callable: 'function_name' or 'ClassName::staticMethod'
			if (str_contains($receiver, '::')) {
				$slotReflection = new \ReflectionMethod($receiver);
			} else {
				$slotReflection = new \ReflectionFunction($receiver);
			}
			
			$slotParams = $slotReflection->getParameters();
			self::validateParameterCompatibility($signalParameterTypes, $slotParams);
		}
		
		/**
		 * Validates closure or invokable object callable
		 * @param callable $receiver The closure/invokable object to validate
		 * @param array $signalParameterTypes Expected signal parameter types
		 * @throws \Exception If types mismatch or parameter count differs
		 */
		private static function validateClosureCallable(callable $receiver, array $signalParameterTypes): void {
			$slotReflection = new \ReflectionFunction($receiver);
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
			$counter = count($signalParameterTypes);
			
			// Check type compatibility for each parameter
			for ($i = 0; $i < $counter; $i++) {
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
				if (!TypeValidator::isCompatible($signalType, $slotTypeName)) {
					throw new \Exception("Type mismatch for parameter {$i} between signal ({$signalType}) and slot ({$slotTypeName}).");
				}
			}
		}
	}