<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use ReflectionClass;
	use ReflectionProperty;
	
	/**
	 * Base renderer implementing common debugging functionality
	 * Provides default implementations that can be overridden by concrete renderers
	 */
	class BaseRenderer implements RendererInterface {
		
		/**
		 * Current nesting depth for recursive structures
		 * @var int
		 */
		protected int $depth = 0;
		
		/**
		 * Maximum depth to prevent infinite recursion
		 * @var int
		 */
		protected int $maxDepth = 10;
		
		/**
		 * Counter for generating unique IDs
		 * @var int
		 */
		protected int $idCounter = 0;
		
		/**
		 * Track processed objects to detect circular references
		 * @var array
		 */
		protected array $processedObjects = [];
		
		/**
		 * Configuration options
		 * @var array
		 */
		protected array $config = [
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
		protected array $typeRenderers = [];
		
		/**
		 * Main render method - can be implemented by concrete renderers
		 * @param array $vars Variables to render
		 */
		public function render(array $vars): void {
			// Default implementation - just render each variable
			$this->initTypeRenderers();
			
			foreach ($vars as $var) {
				$this->renderValue($var);
				echo "\n";
			}
		}
		
		/**
		 * Render a single value - template method pattern
		 * @param mixed $value The value to render
		 * @param string|null $key Optional key name
		 * @param array $context Additional context information
		 */
		protected function renderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Pre-render hook
			$this->beforeRenderValue($value, $key, $context);
			
			// Check for circular references
			if (is_object($value) && $this->isCircularReference($value)) {
				$this->renderCircularReference($value, $key);
				return;
			}
			
			// Check depth limits
			if ($this->isMaxDepthReached()) {
				$this->renderMaxDepthIndicator($key);
				return;
			}
			
			$type = gettype($value);
			
			// Get type-specific renderer
			$renderer = $this->typeRenderers[$type] ?? null;
			
			if ($renderer && is_callable($renderer)) {
				call_user_func($renderer, $value, $key, $context);
			} else {
				$this->renderUnknownType($value, $key, $context);
			}
			
			// Post-render hook
			$this->afterRenderValue($value, $key, $context);
		}
		
		/**
		 * Initialize type renderers - can be overridden by concrete classes
		 */
		protected function initTypeRenderers(): void {
			if (!empty($this->typeRenderers)) {
				return;
			}
			
			// Default type renderers that just echo the value
			$this->typeRenderers = [
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
				'array' => [$this, 'renderArrayDefault'],
				'object' => [$this, 'renderObjectDefault'],
				'resource' => function($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . 'resource(' . get_resource_type($value) . ')';
				},
			];
		}
		
		/**
		 * Check if we've reached maximum depth
		 * @return bool
		 */
		protected function isMaxDepthReached(): bool {
			return $this->depth >= $this->getConfig('maxDepth');
		}
		
		/**
		 * Increase rendering depth
		 */
		protected function increaseDepth(): void {
			$this->depth++;
		}
		
		/**
		 * Decrease rendering depth
		 */
		protected function decreaseDepth(): void {
			$this->depth = max(0, $this->depth - 1);
		}
		
		/**
		 * Get current indentation based on depth
		 * @return string
		 */
		protected function getIndent(): string {
			return str_repeat('  ', $this->depth);
		}
		
		/**
		 * Generate next unique ID
		 * @return int
		 */
		protected function getNextId(): int {
			return ++$this->idCounter;
		}
		
		/**
		 * Check for circular references in objects
		 * @param object $object
		 * @return bool
		 */
		protected function isCircularReference(object $object): bool {
			$objectId = spl_object_id($object);
			
			if (in_array($objectId, $this->processedObjects)) {
				return true;
			}
			
			$this->processedObjects[] = $objectId;
			return false;
		}
		
		/**
		 * Remove object from circular reference tracking
		 * @param object $object
		 */
		protected function removeFromCircularTracking(object $object): void {
			$objectId = spl_object_id($object);
			$key = array_search($objectId, $this->processedObjects);
			if ($key !== false) {
				unset($this->processedObjects[$key]);
			}
		}
		
		/**
		 * Get object properties using reflection
		 * @param object $object
		 * @return ReflectionProperty[]
		 */
		protected function getObjectProperties(object $object): array {
			$reflection = new ReflectionClass($object);
			$properties = $reflection->getProperties();
			
			return array_filter($properties, function(ReflectionProperty $property) {
				if ($property->isPrivate() && !$this->getConfig('showPrivateProperties')) {
					return false;
				}
				if ($property->isProtected() && !$this->getConfig('showProtectedProperties')) {
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
		protected function getPropertyValue(ReflectionProperty $property, object $object): mixed {
			try {
				$property->setAccessible(true);
				
				if (!$property->isInitialized($object)) {
					return '*** uninitialized ***';
				}
				
				return $property->getValue($object);
			} catch (\Exception $e) {
				return '*** error: ' . htmlspecialchars($e->getMessage()) . ' ***';
			}
		}
		
		/**
		 * Get visibility symbol for property
		 * @param ReflectionProperty $property
		 * @return string
		 */
		protected function getVisibilitySymbol(ReflectionProperty $property): string {
			if ($property->isPrivate()) {
				return '-';
			} elseif ($property->isProtected()) {
				return '#';
			} else {
				return '+';
			}
		}
		
		/**
		 * Truncate string if it exceeds max length
		 * @param string $string
		 * @return string
		 */
		protected function truncateString(string $string): string {
			$maxLength = $this->getConfig('maxStringLength');
			
			if (strlen($string) <= $maxLength) {
				return $string;
			} else {
				return substr($string, 0, $maxLength) . '... [truncated]';
			}
		}
		
		/**
		 * Check if array should be truncated
		 * @param array $array
		 * @return bool
		 */
		protected function shouldTruncateArray(array $array): bool {
			return count($array) > $this->getConfig('maxArrayElements');
		}
		
		/**
		 * Get truncated array for display
		 * @param array $array
		 * @return array
		 */
		protected function getTruncatedArray(array $array): array {
			$maxElements = $this->getConfig('maxArrayElements');
			return array_slice($array, 0, $maxElements, true);
		}
		
		/**
		 * Get configuration value
		 * @param string $key
		 * @return mixed
		 */
		protected function getConfig(string $key): mixed {
			return $this->config[$key] ?? null;
		}
		
		/**
		 * Set configuration value
		 * @param string $key
		 * @param mixed $value
		 */
		public function setConfig(string $key, mixed $value): void {
			$this->config[$key] = $value;
		}
		
		/**
		 * Default array renderer
		 * @param array $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderArrayDefault(array $value, ?string $key = null, array $context = []): void {
			echo ($key ? "\"{$key}\" => " : '') . "array(" . count($value) . ") [\n";
			
			$this->increaseDepth();
			
			foreach ($value as $arrayKey => $arrayValue) {
				echo $this->getIndent();
				$this->renderValue($arrayValue, $arrayKey);
				echo "\n";
			}
			
			$this->decreaseDepth();
			
			echo $this->getIndent() . "]";
		}
		
		/**
		 * Default object renderer
		 * @param object $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderObjectDefault(object $value, $key = null, array $context = []): void {
			$className = get_class($value);
			$objectId = spl_object_id($value);
			
			echo ($key ? "\"{$key}\" => " : '') . "{$className} #{$objectId} {\n";
			
			$this->increaseDepth();
			
			$properties = $this->getObjectProperties($value);
			
			foreach ($properties as $property) {
				$propertyValue = $this->getPropertyValue($property, $value);
				$visibility = $this->getVisibilitySymbol($property);
				
				echo $this->getIndent() . $visibility . $property->getName() . ': ';
				$this->renderValue($propertyValue);
				echo "\n";
			}
			
			$this->decreaseDepth();
			echo $this->getIndent() . "}";
			
			// Remove from circular reference tracking
			$this->removeFromCircularTracking($value);
		}
		
		// Default implementations that concrete renderers can override
		
		/**
		 * Render circular reference indicator
		 * @param object $object
		 * @param string|null $key
		 */
		protected function renderCircularReference(object $object, string $key = null): void {
			$className = get_class($object);
			$objectId = spl_object_id($object);
			echo "*CIRCULAR REFERENCE* {$className} #{$objectId}";
		}
		
		/**
		 * Render max depth indicator
		 * @param string|null $key
		 */
		protected function renderMaxDepthIndicator(?string $key = null): void {
			echo "*MAX DEPTH REACHED*";
		}
		
		/**
		 * Render unknown type
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderUnknownType(mixed $value, ?string $key = null, array $context = []): void {
			echo "unknown(" . gettype($value) . ")";
		}
		
		// Hooks for extending behavior
		
		/**
		 * Called before rendering a value
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function beforeRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Override in concrete classes if needed
		}
		
		/**
		 * Called after rendering a value
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function afterRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Override in concrete classes if needed
		}
	}