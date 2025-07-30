<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use ReflectionClass;
	use ReflectionProperty;
	
	/**
	 * Base renderer implementing common debugging functionality
	 * Provides default implementations that can be overridden by concrete renderers
	 */
	class BaseRenderer {
		
		/**
		 * Current nesting depth for recursive structures
		 * @var int
		 */
		protected static int $depth = 0;
		
		/**
		 * Maximum depth to prevent infinite recursion
		 * @var int
		 */
		protected static int $maxDepth = 10;
		
		/**
		 * Counter for generating unique IDs
		 * @var int
		 */
		protected static int $idCounter = 0;
		
		/**
		 * Track processed objects to detect circular references
		 * @var array
		 */
		protected static array $processedObjects = [];
		
		/**
		 * Configuration options
		 * @var array
		 */
		protected static array $config = [
			'maxDepth' => 10,
			'maxStringLength' => 1000,
			'maxArrayElements' => 100,
			'showPrivateProperties' => true,
			'showProtectedProperties' => true,
			'showMethods' => false,
			'showConstants' => false,
		];
		
		/**
		 * Type rendering strategies - to be implemented by concrete renderers
		 * @var array
		 */
		protected static array $typeRenderers = [];
		
		/**
		 * Main render method - can be implemented by concrete renderers
		 * @param array $vars Variables to render
		 */
		public static function render(array $vars): void {
			// Default implementation - just render each variable
			static::reset();
			static::initTypeRenderers();
			
			foreach ($vars as $var) {
				static::renderValue($var);
				echo "\n";
			}
		}
		
		/**
		 * Render a single value - template method pattern
		 * @param mixed $value The value to render
		 * @param string|null $key Optional key name
		 * @param array $context Additional context information
		 */
		protected static function renderValue($value, $key = null, array $context = []): void {
			// Pre-render hook
			static::beforeRenderValue($value, $key, $context);
			
			// Check for circular references
			if (is_object($value) && static::isCircularReference($value)) {
				static::renderCircularReference($value, $key);
				return;
			}
			
			// Check depth limits
			if (static::isMaxDepthReached()) {
				static::renderMaxDepthIndicator($key);
				return;
			}
			
			$type = gettype($value);
			
			// Get type-specific renderer
			$renderer = static::$typeRenderers[$type] ?? null;
			
			if ($renderer && is_callable($renderer)) {
				call_user_func($renderer, $value, $key, $context);
			} else {
				static::renderUnknownType($value, $key, $context);
			}
			
			// Post-render hook
			static::afterRenderValue($value, $key, $context);
		}
		
		/**
		 * Initialize type renderers - can be overridden by concrete classes
		 */
		protected static function initTypeRenderers(): void {
			if (!empty(static::$typeRenderers)) {
				return;
			}
			
			// Default type renderers that just echo the value
			static::$typeRenderers = [
				'string' => function($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . "\"{$value}\"";
				},
				'integer' => function($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . $value;
				},
				'double' => function($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . $value;
				},
				'boolean' => function($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . ($value ? 'true' : 'false');
				},
				'NULL' => function($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . 'null';
				},
				'array' => [static::class, 'renderArrayDefault'],
				'object' => [static::class, 'renderObjectDefault'],
				'resource' => function($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . 'resource(' . get_resource_type($value) . ')';
				},
			];
		}
		
		/**
		 * Check if we've reached maximum depth
		 * @return bool
		 */
		protected static function isMaxDepthReached(): bool {
			return static::$depth >= static::getConfig('maxDepth');
		}
		
		/**
		 * Increase rendering depth
		 */
		protected static function increaseDepth(): void {
			static::$depth++;
		}
		
		/**
		 * Decrease rendering depth
		 */
		protected static function decreaseDepth(): void {
			static::$depth = max(0, static::$depth - 1);
		}
		
		/**
		 * Get current indentation based on depth
		 * @return string
		 */
		protected static function getIndent(): string {
			return str_repeat('  ', static::$depth);
		}
		
		/**
		 * Generate next unique ID
		 * @return int
		 */
		protected static function getNextId(): int {
			return ++static::$idCounter;
		}
		
		/**
		 * Check for circular references in objects
		 * @param object $object
		 * @return bool
		 */
		protected static function isCircularReference(object $object): bool {
			$objectId = spl_object_id($object);
			
			if (in_array($objectId, static::$processedObjects)) {
				return true;
			}
			
			static::$processedObjects[] = $objectId;
			return false;
		}
		
		/**
		 * Remove object from circular reference tracking
		 * @param object $object
		 */
		protected static function removeFromCircularTracking(object $object): void {
			$objectId = spl_object_id($object);
			$key = array_search($objectId, static::$processedObjects);
			if ($key !== false) {
				unset(static::$processedObjects[$key]);
			}
		}
		
		/**
		 * Get object properties using reflection
		 * @param object $object
		 * @return ReflectionProperty[]
		 */
		protected static function getObjectProperties(object $object): array {
			$reflection = new ReflectionClass($object);
			$properties = $reflection->getProperties();
			
			return array_filter($properties, function(ReflectionProperty $property) {
				if ($property->isPrivate() && !static::getConfig('showPrivateProperties')) {
					return false;
				}
				if ($property->isProtected() && !static::getConfig('showProtectedProperties')) {
					return false;
				}
				return true;
			});
		}
		
		/**
		 * Get property value safely
		 * @param ReflectionProperty $property
		 * @param object $object
		 * @return mixed
		 */
		protected static function getPropertyValue(ReflectionProperty $property, object $object) {
			try {
				$property->setAccessible(true);
				
				if (!$property->isInitialized($object)) {
					return '*** uninitialized ***';
				}
				
				return $property->getValue($object);
			} catch (\Exception $e) {
				return '*** error: ' . $e->getMessage() . ' ***';
			}
		}
		
		/**
		 * Get visibility symbol for property
		 * @param ReflectionProperty $property
		 * @return string
		 */
		protected static function getVisibilitySymbol(ReflectionProperty $property): string {
			if ($property->isPrivate()) {
				return '-';
			} elseif ($property->isProtected()) {
				return '#';
			}
			return '+';
		}
		
		/**
		 * Truncate string if it exceeds max length
		 * @param string $string
		 * @return string
		 */
		protected static function truncateString(string $string): string {
			$maxLength = static::getConfig('maxStringLength');
			if (strlen($string) <= $maxLength) {
				return $string;
			}
			
			return substr($string, 0, $maxLength) . '... [truncated]';
		}
		
		/**
		 * Check if array should be truncated
		 * @param array $array
		 * @return bool
		 */
		protected static function shouldTruncateArray(array $array): bool {
			return count($array) > static::getConfig('maxArrayElements');
		}
		
		/**
		 * Get truncated array for display
		 * @param array $array
		 * @return array
		 */
		protected static function getTruncatedArray(array $array): array {
			$maxElements = static::getConfig('maxArrayElements');
			return array_slice($array, 0, $maxElements, true);
		}
		
		/**
		 * Get configuration value
		 * @param string $key
		 * @return mixed
		 */
		protected static function getConfig(string $key) {
			return static::$config[$key] ?? null;
		}
		
		/**
		 * Set configuration value
		 * @param string $key
		 * @param mixed $value
		 */
		public static function setConfig(string $key, $value): void {
			static::$config[$key] = $value;
		}
		
		/**
		 * Reset renderer state (useful for testing or multiple renders)
		 */
		public static function reset(): void {
			static::$depth = 0;
			static::$idCounter = 0;
			static::$processedObjects = [];
		}
		
		/**
		 * Default array renderer
		 * @param array $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderArrayDefault(array $value, $key = null, array $context = []): void {
			echo ($key ? "\"{$key}\" => " : '') . "array(" . count($value) . ") [\n";
			
			static::increaseDepth();
			foreach ($value as $arrayKey => $arrayValue) {
				echo static::getIndent();
				static::renderValue($arrayValue, $arrayKey);
				echo "\n";
			}
			static::decreaseDepth();
			
			echo static::getIndent() . "]";
		}
		
		/**
		 * Default object renderer
		 * @param object $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderObjectDefault(object $value, $key = null, array $context = []): void {
			$className = get_class($value);
			$objectId = spl_object_id($value);
			
			echo ($key ? "\"{$key}\" => " : '') . "{$className} #{$objectId} {\n";
			
			static::increaseDepth();
			$properties = static::getObjectProperties($value);
			
			foreach ($properties as $property) {
				$propertyValue = static::getPropertyValue($property, $value);
				$visibility = static::getVisibilitySymbol($property);
				
				echo static::getIndent() . $visibility . $property->getName() . ': ';
				static::renderValue($propertyValue);
				echo "\n";
			}
			static::decreaseDepth();
			
			echo static::getIndent() . "}";
			
			// Remove from circular reference tracking
			static::removeFromCircularTracking($value);
		}
		
		// Default implementations that concrete renderers can override
		
		/**
		 * Render circular reference indicator
		 * @param object $object
		 * @param string|null $key
		 */
		protected static function renderCircularReference(object $object, $key = null): void {
			$className = get_class($object);
			$objectId = spl_object_id($object);
			echo "*CIRCULAR REFERENCE* {$className} #{$objectId}";
		}
		
		/**
		 * Render max depth indicator
		 * @param string|null $key
		 */
		protected static function renderMaxDepthIndicator($key = null): void {
			echo "*MAX DEPTH REACHED*";
		}
		
		/**
		 * Render unknown type
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderUnknownType($value, $key = null, array $context = []): void {
			$type = gettype($value);
			echo "unknown({$type})";
		}
		
		// Hooks for extending behavior
		
		/**
		 * Called before rendering a value
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function beforeRenderValue($value, $key = null, array $context = []): void {
			// Override in concrete classes if needed
		}
		
		/**
		 * Called after rendering a value
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function afterRenderValue($value, $key = null, array $context = []): void {
			// Override in concrete classes if needed
		}
	}