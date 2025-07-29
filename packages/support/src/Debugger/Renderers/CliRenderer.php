<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * CLI renderer for terminal output with ANSI colors
	 */
	class CliRenderer extends BaseRenderer {
		
		/**
		 * Render multiple variables for CLI output
		 * @param array $vars Variables to render
		 */
		public static function render(array $vars): void {
			foreach ($vars as $var) {
				self::renderValue($var);
				echo "\n";
			}
		}
		
		/**
		 * Render a single value for CLI output
		 * @param mixed $value The value to render
		 * @param string|null $key Optional key name
		 */
		protected static function renderValue($value, $key = null) {
			$indent = self::getIndent();
			
			// Render key with ANSI blue color if this is part of an array or object
			if ($key !== null) {
				echo $indent . Colors::wrapAnsi('"' . $key . '"', 'key') . ' => ';
			} else {
				echo $indent;
			}
			
			$type = gettype($value);
			
			switch ($type) {
				case 'string':
					echo Colors::wrapAnsi('"' . $value . '"', 'string') . ' (' . strlen($value) . ')';
					break;
				
				case 'integer':
				case 'double':
					echo Colors::wrapAnsi((string)$value, $type === 'double' ? 'float' : 'integer');
					break;
				
				case 'boolean':
					echo Colors::wrapAnsi($value ? 'true' : 'false', 'boolean');
					break;
				
				case 'NULL':
					echo Colors::wrapAnsi('null', 'null');
					break;
				
				case 'array':
					self::renderArray($value);
					return;
				
				case 'object':
					self::renderObject($value);
					return;
				
				case 'resource':
					echo Colors::wrapAnsi('resource(' . get_resource_type($value) . ')', 'resource');
					break;
				
				default:
					echo $type;
			}
			
			if ($key === null) {
				echo "\n";
			}
		}
		
		/**
		 * Render arrays for CLI output
		 * @param array $array The array to render
		 */
		protected static function renderArray(array $array) {
			$count = count($array);
			echo Colors::wrapAnsi('array:' . $count, 'array') . " [\n";
			
			if (!self::isMaxDepthReached() && $count > 0) {
				self::increaseDepth();
				
				foreach ($array as $key => $value) {
					self::renderValue($value, $key);
					echo "\n";
				}
				
				self::decreaseDepth();
			}
			
			echo self::getIndent() . ']';
		}
		
		/**
		 * Render objects for CLI output
		 * @param object $object The object to render
		 */
		protected static function renderObject(object $object): void {
			$className = get_class($object);
			$hash = spl_object_hash($object);
			
			echo Colors::wrapAnsi($className, 'object') . ' {#' . substr($hash, -4) . "\n";
			
			if (!self::isMaxDepthReached()) {
				self::increaseDepth();
				
				$properties = self::getObjectProperties($object);
				
				foreach ($properties as $property) {
					$value = self::getPropertyValue($property, $object);
					$visibility = self::getVisibilitySymbol($property);
					
					echo CliRenderer . phpself::getIndent() . Colors::wrapAnsi($visibility . $property->getName(), 'property') . ': ';
					
					if ($value === '*** uninitialized ***') {
						echo Colors::wrapAnsi('*** uninitialized ***', 'null');
					} else {
						self::renderValue($value);
					}
					echo "\n";
				}
				
				self::decreaseDepth();
			}
			
			echo self::getIndent() . '}';
		}
	}