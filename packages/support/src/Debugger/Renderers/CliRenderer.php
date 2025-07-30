<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * CLI renderer for terminal output with ANSI colors - Now properly utilizing BaseRenderer
	 */
	class CliRenderer extends BaseRenderer {
		
		/**
		 * Initialize CLI-specific type renderers
		 */
		protected static function initTypeRenderers(): void {
			if (!empty(static::$typeRenderers)) {
				return;
			}
			
			static::$typeRenderers = [
				'string' => [static::class, 'renderString'],
				'integer' => [static::class, 'renderInteger'],
				'double' => [static::class, 'renderFloat'],
				'boolean' => [static::class, 'renderBoolean'],
				'NULL' => [static::class, 'renderNull'],
				'array' => [static::class, 'renderArray'],
				'object' => [static::class, 'renderObject'],
				'resource' => [static::class, 'renderResource'],
			];
		}
		
		/**
		 * Render multiple variables for CLI output
		 * @param array $vars Variables to render
		 */
		public static function render(array $vars): void {
			// Reset state for fresh render
			static::reset();
			
			// Initialize type renderers
			static::initTypeRenderers();
			
			// Render each variable with separation
			foreach ($vars as $var) {
				static::renderValue($var);
				echo "\n\n";
				
				// Reset for next variable
				static::$processedObjects = [];
			}
		}
		
		/**
		 * Render string values
		 * @param string $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderString(string $value, ?string $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			
			$truncated = static::truncateString($value);
			$wasTruncated = $truncated !== $value;
			
			echo Colors::wrapAnsi('"' . $truncated . '"', 'string');
			echo ' (' . strlen($value) . ')';
			
			if ($wasTruncated) {
				echo ' ' . Colors::wrapAnsi('[truncated]', 'null');
			}
			
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Render integer values
		 * @param int $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderInteger(int $value, ?string $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo Colors::wrapAnsi((string)$value, 'integer');
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Render float values
		 * @param float $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderFloat(float $value, ?string $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo Colors::wrapAnsi((string)$value, 'float');
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Render boolean values
		 * @param bool $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderBoolean(bool $value, ?string $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo Colors::wrapAnsi($value ? 'true' : 'false', 'boolean');
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Render null values
		 * @param null $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderNull(null $value, ?string $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo Colors::wrapAnsi('null', 'null');
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Render resource values
		 * @param resource $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderResource($value, ?string $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo Colors::wrapAnsi('resource(' . get_resource_type($value) . ')', 'resource');
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Render arrays for CLI output
		 * @param array $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderArray(array $value, ?string $key = null, array $context = []): void {
			$count = count($value);
			
			static::renderKeyIfPresent($key);
			
			if ($count === 0) {
				echo Colors::wrapAnsi('array:0', 'array') . ' []';
				static::newLineIfNotInline($context);
				return;
			}
			
			// Check if array should be truncated
			$shouldTruncate = static::shouldTruncateArray($value);
			$displayArray = $shouldTruncate ? static::getTruncatedArray($value) : $value;
			$displayCount = count($displayArray);
			
			echo Colors::wrapAnsi('array:' . $count, 'array');
			
			if ($shouldTruncate) {
				echo ' ' . Colors::wrapAnsi('(showing ' . $displayCount . ')', 'null');
			}
			
			echo " [\n";
			
			static::increaseDepth();
			foreach ($displayArray as $arrayKey => $arrayValue) {
				echo static::getIndent();
				static::renderValue($arrayValue, $arrayKey, ['inline' => false]);
			}
			
			if ($shouldTruncate) {
				echo static::getIndent();
				echo Colors::wrapAnsi('... and ' . ($count - $displayCount) . ' more elements', 'null');
				echo "\n";
			}
			
			static::decreaseDepth();
			echo static::getIndent() . ']';
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Render objects for CLI output
		 * @param object $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderObject(object $value, ?string $key = null, array $context = []): void {
			$className = get_class($value);
			$objectId = spl_object_id($value);
			
			static::renderKeyIfPresent($key);
			
			echo Colors::wrapAnsi($className, 'object') . ' {#' . $objectId;
			
			// Add string representation if available
			$stringRepresentation = static::getObjectStringRepresentation($value);
			
			if ($stringRepresentation) {
				echo ' ' . Colors::wrapAnsi($stringRepresentation, 'string');
			}
			
			echo "\n";
			
			static::increaseDepth();
			$properties = static::getObjectProperties($value);
			
			foreach ($properties as $property) {
				$propertyValue = static::getPropertyValue($property, $value);
				$visibility = static::getVisibilitySymbol($property);
				
				echo static::getIndent();
				echo Colors::wrapAnsi($visibility . $property->getName(), 'property') . ': ';
				
				if (is_string($propertyValue) && str_starts_with($propertyValue, '***')) {
					echo Colors::wrapAnsi($propertyValue, 'null');
					echo "\n";
				} else {
					static::renderValue($propertyValue, null, ['inline' => true]);
				}
			}
			
			static::decreaseDepth();
			echo static::getIndent() . '}';
			static::newLineIfNotInline($context);
			
			// Remove from circular reference tracking
			static::removeFromCircularTracking($value);
		}
		
		/**
		 * Override circular reference rendering for CLI
		 * @param object $object
		 * @param string|null $key
		 */
		protected static function renderCircularReference(object $object, $key = null): void {
			static::renderKeyIfPresent($key);
			$className = get_class($object);
			$objectId = spl_object_id($object);
			
			echo Colors::wrapAnsi('*CIRCULAR REFERENCE* ', 'null');
			echo Colors::wrapAnsi($className, 'object') . ' {#' . $objectId . '}';
			echo "\n";
		}
		
		/**
		 * Override max depth rendering for CLI
		 * @param string|null $key
		 */
		protected static function renderMaxDepthIndicator(?string $key = null): void {
			static::renderKeyIfPresent($key);
			echo Colors::wrapAnsi('*MAX DEPTH REACHED*', 'null');
			echo "\n";
		}
		
		/**
		 * Override unknown type rendering for CLI
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderUnknownType(mixed $value, ?string $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			$type = gettype($value);
			echo Colors::wrapAnsi('unknown(' . $type . ')', 'null');
			static::newLineIfNotInline($context);
		}
		
		/**
		 * Helper: Render key if present
		 * @param string|null $key
		 */
		private static function renderKeyIfPresent(?string $key): void {
			if ($key !== null) {
				echo Colors::wrapAnsi('"' . $key . '"', 'key') . ' => ';
			}
		}
		
		/**
		 * Helper: Add newline if not inline
		 * @param array $context
		 */
		private static function newLineIfNotInline(array $context): void {
			if (!($context['inline'] ?? false)) {
				echo "\n";
			}
		}
		
		/**
		 * Get string representation of common objects
		 * @param object $object
		 * @return string
		 */
		private static function getObjectStringRepresentation(object $object): string {
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				return '"' . $object->format('Y-m-d H:i:s T') . '"';
			}
			
			if (method_exists($object, '__toString')) {
				try {
					$stringValue = (string)$object;
					$truncated = static::truncateString($stringValue);
					
					if ($truncated) {
						return '"' . $truncated . '"';
					}
				} catch (\Exception $e) {
					return '"*toString() error*"';
				}
			}
			
			return '';
		}
		
		/**
		 * Override beforeRenderValue to handle CLI-specific setup
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function beforeRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Add indentation for non-inline renders
			if (!($context['inline'] ?? false) && !in_array(gettype($value), ['array', 'object'])) {
				echo static::getIndent();
			}
		}
	}