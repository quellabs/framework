<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Assets\StyleSheet;
	use Quellabs\Support\Debugger\Assets\JavaScript;
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * HTML renderer for web output
	 */
	class HtmlRenderer extends BaseRenderer {
		
		/**
		 * Flag to ensure CSS/JS styles are only output once per request
		 * @var bool
		 */
		private static bool $stylesOutputted = false;
		
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
		 */
		protected static function renderValue($value, $key = null) {
			$indent = self::getIndent();
			
			// Render key if this is part of an array or object
			if ($key !== null) {
				echo '<div class="canvas-dump-line">' . $indent . '<span class="canvas-dump-key" style="color: ' . Colors::getHtml('key') . '">"' . htmlspecialchars($key) . '"</span> => ';
			} else {
				echo '<div class="canvas-dump-line">' . $indent;
			}
			
			$type = gettype($value);
			
			switch ($type) {
				case 'string':
					echo '<span style="color: ' . Colors::getHtml('string') . '">"' . htmlspecialchars($value) . '"</span>';
					echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')</span>';
					break;
				
				case 'integer':
				case 'double':
					echo '<span style="color: ' . Colors::getHtml($type === 'double' ? 'float' : 'integer') . '">' . $value . '</span>';
					break;
				
				case 'boolean':
					echo '<span style="color: ' . Colors::getHtml('boolean') . '">' . ($value ? 'true' : 'false') . '</span>';
					break;
				
				case 'NULL':
					echo '<span style="color: ' . Colors::getHtml('null') . '">null</span>';
					break;
				
				case 'array':
					echo '</div>';
					self::renderArray($value);
					return;
				
				case 'object':
					echo '</div>';
					self::renderObject($value);
					return;
				
				case 'resource':
					echo '<span style="color: ' . Colors::getHtml('resource') . '">resource(' . get_resource_type($value) . ')</span>';
					break;
				
				default:
					echo '<span style="color: ' . Colors::getHtml('null') . '">' . $type . '</span>';
			}
			
			echo '</div>';
		}
		
		/**
		 * Render arrays with collapsible HTML interface
		 * @param array $array The array to render
		 */
		protected static function renderArray(array $array) {
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
			$hash = spl_object_hash($object);
			$id = self::getNextId();
			
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>';
			echo '<span style="color: ' . Colors::getHtml('object') . '">' . $className . '</span> {#' . substr($hash, -4);
			
			// Add special handling for common objects
			$objectValue = self::getObjectStringRepresentation($object);
			echo '<span style="color: ' . Colors::getHtml('string') . '">' . $objectValue . '</span>';
			echo '</span>';
			
			if (!self::isMaxDepthReached()) {
				echo '<div class="canvas-dump-content">';
				self::increaseDepth();
				
				$properties = self::getObjectProperties($object);
				
				foreach ($properties as $property) {
					$value = self::getPropertyValue($property, $object);
					$visibility = self::getVisibilitySymbol($property);
					$visibilityClass = $property->isPublic() ? '' : ($property->isProtected() ? 'canvas-dump-protected' : 'canvas-dump-private');
					
					echo '<div class="canvas-dump-line">' . self::getIndent() . '<span class="' . $visibilityClass . '" style="color: ' . Colors::getHtml('property') . '">' . $visibility . $property->getName() . '</span>: ';
					
					if ($value === '*** uninitialized ***') {
						echo '<span style="color: ' . Colors::getHtml('null') . '">*** uninitialized ***</span></div>';
					} else {
						self::renderSimpleValueInline($value);
					}
				}
				
				self::decreaseDepth();
				echo '<div class="canvas-dump-line">' . self::getIndent() . '}</div>';
				echo '</div>';
			} else {
				echo '<div class="canvas-dump-content">';
				echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth + 1) . '<span style="color: ' . Colors::getHtml('null') . '">...</span></div>';
				echo '<div class="canvas-dump-line">' . self::getIndent() . '}</div>';
				echo '</div>';
			}
			
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
		
		/**
		 * Render simple values inline
		 * @param mixed $value The value to render
		 */
		private static function renderSimpleValueInline($value): void {
			$type = gettype($value);
			
			if ($type === 'string') {
				echo '<span style="color: ' . Colors::getHtml('string') . '">"' . htmlspecialchars($value) . '"</span>';
				echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')</span></div>';
			} elseif ($type === 'integer' || $type === 'double') {
				echo '<span style="color: ' . Colors::getHtml($type === 'double' ? 'float' : 'integer') . '">' . $value . '</span></div>';
			} elseif ($type === 'boolean') {
				echo '<span style="color: ' . Colors::getHtml('boolean') . '">' . ($value ? 'true' : 'false') . '</span></div>';
			} elseif ($type === 'NULL') {
				echo '<span style="color: ' . Colors::getHtml('null') . '">null</span></div>';
			} else {
				echo '</div>';
				self::renderValue($value);
			}
		}
	}