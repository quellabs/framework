<?php
	
	namespace Quellabs\SignalHub\Validation;
	
	/**
	 * Connection type validator for signal-slot connections
	 */
	class ConnectionValidator {
		
		/**
		 * Validates that a callable's parameters are compatible with signal parameters
		 * The signal will emit certain types, and the slot must be able to receive them.
		 * Slot can have fewer parameters (ignores extras) or optional parameters.
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
			self::validateParameterCompatibility($signalParameterTypes, $slotParams, "{$className}::{$method}");
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
			self::validateParameterCompatibility($signalParameterTypes, $slotParams, $receiver);
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
			self::validateParameterCompatibility($signalParameterTypes, $slotParams, 'Closure');
		}
		
		/**
		 * Validates parameter compatibility between signal emissions and slot expectations
		 *
		 * Rules:
		 * 1. Slot can have fewer required parameters than signal provides (ignores extras)
		 * 2. Slot cannot have more required parameters than signal provides
		 * 3. Each slot parameter must accept the corresponding signal type
		 * 4. Untyped slot parameters accept anything
		 *
		 * @param array $signalParameterTypes Signal parameter types (what will be emitted)
		 * @param array $slotParams Slot reflection parameters (what slot expects)
		 * @param string $slotName Name of the slot for error messages
		 * @throws \Exception If parameters are incompatible
		 */
		private static function validateParameterCompatibility(
			array $signalParameterTypes,
			array $slotParams,
			string $slotName
		): void {
			// Count required parameters in slot (non-optional, non-variadic)
			$requiredSlotParams = array_filter($slotParams, fn($p) => !$p->isOptional() && !$p->isVariadic());
			$requiredCount = count($requiredSlotParams);
			
			// Slot cannot require more parameters than signal provides
			if ($requiredCount > count($signalParameterTypes)) {
				throw new \Exception(
					"Slot '{$slotName}' requires {$requiredCount} parameters, " .
					"but signal only provides " . count($signalParameterTypes)
				);
			}
			
			// Validate each parameter that the signal will provide
			foreach ($signalParameterTypes as $i => $signalType) {
				// If slot doesn't have this parameter, it's OK (slot ignores it)
				if (!isset($slotParams[$i])) {
					continue;
				}
				
				$slotParam = $slotParams[$i];
				$slotType = $slotParam->getType();
				
				// Untyped parameters accept anything
				if ($slotType === null) {
					continue;
				}
				
				// Get type name (handle union types by using __toString)
				$slotTypeName = (string)$slotType;
				
				// Signal provides $signalType, slot expects $slotTypeName
				// Check if what signal provides can be accepted by slot
				if (!TypeValidator::isCompatible($signalType, $slotTypeName)) {
					throw new \Exception(
						"Type mismatch for parameter #{$i} in slot '{$slotName}': " .
						"signal provides '{$signalType}', but slot expects '{$slotTypeName}'"
					);
				}
			}
		}
	}