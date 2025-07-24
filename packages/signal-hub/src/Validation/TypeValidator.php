<?php
	
	namespace Quellabs\SignalHub\Validation;
	
	/**
	 * Type compatibility checker
	 */
	class TypeValidator {
		/**
		 * @var array List of primitive types
		 */
		private static array $primitiveTypes = ['int', 'float', 'string', 'bool', 'array'];
		
		/**
		 * Checks if a type from a signal parameter is compatible with a slot parameter type
		 * @param string $signalType The type of the value being passed (from signal emission)
		 * @param string $slotType The type declaration of the receiving parameter
		 * @return bool True if types are compatible, false otherwise
		 */
		public static function isCompatible(string $signalType, string $slotType): bool {
			// Normalize type names to handle aliases
			$signalType = TypeNormalizer::normalize($signalType);
			$slotType = TypeNormalizer::normalize($slotType);
			
			// Handle the special case for generic 'object' type
			if (self::isObjectTypeCompatible ($signalType, $slotType)) {
				return true;
			}
			
			// If types are exactly the same, they're compatible
			if ($signalType === $slotType) {
				return true;
			}
			
			// Handle primitive types compatibility
			if (self::arePrimitivesIncompatible ($signalType, $slotType)) {
				return false;
			}
			
			// Check class inheritance for compatibility
			return self::hasInheritanceRelationship($signalType, $slotType);
		}
		
		/**
		 * Checks if either type is a generic 'object' type and the other is a class
		 * @param string $typeA First type to check
		 * @param string $typeB Second type to check
		 * @return bool True if one is 'object' and the other is a class
		 */
		private static function isObjectTypeCompatible(string $typeA, string $typeB): bool {
			// If typeB is generic 'object', check if typeA is a class
			if ($typeB === 'object' && self::isClassName($typeA)) {
				return true;
			}
			// If typeA is generic 'object', check if typeB is a class
			return $typeA === 'object' && self::isClassName($typeB);
		}
		
		/**
		 * Determines if a type string represents a class name
		 * @param string $type Type to check
		 * @return bool True if the type is likely a class name
		 */
		private static function isClassName(string $type): bool {
			return str_starts_with($type, '\\') || class_exists($type);
		}
		
		/**
		 * Checks if the types involve non-compatible primitive types
		 * @param string $typeA First type to check
		 * @param string $typeB Second type to check
		 * @return bool True if types are primitive and not compatible
		 */
		private static function arePrimitivesIncompatible(string $typeA, string $typeB): bool {
			$isTypeAPrimitive = in_array($typeA, self::$primitiveTypes);
			$isTypeBPrimitive = in_array($typeB, self::$primitiveTypes);
			
			// If one is primitive and the other isn't, they're not compatible
			if ($isTypeAPrimitive !== $isTypeBPrimitive) {
				return true;
			}
			// If both are primitives but different types, they're not compatible
			return $isTypeAPrimitive && $isTypeBPrimitive && $typeA !== $typeB;
		}
		
		/**
		 * Checks if two class types have an inheritance relationship
		 * @param string $classA First class name
		 * @param string $classB Second class name
		 * @return bool True if one class inherits from the other
		 */
		private static function hasInheritanceRelationship(string $classA, string $classB): bool {
			// Check if either class inherits from the other
			return is_subclass_of($classA, $classB) || is_subclass_of($classB, $classA);
		}
	}