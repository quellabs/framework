<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	/**
	 * Base renderer class with common functionality
	 */
	abstract class BaseRenderer {
		
		/**
		 * Current nesting depth for indentation
		 * @var int
		 */
		protected static int $depth = 0;
		
		/**
		 * Maximum depth to prevent infinite recursion
		 * @var int
		 */
		protected static int $maxDepth = 10;
		
		/**
		 * Counter for generating unique IDs for collapsible elements
		 * @var int
		 */
		protected static int $uniqueId = 0;
		
		/**
		 * Render multiple variables
		 * @param array $vars Variables to render
		 */
		abstract public static function render(array $vars): void;
		
		/**
		 * Render a single value
		 * @param mixed $value The value to render
		 * @param string|null $key Optional key name
		 */
		abstract protected static function renderValue($value, $key = null);
		
		/**
		 * Render an array
		 * @param array $array The array to render
		 */
		abstract protected static function renderArray(array $array);
		
		/**
		 * Render an object
		 * @param object $object The object to render
		 */
		abstract protected static function renderObject(object $object): void;
		
		/**
		 * Get the next unique ID
		 * @return int
		 */
		protected static function getNextId(): int {
			return ++self::$uniqueId;
		}
		
		/**
		 * Get current indentation string
		 * @return string
		 */
		protected static function getIndent(): string {
			return str_repeat('  ', self::$depth);
		}
		
		/**
		 * Check if maximum depth is reached
		 * @return bool
		 */
		protected static function isMaxDepthReached(): bool {
			return self::$depth >= self::$maxDepth;
		}
		
		/**
		 * Increase depth level
		 */
		protected static function increaseDepth(): void {
			self::$depth++;
		}
		
		/**
		 * Decrease depth level
		 */
		protected static function decreaseDepth(): void {
			self::$depth--;
		}
		
		/**
		 * Get object properties using reflection
		 * @param object $object The object to inspect
		 * @return \ReflectionProperty[]
		 */
		protected static function getObjectProperties(object $object): array {
			$reflection = new \ReflectionClass($object);
			return $reflection->getProperties();
		}
		
		/**
		 * Safely get property value
		 * @param \ReflectionProperty $property The property to get value from
		 * @param object $object The object instance
		 * @return mixed
		 */
		protected static function getPropertyValue(\ReflectionProperty $property, object $object) {
			$property->setAccessible(true);
			
			try {
				return $property->getValue($object);
			} catch (\Exception $e) {
				return '*** uninitialized ***';
			}
		}
		
		/**
		 * Get property visibility symbol
		 * @param \ReflectionProperty $property The property to check
		 * @return string
		 */
		protected static function getVisibilitySymbol(\ReflectionProperty $property): string {
			return $property->isPublic() ? '+' : ($property->isProtected() ? '#' : '-');
		}
	}