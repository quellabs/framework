<?php
	
	namespace Quellabs\SignalHub\Validation;
	
	/**
	 * Type compatibility checker
	 */
	class TypeValidator {
		
		/**
		 * @var array List of primitive and special types
		 */
		private static array $primitiveTypes = ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'null'];
		
		/**
		 * Checks if a value of $providedType can be accepted by a parameter of $expectedType
		 *
		 * Think: "Can I pass a $providedType value to a parameter expecting $expectedType?"
		 *
		 * Examples:
		 * - isCompatible('Dog', 'Animal') -> true (Dog can be passed to Animal parameter)
		 * - isCompatible('Animal', 'Dog') -> false (Animal cannot be passed to Dog parameter)
		 * - isCompatible('int', 'mixed') -> true (int can be passed to mixed parameter)
		 * - isCompatible('object', 'Dog') -> true (generic object accepted as Dog - runtime check)
		 *
		 * @param string $providedType The type being provided (what we have)
		 * @param string $expectedType The type being expected (what parameter accepts)
		 * @return bool True if provided type can satisfy expected type
		 */
		public static function isCompatible(string $providedType, string $expectedType): bool {
			// Normalize type names to handle aliases
			$providedType = TypeNormalizer::normalize($providedType);
			$expectedType = TypeNormalizer::normalize($expectedType);
			
			// Exact match is always compatible
			if ($providedType === $expectedType) {
				return true;
			}
			
			// mixed accepts anything
			if ($expectedType === 'mixed') {
				return true;
			}
			
			// object type accepts any class instance
			if ($expectedType === 'object' && self::isClassName($providedType)) {
				return true;
			}
			
			// Generic object provided can be cast to specific class (runtime check required)
			// This allows signals to emit generic 'object' to slots expecting specific classes
			if ($providedType === 'object' && self::isClassName($expectedType)) {
				return true;
			}
			
			// Handle primitive type incompatibility
			if (self::arePrimitivesIncompatible($providedType, $expectedType)) {
				return false;
			}
			
			// Both are class names - check inheritance (contravariant)
			// Provided type must be same class or subclass of expected type
			if (self::isClassName($providedType) && self::isClassName($expectedType)) {
				return is_subclass_of($providedType, $expectedType);
			}
			
			return false;
		}
		
		/**
		 * Determines if a type string represents a class name
		 * @param string $type Type to check
		 * @return bool True if the type is likely a class name
		 */
		private static function isClassName(string $type): bool {
			// Not a primitive type and either starts with backslash or class exists
			return
				!in_array($type, self::$primitiveTypes, true) &&
				(str_starts_with($type, '\\') || class_exists($type));
		}
		
		/**
		 * Checks if the types involve non-compatible primitive types
		 * @param string $providedType The type being provided
		 * @param string $expectedType The type being expected
		 * @return bool True if types are primitive and not compatible
		 */
		private static function arePrimitivesIncompatible(string $providedType, string $expectedType): bool {
			$isProvidedPrimitive = in_array($providedType, self::$primitiveTypes, true);
			$isExpectedPrimitive = in_array($expectedType, self::$primitiveTypes, true);
			
			// If one is primitive and the other isn't, they're incompatible
			// Exception: object and mixed can accept class instances
			if ($isProvidedPrimitive !== $isExpectedPrimitive) {
				return !in_array($expectedType, ['object', 'mixed'], true);
			}
			
			// Both are primitives - check special compatibility rules
			if ($isProvidedPrimitive && $isExpectedPrimitive) {
				// iterable accepts array
				if ($expectedType === 'iterable' && $providedType === 'array') {
					return false;
				}
				
				// Different primitive types are incompatible
				return true;
			}
			
			return false;
		}
	}