<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Assets\StyleSheet;
	use Quellabs\Support\Debugger\Assets\JavaScript;
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * HTML renderer for web output - Refactored to eliminate duplication
	 */
	class HtmlRenderer extends BaseRenderer {
		
		/**
		 * Flag to ensure CSS/JS styles are only output once per request
		 * @var bool
		 */
		private static bool $stylesOutputted = false;
		
		/**
		 * Value type rendering strategies
		 * @var array
		 */
		private static array $typeRenderers = [];
		
		/**
		 * Initialize type renderers
		 */
		private static function initTypeRenderers(): void {
			if (!empty(self::$typeRenderers)) {
				return;
			}
			
			self::$typeRenderers = [
				'string'   => [self::class, 'renderString'],
				'integer'  => [self::class, 'renderNumber'],
				'double'   => [self::class, 'renderNumber'],
				'boolean'  => [self::class, 'renderBoolean'],
				'NULL'     => [self::class, 'renderNull'],
				'array'    => [self::class, 'renderArrayType'],
				'object'   => [self::class, 'renderObjectType'],
				'resource' => [self::class, 'renderResource'],
			];
		}
		
		/**
		 * Render multiple variables for HTML output
		 * @param array $vars Variables to render
		 */
		public static function render(array $vars): void {
			// Only output styles once per request
			if (!self::$stylesOutputted) {
				echo StyleSheet::get();
				echo JavaScript::get();
				self::$stylesOutputted = true;
			}
			
			self::initTypeRenderers();
			
			// Render each variable in its own container
			foreach ($vars as $var) {
				echo '<div class="canvas-dump">';
				self::renderValue($var);
				echo '</div>';
			}
		}
		
		/**
		 * Render a single value with HTML formatting
		 * @param mixed $value The value to render
		 * @param string|null $key Optional key name
		 * @param bool $inline Whether to render inline (for object properties)
		 */
		protected static function renderValue($value, $key = null, bool $inline = false) {
			$type = gettype($value);
			
			// Render key if provided
			if ($key !== null) {
				if (!$inline) {
					echo '<div class="canvas-dump-line">' . self::getIndent();
				}
				
				echo '<span class="canvas-dump-key" style="color: ' . Colors::getHtml('key') . '">"' . htmlspecialchars($key) . '"</span> => ';
			} elseif (!$inline) {
				echo '<div class="canvas-dump-line">' . self::getIndent();
			}
			
			// Use type-specific renderer
			$renderer = self::$typeRenderers[$type] ?? [self::class, 'renderUnknown'];
			$result = call_user_func($renderer, $value, $inline);
			
			// Close div if not inline
			if (!$inline && !in_array($type, ['array', 'object'])) {
				echo '</div>';
			}
			
			return $result;
		}
		
		/**
		 * Render string values
		 * @param string $value
		 * @return void [html_content, should_close_div]
		 */
		private static function renderString(string $value): void {
			echo '<span style="color: ' . Colors::getHtml('string') . '">"' . htmlspecialchars($value) . '"</span>';
			echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')</span>';
		}
		
		/**
		 * Render numeric values (int/float)
		 * @param int|float $value
		 * @param bool $inline
		 */
		private static function renderNumber($value, bool $inline = false): void {
			$type = is_int($value) ? 'integer' : 'float';
			echo '<span style="color: ' . Colors::getHtml($type) . '">' . $value . '</span>';
			
			if ($inline) {
				echo '</div>';
			}
		}
		
		/**
		 * Render boolean values
		 * @param bool $value
		 * @param bool $inline
		 */
		private static function renderBoolean(bool $value, bool $inline = false): void {
			echo '<span style="color: ' . Colors::getHtml('boolean') . '">' . ($value ? 'true' : 'false') . '</span>';
			
			if ($inline) {
				echo '</div>';
			}
		}
		
		/**
		 * Render null values
		 * @param null $value
		 * @param bool $inline
		 */
		private static function renderNull($value, bool $inline = false): void {
			echo '<span style="color: ' . Colors::getHtml('null') . '">null</span>';
			
			if ($inline) {
				echo '</div>';
			}
		}
		
		/**
		 * Render resource values
		 * @param resource $value
		 * @param bool $inline
		 */
		private static function renderResource($value, bool $inline = false): void {
			echo '<span style="color: ' . Colors::getHtml('resource') . '">resource(' . get_resource_type($value) . ')</span>';
			
			if ($inline) {
				echo '</div>';
			}
		}
		
		/**
		 * Render unknown types
		 * @param mixed $value
		 * @param bool $inline
		 */
		private static function renderUnknown($value, bool $inline = false): void {
			$type = gettype($value);
			echo '<span style="color: ' . Colors::getHtml('null') . '">' . htmlspecialchars($type) . '</span>';
			
			if ($inline) {
				echo '</div>';
			}
		}
		
		/**
		 * Handle array type rendering (delegates to renderArray)
		 * @param array $value
		 * @param bool $inline
		 */
		private static function renderArrayType(array $value, bool $inline = false): void {
			if (!$inline) {
				echo '</div>';
			}
			self::renderArray($value);
		}
		
		/**
		 * Handle object type rendering (delegates to renderObject)
		 * @param object $value
		 * @param bool $inline
		 */
		private static function renderObjectType(object $value, bool $inline = false): void {
			if (!$inline) {
				echo '</div>';
			}
			self::renderObject($value);
		}
		
		/**
		 * Render arrays with collapsible HTML interface
		 * @param array $array The array to render
		 */
		protected static function renderArray(array $array): void {
			$count = count($array);
			$id = self::getNextId();
			
			if ($count === 0) {
				echo '<span style="color: ' . Colors::getHtml('array') . '">array:0</span> []';
				return;
			}
			
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>';
			echo '<span style="color: ' . Colors::getHtml('array') . '">array:' . $count . '</span> [';
			echo '</span>';
			
			echo '<div class="canvas-dump-content">';
			
			if (!self::isMaxDepthReached()) {
				self::increaseDepth();
				
				foreach ($array as $key => $value) {
					self::renderValue($value, $key);
				}
				
				self::decreaseDepth();
			} else {
				echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth + 1) . '<span style="color: ' . Colors::getHtml('null') . '">...</span></div>';
			}
			
			echo '<div class="canvas-dump-line">' . self::getIndent() . ']</div>';
			echo '</div>';
			echo '</div>';
		}
		
		/**
		 * Render objects with HTML formatting
		 * @param object $object The object to render
		 */
		protected static function renderObject(object $object): void {
			$className = get_class($object);
			$objectId = spl_object_id($object);
			$id = self::getNextId();
			
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>';
			echo '<span style="color: ' . Colors::getHtml('object') . '">' . htmlspecialchars($className) . '</span> {#' . $objectId;
			
			// Add special handling for common objects
			$objectValue = self::getObjectStringRepresentation($object);
			echo '<span style="color: ' . Colors::getHtml('string') . '">' . $objectValue . '</span>';
			echo '</span>';
			
			echo '<div class="canvas-dump-content">';
			
			if (!self::isMaxDepthReached()) {
				self::increaseDepth();
				
				$properties = self::getObjectProperties($object);
				
				foreach ($properties as $property) {
					$value = self::getPropertyValue($property, $object);
					$visibility = self::getVisibilitySymbol($property);
					$visibilityClass = $property->isPublic() ? '' : ($property->isProtected() ? 'canvas-dump-protected' : 'canvas-dump-private');
					
					echo '<div class="canvas-dump-line">' . self::getIndent() . '<span class="' . $visibilityClass . '" style="color: ' . Colors::getHtml('property') . '">' . $visibility . htmlspecialchars($property->getName()) . '</span>: ';
					
					if ($value === '*** uninitialized ***') {
						echo '<span style="color: ' . Colors::getHtml('null') . '">*** uninitialized ***</span></div>';
					} else {
						self::renderValue($value, null, true);
					}
				}
				
				self::decreaseDepth();
			} else {
				echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth + 1) . '<span style="color: ' . Colors::getHtml('null') . '">...</span></div>';
			}
			
			echo '<div class="canvas-dump-line">' . self::getIndent() . '}</div>';
			echo '</div>';
			echo '</div>';
		}
		
		/**
		 * Get string representation of common objects
		 * @param object $object The object to get string representation for
		 * @return string
		 */
		private static function getObjectStringRepresentation(object $object): string {
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				return ' "' . $object->format('Y-m-d H:i:s T') . '"';
			}
			
			if (method_exists($object, '__toString')) {
				try {
					$stringValue = (string)$object;
					if (strlen($stringValue) <= 100) {
						return ' "' . htmlspecialchars($stringValue) . '"';
					}
				} catch (\Exception $e) {
					// Ignore if __toString() throws
				}
			}
			
			return '';
		}
	}