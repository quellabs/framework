<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\DependencyInjection\Container;
	use ReflectionClass;
	use ReflectionProperty;
	
	/**
	 * This abstract base class provides a foundation for creating custom debug output renderers.
	 * It implements the Template Method pattern, allowing concrete renderers to override specific
	 * behaviors while maintaining consistent core functionality.
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
		 * Call location information
		 * @var array|null
		 */
		protected ?array $callLocation = null;
		
		/**
		 * Configuration options
		 *
		 * Controls various aspects of the rendering behavior:
		 * - maxDepth: Maximum nesting depth before stopping
		 * - maxStringLength: Truncate strings longer than this
		 * - maxArrayElements: Show only first N array elements
		 * - showPrivateProperties: Whether to include private object properties
		 * - showProtectedProperties: Whether to include protected object properties
		 * - showMethods: Whether to display object methods (not implemented in base)
		 * - showConstants: Whether to display class constants (not implemented in base)
		 *
		 * @var array
		 */
		protected array $config = [
			'maxDepth'                => 10,
			'maxStringLength'         => 1000,
			'maxArrayElements'        => 100,
			'showPrivateProperties'   => true,
			'showProtectedProperties' => true,
			'showMethods'             => false,
			'showConstants'           => false,
		];
		
		/**
		 * Type rendering strategies - to be implemented by concrete renderers
		 * @var array
		 */
		protected array $typeRenderers = [];
		
		/**
		 * Main render method - entry point for rendering variables
		 * @param array $vars Variables to render - can be any PHP data types
		 */
		public function render(array $vars): void {
			// Initialize the type-specific renderers
			$this->initTypeRenderers();
			
			// Process each variable individually
			foreach ($vars as $var) {
				$this->renderValue($var);
				echo "\n"; // Separate each variable with a newline
			}
		}
		
		/**
		 * Set the call location (called from CanvasDebugger)
		 * @param array $callLocation Call location info
		 */
		public function setCallLocation(array $callLocation): void {
			$this->callLocation = $callLocation;
		}
		
		/**
		 * Get call location info
		 * @return array Call location details
		 */
		protected function getCallLocation(): array {
			return $this->callLocation ?? [
				'file'     => 'unknown',
				'line'     => 0,
				'function' => 'unknown',
				'class'    => null,
				'type'     => null
			];
		}
		
		/**
		 * Render a single value - core of the Template Method pattern
		 * @param mixed $value The value to render (any PHP type)
		 * @param string|null $key Optional key name (for array elements, object properties)
		 * @param array $context Additional context information for specialized rendering
		 */
		protected function renderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Allow subclasses to perform setup before rendering
			$this->beforeRenderValue($value, $key, $context);
			
			// For objects, check if we've already started processing this object
			// to prevent infinite loops in circular references
			if (is_object($value) && $this->isCircularReference($value)) {
				$this->renderCircularReference($value, $key);
				return;
			}
			
			// Prevent infinite recursion by limiting nesting depth
			if ($this->isMaxDepthReached()) {
				$this->renderMaxDepthIndicator($key);
				return;
			}
			
			// Get the PHP type name for this value
			$type = gettype($value);
			
			// Look up the appropriate renderer for this type
			$renderer = $this->typeRenderers[$type] ?? null;
			
			// If we have a renderer for this type, use it
			if ($renderer && is_callable($renderer)) {
				call_user_func($renderer, $value, $key, $context);
			} else {
				// Fallback for unknown or unhandled types
				$this->renderUnknownType($value, $key, $context);
			}
			
			// Allow subclasses to perform cleanup after rendering
			$this->afterRenderValue($value, $key, $context);
		}
		
		/**
		 * Initialize type renderers - Strategy pattern implementation
		 * @return void
		 */
		protected function initTypeRenderers(): void {
			// Skip initialization if already done
			if (!empty($this->typeRenderers)) {
				return;
			}
			
			// Set up default renderers for all PHP primitive types
			$this->typeRenderers = [
				// String values: wrap in quotes and show key if present
				'string'   => function ($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . "\"{$value}\"";
				},
				
				// Integer values: display as-is
				'integer'  => function ($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . $value;
				},
				
				// Float/double values: display as-is
				'double'   => function ($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . $value;
				},
				
				// Boolean values: convert to 'true'/'false' strings
				'boolean'  => function ($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . ($value ? 'true' : 'false');
				},
				
				// NULL values: display as 'null'
				'NULL'     => function ($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . 'null';
				},
				
				// Arrays: delegate to specialized array renderer
				'array'    => [$this, 'renderArrayDefault'],
				
				// Objects: delegate to specialized object renderer
				'object'   => [$this, 'renderObjectDefault'],
				
				// Resources: show type information
				'resource' => function ($value, $key = null, $context = []) {
					echo ($key ? "\"{$key}\" => " : '') . 'resource(' . get_resource_type($value) . ')';
				},
			];
		}
		
		/**
		 * Check if we've reached maximum depth
		 * @return bool True if maximum depth has been reached
		 */
		protected function isMaxDepthReached(): bool {
			return $this->depth >= $this->getConfig('maxDepth');
		}
		
		/**
		 * Increase rendering depth
		 * @return void
		 */
		protected function increaseDepth(): void {
			$this->depth++;
		}
		
		/**
		 * Decrease rendering depth
		 * @return void
		 */
		protected function decreaseDepth(): void {
			$this->depth = max(0, $this->depth - 1);
		}
		
		/**
		 * Get current indentation based on depth
		 * @return string Indentation string (spaces)
		 */
		protected function getIndent(): string {
			return str_repeat('  ', $this->depth);
		}
		
		/**
		 * Generate next unique ID
		 * @return int Next unique ID number
		 */
		protected function getNextId(): int {
			return ++$this->idCounter;
		}
		
		/**
		 * Check for circular references in objects
		 * @param object $object The object to check
		 * @return bool True if this object is already being processed (circular reference)
		 */
		protected function isCircularReference(object $object): bool {
			$objectId = spl_object_id($object);
			
			// If we've seen this object ID before, it's a circular reference
			if (in_array($objectId, $this->processedObjects)) {
				return true;
			}
			
			// Add this object to our tracking list
			$this->processedObjects[] = $objectId;
			return false;
		}
		
		/**
		 * Remove object from circular reference tracking
		 * @param object $object The object to stop tracking
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
		 * @param object $object The object to inspect
		 * @return ReflectionProperty[] Array of properties to display
		 */
		protected function getObjectProperties(object $object): array {
			$reflection = new ReflectionClass($object);
			$properties = $reflection->getProperties();
			
			// Filter properties based on configuration settings
			return array_filter($properties, function (ReflectionProperty $property) {
				// Skip private properties if configured to do so
				if ($property->isPrivate() && !$this->getConfig('showPrivateProperties')) {
					return false;
				}
				// Skip protected properties if configured to do so
				if ($property->isProtected() && !$this->getConfig('showProtectedProperties')) {
					return false;
				}
				return true;
			});
		}
		
		/**
		 * Get property value safely
		 * @param ReflectionProperty $property The property to read
		 * @param object $object The object instance
		 * @return mixed The property value or an error indicator
		 */
		protected function getPropertyValue(ReflectionProperty $property, object $object): mixed {
			try {
				// Make private/protected properties accessible
				$property->setAccessible(true);
				
				// Check if the property has been initialized (PHP 7.4+ feature)
				if (!$property->isInitialized($object)) {
					return '*** uninitialized ***';
				}
				
				return $property->getValue($object);
			} catch (\Exception $e) {
				// If anything goes wrong, return an error message
				// htmlspecialchars prevents potential XSS if this is displayed in HTML
				return '*** error: ' . htmlspecialchars($e->getMessage()) . ' ***';
			}
		}
		
		/**
		 * Get visibility symbol for property
		 * @param ReflectionProperty $property The property to check
		 * @return string Single character visibility symbol
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
		 * @param string $string The string to potentially truncate
		 * @return string The original string or a truncated version
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
		 * @param array $array The array to check
		 * @return bool True if the array should be truncated
		 */
		protected function shouldTruncateArray(array $array): bool {
			return count($array) > $this->getConfig('maxArrayElements');
		}
		
		/**
		 * Get truncated array for display
		 * @param array $array The array to truncate
		 * @return array First N elements of the original array
		 */
		protected function getTruncatedArray(array $array): array {
			$maxElements = $this->getConfig('maxArrayElements');
			return array_slice($array, 0, $maxElements, true);
		}
		
		/**
		 * Get configuration value
		 * @param string $key Configuration key to retrieve
		 * @return mixed Configuration value or null if not found
		 */
		protected function getConfig(string $key): mixed {
			return $this->config[$key] ?? null;
		}
		
		/**
		 * Set configuration value
		 * @param string $key Configuration key to set
		 * @param mixed $value New value for the configuration key
		 */
		public function setConfig(string $key, mixed $value): void {
			$this->config[$key] = $value;
		}
		
		/**
		 * Default array renderer
		 * @param array $value The array to render
		 * @param string|null $key The key this array is stored under (if any)
		 * @param array $context Additional context information
		 */
		protected function renderArrayDefault(array $value, ?string $key = null, array $context = []): void {
			// Show the key (if present) and array size
			echo ($key ? "\"{$key}\" => " : '') . "array(" . count($value) . ") [\n";
			
			// Increase indentation for nested elements
			$this->increaseDepth();
			
			// Render each array element
			foreach ($value as $arrayKey => $arrayValue) {
				echo $this->getIndent();
				$this->renderValue($arrayValue, $arrayKey);
				echo "\n";
			}
			
			// Restore previous indentation level
			$this->decreaseDepth();
			
			// Close the array structure
			echo $this->getIndent() . "]";
		}
		
		/**
		 * Default object renderer
		 * @param object $value The object to render
		 * @param string|null $key The key this object is stored under (if any)
		 * @param array $context Additional context information
		 */
		protected function renderObjectDefault(object $value, $key = null, array $context = []): void {
			$className = get_class($value);
			$objectId = spl_object_id($value);
			
			// Show class name and unique object identifier
			echo ($key ? "\"{$key}\" => " : '') . "{$className} #{$objectId} {\n";
			
			// Increase indentation for properties
			$this->increaseDepth();
			
			// Get all displayable properties using reflection
			$properties = $this->getObjectProperties($value);
			
			// Render each property
			foreach ($properties as $property) {
				$propertyValue = $this->getPropertyValue($property, $value);
				$visibility = $this->getVisibilitySymbol($property);
				
				// Show visibility, property name, and value
				echo $this->getIndent() . $visibility . $property->getName() . ': ';
				$this->renderValue($propertyValue);
				echo "\n";
			}
			
			// Restore previous indentation level
			$this->decreaseDepth();
			echo $this->getIndent() . "}";
			
			// Clean up circular reference tracking for this object
			$this->removeFromCircularTracking($value);
		}
		
		/**
		 * Format the call location for display
		 * @param array $location Call location info
		 * @return string Formatted location string
		 */
		protected function formatCallLocation(array $location): string {
			// Extract the filename without the full path for cleaner display
			$file = basename($location['file']);
			$line = $location['line'];
			
			// Check if we have class information in the call stack
			if ($location['class']) {
				// Get just the class name without namespace for readability
				// Convert namespace separators to forward slashes, then get basename
				$className = basename(str_replace('\\', '/', $location['class']));
				
				// Build the caller string: ClassName->method() or ClassName::method()
				$caller = $className . $location['type'] . $location['function'] . '()';
			} elseif ($location['function'] !== 'unknown') {
				// For standalone functions (not class methods)
				$caller = $location['function'] . '()';
			} else {
				// No identifiable function/method name available
				$caller = '';
			}
			
			// Build the final location string
			if ($caller) {
				// Include both file location and caller info
				return "{$file}:{$line} in {$caller}";
			} else {
				// Fall back to just file and line number
				return "{$file}:{$line}";
			}
		}
		
		// Default implementations that concrete renderers can override
		
		/**
		 * Render circular reference indicator
		 * @param object $object The object that creates the circular reference
		 * @param string|null $key The key this object is stored under (if any)
		 */
		protected function renderCircularReference(object $object, string $key = null): void {
			$className = get_class($object);
			$objectId = spl_object_id($object);
			echo "*CIRCULAR REFERENCE* {$className} #{$objectId}";
		}
		
		/**
		 * Render max depth indicator
		 * @param string|null $key The key where max depth was reached (if any)
		 */
		protected function renderMaxDepthIndicator(?string $key = null): void {
			echo "*MAX DEPTH REACHED*";
		}
		
		/**
		 * Render unknown type
		 * @param mixed $value The value of unknown type
		 * @param string|null $key The key this value is stored under (if any)
		 * @param array $context Additional context information
		 */
		protected function renderUnknownType(mixed $value, ?string $key = null, array $context = []): void {
			echo "unknown(" . gettype($value) . ")";
		}
		
		// Hooks for extending behavior
		
		/**
		 * Called before rendering a value
		 * @param mixed $value The value about to be rendered
		 * @param string|null $key The key this value is stored under (if any)
		 * @param array $context Additional context information
		 */
		protected function beforeRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Override in concrete classes if needed
		}
		
		/**
		 * Called after rendering a value
		 * @param mixed $value The value that was just rendered
		 * @param string|null $key The key this value was stored under (if any)
		 * @param array $context Additional context information
		 */
		protected function afterRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Override in concrete classes if needed
		}
	}