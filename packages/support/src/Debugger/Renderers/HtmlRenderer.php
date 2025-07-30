<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Assets\StyleSheet;
	use Quellabs\Support\Debugger\Assets\JavaScript;
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * HTML renderer for web output - Now properly utilizing BaseRenderer
	 */
	class HtmlRenderer extends BaseRenderer {
		
		/**
		 * Flag to ensure CSS/JS styles are only output once per request
		 * @var bool
		 */
		private static bool $stylesOutputted = false;
		
		/**
		 * Initialize HTML-specific type renderers
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
		 * Render multiple variables for HTML output
		 * @param array $vars Variables to render
		 */
		public static function render(array $vars): void {
			// Reset state for fresh render
			static::reset();
			
			// Initialize type renderers
			static::initTypeRenderers();
			
			// Only output styles once per request
			if (!static::$stylesOutputted) {
				echo StyleSheet::get();
				echo JavaScript::get();
				static::$stylesOutputted = true;
			}
			
			// Render each variable in its own container
			foreach ($vars as $var) {
				echo '<div class="canvas-dump">';
				static::renderValue($var);
				echo '</div>';
				
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
		protected static function renderString(string $value, $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			
			$truncated = static::truncateString($value);
			$wasTruncated = $truncated !== $value;
			
			echo '<span style="color: ' . Colors::getHtml('string') . '">"' . htmlspecialchars($truncated) . '"</span>';
			echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')';
			
			if ($wasTruncated) {
				echo ' truncated';
			}
			
			echo '</span>';
			static::closeLineIfNotInline($context);
		}
		
		/**
		 * Render integer values
		 * @param int $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderInteger(int $value, $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('integer') . '">' . $value . '</span>';
			static::closeLineIfNotInline($context);
		}
		
		/**
		 * Render float values
		 * @param float $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderFloat(float $value, $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('float') . '">' . $value . '</span>';
			static::closeLineIfNotInline($context);
		}
		
		/**
		 * Render boolean values
		 * @param bool $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderBoolean(bool $value, $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('boolean') . '">' . ($value ? 'true' : 'false') . '</span>';
			static::closeLineIfNotInline($context);
		}
		
		/**
		 * Render null values
		 * @param null $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderNull($value, $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('null') . '">null</span>';
			static::closeLineIfNotInline($context);
		}
		
		/**
		 * Render resource values
		 * @param resource $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderResource($value, $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('resource') . '">resource(' . get_resource_type($value) . ')</span>';
			static::closeLineIfNotInline($context);
		}
		
		/**
		 * Render arrays with collapsible HTML interface
		 * @param array $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderArray(array $value, $key = null, array $context = []): void {
			$count = count($value);
			$id = static::getNextId();
			
			static::renderKeyIfPresent($key);
			
			if ($count === 0) {
				echo '<span style="color: ' . Colors::getHtml('array') . '">array:0</span> []';
				static::closeLineIfNotInline($context);
				return;
			}
			
			// Check if array should be truncated
			$shouldTruncate = static::shouldTruncateArray($value);
			$displayArray = $shouldTruncate ? static::getTruncatedArray($value) : $value;
			$displayCount = count($displayArray);
			
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>';
			echo '<span style="color: ' . Colors::getHtml('array') . '">array:' . $count . '</span>';
			
			if ($shouldTruncate) {
				echo ' <span class="canvas-dump-type">(showing ' . $displayCount . ')</span>';
			}
			
			echo ' [</span>';
			echo '<div class="canvas-dump-content">';
			
			static::increaseDepth();
			foreach ($displayArray as $arrayKey => $arrayValue) {
				echo '<div class="canvas-dump-line">' . static::getIndent();
				static::renderValue($arrayValue, $arrayKey, ['inline' => false]);
				echo '</div>';
			}
			
			if ($shouldTruncate) {
				echo '<div class="canvas-dump-line">' . static::getIndent();
				echo '<span style="color: ' . Colors::getHtml('null') . '">... and ' . ($count - $displayCount) . ' more elements</span>';
				echo '</div>';
			}
			
			static::decreaseDepth();
			echo '<div class="canvas-dump-line">' . static::getIndent() . ']</div>';
			echo '</div></div>';
		}
		
		/**
		 * Render objects with HTML formatting
		 * @param object $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderObject(object $value, $key = null, array $context = []): void {
			$className = get_class($value);
			$objectId = spl_object_id($value);
			$id = static::getNextId();
			
			static::renderKeyIfPresent($key);
			
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>';
			echo '<span style="color: ' . Colors::getHtml('object') . '">' . htmlspecialchars($className) . '</span> {#' . $objectId;
			
			// Add string representation if available
			$stringRepresentation = static::getObjectStringRepresentation($value);
			if ($stringRepresentation) {
				echo '<span style="color: ' . Colors::getHtml('string') . '">' . $stringRepresentation . '</span>';
			}
			
			echo '</span>';
			echo '<div class="canvas-dump-content">';
			
			static::increaseDepth();
			$properties = static::getObjectProperties($value);
			
			foreach ($properties as $property) {
				$propertyValue = static::getPropertyValue($property, $value);
				$visibility = static::getVisibilitySymbol($property);
				$visibilityClass = $property->isPublic() ? '' :
					($property->isProtected() ? 'canvas-dump-protected' : 'canvas-dump-private');
				
				echo '<div class="canvas-dump-line">' . static::getIndent();
				echo '<span class="' . $visibilityClass . '" style="color: ' . Colors::getHtml('property') . '">';
				echo $visibility . htmlspecialchars($property->getName()) . '</span>: ';
				
				if (is_string($propertyValue) && strpos($propertyValue, '***') === 0) {
					echo '<span style="color: ' . Colors::getHtml('null') . '">' . htmlspecialchars($propertyValue) . '</span>';
				} else {
					static::renderValue($propertyValue, null, ['inline' => true]);
				}
				echo '</div>';
			}
			
			static::decreaseDepth();
			echo '<div class="canvas-dump-line">' . static::getIndent() . '}</div>';
			echo '</div></div>';
			
			// Remove from circular reference tracking
			static::removeFromCircularTracking($value);
		}
		
		/**
		 * Render circular reference indicator
		 * @param object $object
		 * @param string|null $key
		 */
		protected static function renderCircularReference(object $object, $key = null): void {
			static::renderKeyIfPresent($key);
			$className = get_class($object);
			$objectId = spl_object_id($object);
			
			echo '<span style="color: ' . Colors::getHtml('null') . '">*CIRCULAR REFERENCE* ';
			echo htmlspecialchars($className) . ' {#' . $objectId . '}</span>';
			echo '</div>';
		}
		
		/**
		 * Render max depth indicator
		 * @param string|null $key
		 */
		protected static function renderMaxDepthIndicator($key = null): void {
			static::renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('null') . '">*MAX DEPTH REACHED*</span>';
			echo '</div>';
		}
		
		/**
		 * Render unknown type
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function renderUnknownType($value, $key = null, array $context = []): void {
			static::renderKeyIfPresent($key);
			$type = gettype($value);
			echo '<span style="color: ' . Colors::getHtml('null') . '">unknown(' . htmlspecialchars($type) . ')</span>';
			static::closeLineIfNotInline($context);
		}
		
		/**
		 * Helper: Render key if present
		 * @param string|null $key
		 */
		private static function renderKeyIfPresent($key): void {
			if ($key !== null) {
				echo '<span class="canvas-dump-key" style="color: ' . Colors::getHtml('key') . '">';
				echo '"' . htmlspecialchars($key) . '"</span> => ';
			}
		}
		
		/**
		 * Helper: Close line div if not inline
		 * @param array $context
		 */
		private static function closeLineIfNotInline(array $context): void {
			if (!($context['inline'] ?? false)) {
				echo '</div>';
			}
		}
		
		/**
		 * Get string representation of common objects
		 * @param object $object
		 * @return string
		 */
		private static function getObjectStringRepresentation(object $object): string {
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				return ' "' . $object->format('Y-m-d H:i:s T') . '"';
			}
			
			if (method_exists($object, '__toString')) {
				try {
					$stringValue = (string)$object;
					$truncated = static::truncateString($stringValue);
					if ($truncated) {
						return ' "' . htmlspecialchars($truncated) . '"';
					}
				} catch (\Exception $e) {
					return ' "*toString() error*"';
				}
			}
			
			return '';
		}
		
		/**
		 * Override beforeRenderValue to handle HTML-specific setup
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected static function beforeRenderValue($value, $key = null, array $context = []): void {
			// Only start a new line div if we're not inline and not handling complex types
			if (!($context['inline'] ?? false) && !in_array(gettype($value), ['array', 'object'])) {
				echo '<div class="canvas-dump-line">' . static::getIndent();
			}
		}
	}