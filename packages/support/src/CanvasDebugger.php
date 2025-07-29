<?php
	
	namespace Quellabs\Support;
	
	/**
	 * CanvasDebugger - A sophisticated debugging utility for PHP
	 *
	 * Provides enhanced variable dumping with collapsible HTML output for web contexts
	 * and colored terminal output for CLI environments. Offers better visualization
	 * than standard var_dump() with syntax highlighting and interactive features.
	 */
	class CanvasDebugger {

		/**
		 * Color scheme for syntax highlighting different data types
		 * @var array
		 */
		private static $colors = [
			'string'   => '#d14',      // Red for strings
			'integer'  => '#005cc5',  // Blue for integers
			'float'    => '#005cc5',    // Blue for floats
			'boolean'  => '#6f42c1',  // Purple for booleans
			'null'     => '#6a737d',     // Gray for null values
			'array'    => '#e36209',    // Orange for arrays
			'object'   => '#28a745',   // Green for objects
			'resource' => '#fd7e14', // Orange-red for resources
			'property' => '#6f42c1', // Purple for object properties
			'method'   => '#005cc5',   // Blue for methods
			'key'      => '#032f62'       // Dark blue for array keys
		];
		
		/**
		 * Current nesting depth for indentation
		 * @var int
		 */
		private static int $depth = 0;
		
		/**
		 * Maximum depth to prevent infinite recursion
		 * @var int
		 */
		private static int $maxDepth = 10;
		
		/**
		 * Counter for generating unique IDs for collapsible elements
		 * @var int
		 */
		private static int $uniqueId = 0;
		
		/**
		 * Flag to ensure CSS/JS styles are only output once per request
		 * @var bool
		 */
		private static bool $stylesOutputted = false;
		
		/**
		 * Dump variables without stopping execution
		 * @param mixed ...$vars Variables to dump
		 */
		public static function dump(...$vars): void {
			// Check if we're in a web context (not command line interface)
			if (php_sapi_name() !== 'cli') {
				// If headers already sent, we can't use fancy HTML - fall back to simple output
				if (headers_sent()) {
					echo '<pre style="background:#f8f9fa;padding:10px;border:1px solid #ddd;margin:10px 0;">';
					foreach ($vars as $var) {
						var_dump($var);
					}
					echo '</pre>';
					return;
				}
				
				// Ensure we have output buffering for clean HTML output
				// This prevents our debug output from interfering with the main response
				if (ob_get_level() === 0) {
					ob_start();
				}
			}
			
			// Route to appropriate rendering method based on environment
			if (php_sapi_name() === 'cli') {
				self::dumpCli($vars);
			} else {
				self::dumpHtml($vars);
			}
		}
		
		
		/**
		 * Similar to dump() but stops execution afterward. Includes comprehensive
		 * error handling to ensure debug output is always visible, even if our
		 * fancy formatting fails.
		 * @param mixed ...$vars Variables to dump before dying
		 */
		public static function dumpAndDie(...$vars) {
			try {
				// Clear any existing output buffers to prevent interference
				// This ensures our debug output appears cleanly
				if (php_sapi_name() !== 'cli') {
					while (ob_get_level()) {
						ob_end_clean();
					}
					
					// Start fresh output buffer for our debug content
					ob_start();
				}
				
				// Attempt to use our enhanced dump method
				self::dump(...$vars);
				
				// Flush our debug output to the browser
				if (php_sapi_name() !== 'cli') {
					ob_end_flush();
				}
				
			} catch (\Throwable $e) {
				// If our fancy debug fails for any reason, fall back to basic output
				// This ensures developers always see something, even if it's not pretty
				echo '<h3>Canvas Debug Error - Falling back to simple output:</h3>';
				echo '<pre>';
				
				foreach ($vars as $var) {
					var_dump($var);
				}
				
				echo '</pre>';
				echo '<p>Original error: ' . $e->getMessage() . '</p>';
			}
			
			// Terminate script execution
			die();
		}
		
		/**
		 * Simple, guaranteed-to-work dump method
		 * When all else fails, this method provides basic but reliable output.
		 * Used as a last resort when the main dump methods encounter issues.
		 * @param mixed ...$vars Variables to dump
		 */
		public static function safeDump(...$vars) {
			echo '<pre style="background:#f8f9fa;padding:10px;border:1px solid #ddd;margin:10px 0;font-family:monospace;">';
			
			foreach ($vars as $var) {
				var_dump($var);
			}
			
			echo '</pre>';
		}
		
		/**
		 * Handle HTML output for web contexts
		 * Outputs CSS/JS once per request, then renders each variable
		 * with interactive HTML formatting.
		 * @param array $vars Variables to dump
		 */
		private static function dumpHtml(array $vars): void {
			// Only output styles once per request to avoid duplication
			if (!self::$stylesOutputted) {
				echo self::getStyles();
				echo self::getJavaScript();
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
		 * Generate CSS styles for HTML output
		 *
		 * Creates a comprehensive stylesheet for the debug output including:
		 * - Container styling with shadows and borders
		 * - Syntax highlighting colors
		 * - Interactive elements (hover effects, collapsible sections)
		 * - High z-index to ensure visibility over other content
		 *
		 * @return string CSS stylesheet
		 */
		private static function getStyles(): string {
			return '<style>
            .canvas-dump {
                background: linear-gradient(135deg, #1e1e1e 0%, #2d2d2d 100%);
                border: 1px solid #404040;
                border-radius: 12px;
                padding: 20px;
                margin: 20px 0;
                font-family: "JetBrains Mono", "Fira Code", "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 14px;
                line-height: 1.2;
                color: #e9ecef;
                overflow-x: auto;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.05);
                position: relative;
                z-index: 9999;
                backdrop-filter: blur(10px);
            }
            .canvas-dump-type {
                font-weight: 500;
                opacity: 0.8;
                font-size: 12px;
                background: rgba(108, 117, 125, 0.2);
                padding: 2px 6px;
                border-radius: 4px;
                margin-left: 8px;
            }
            .canvas-dump-expandable {
                cursor: pointer;
                user-select: none;
                display: inline-block;
                padding: 2px 4px;
                border-radius: 6px;
                transition: all 0.2s ease;
                margin: 0;
            }
            .canvas-dump-expandable:hover {
                background: rgba(255, 255, 255, 0.1);
                transform: translateY(-1px);
            }
            .canvas-dump-toggle {
                display: inline-block;
                width: 16px;
                height: 16px;
                line-height: 14px;
                text-align: center;
                background: linear-gradient(135deg, #007acc 0%, #0096ff 100%);
                color: white;
                border-radius: 50%;
                font-size: 11px;
                font-weight: bold;
                margin-right: 8px;
                cursor: pointer;
                transition: all 0.2s ease;
                box-shadow: 0 2px 8px rgba(0, 150, 255, 0.3);
            }
            .canvas-dump-toggle:hover {
                transform: scale(1.1);
                box-shadow: 0 4px 12px rgba(0, 150, 255, 0.5);
            }
            .canvas-dump-content {
                margin-left: 16px;
                border-left: 2px solid rgba(255, 255, 255, 0.1);
                padding-left: 12px;
                margin-top: 0;
            }
            .canvas-dump-collapsed .canvas-dump-content {
                display: none;
            }
            .canvas-dump-key {
                font-weight: 600;
                text-shadow: 0 0 10px rgba(3, 47, 98, 0.5);
            }
            .canvas-dump-private {
                opacity: 0.7;
                font-style: italic;
            }
            .canvas-dump-protected {
                opacity: 0.85;
            }
            .canvas-dump-length {
                color: #adb5bd;
                font-style: italic;
                font-size: 12px;
                background: rgba(173, 181, 189, 0.1);
                padding: 1px 4px;
                border-radius: 3px;
                margin-left: 6px;
            }
            .canvas-dump-item {
                margin: 0;
                line-height: 1.2;
            }
            
            .canvas-dump-line {
                display: block;
                margin: 0;
                padding: 0;
                line-height: 1.3;
            }
            
            /* Enhanced syntax highlighting */
            .canvas-dump [style*="#d14"] { /* strings */
                color: #98d982 !important;
                font-weight: 500;
            }
            .canvas-dump [style*="#005cc5"] { /* numbers/methods */
                color: #79c0ff !important;
                font-weight: 500;
            }
            .canvas-dump [style*="#6f42c1"] { /* booleans/properties */
                color: #d2a8ff !important;
                font-weight: 500;
            }
            .canvas-dump [style*="#6a737d"] { /* null */
                color: #8b949e !important;
                font-style: italic;
            }
            .canvas-dump [style*="#e36209"] { /* arrays */
                color: #ffa657 !important;
                font-weight: 600;
            }
            .canvas-dump [style*="#28a745"] { /* objects */
                color: #7ee787 !important;
                font-weight: 600;
            }
            .canvas-dump [style*="#fd7e14"] { /* resources */
                color: #ffab70 !important;
            }
            .canvas-dump [style*="#032f62"] { /* keys */
                color: #79c0ff !important;
                font-weight: 600;
            }
        </style>';
		}
		
		/**
		 * Generate JavaScript for interactive functionality
		 * Provides the toggle function for collapsible arrays and objects.
		 * Switches between expanded (-) and collapsed (+) states.
		 * @return string JavaScript code
		 */
		private static function getJavaScript(): string {
			return '<script>
            function toggleCanvasDump(id) {
                const element = document.getElementById("canvas-dump-" + id);
                
                if (!element) {
                    return;
                }
                
                const toggle = element.querySelector(".canvas-dump-toggle");
                
                if (!toggle) {
                    return;
                }
                
                if (element.classList.contains("canvas-dump-collapsed")) {
                    element.classList.remove("canvas-dump-collapsed");
                    toggle.textContent = "−";
                } else {
                    element.classList.add("canvas-dump-collapsed");
                    toggle.textContent = "+";
                }
            }
        </script>';
		}
		
		/**
		 * Render a single value with appropriate formatting
		 * Main rendering dispatcher that handles all PHP data types.
		 * Applies proper indentation, syntax highlighting, and type-specific formatting.
		 * @param mixed $value The value to render
		 * @param string|null $key Optional key name for array elements or object properties
		 */
		private static function renderValue($value, $key = null) {
			// Apply current indentation level
			$indent = str_repeat('  ', self::$depth);
			
			// Render key if this is part of an array or object
			if ($key !== null) {
				echo '<div class="canvas-dump-line">' . $indent . '<span class="canvas-dump-key" style="color: ' . self::$colors['key'] . '">"' . htmlspecialchars($key) . '"</span> => ';
			} else {
				echo '<div class="canvas-dump-line">' . $indent;
			}
			
			// Determine PHP data type and render accordingly
			$type = gettype($value);
			
			switch ($type) {
				case 'string':
					// Strings: show content with length indicator
					echo '<span style="color: ' . self::$colors['string'] . '">"' . htmlspecialchars($value) . '"</span>';
					echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')</span>';
					break;
				
				case 'integer':
				case 'double':
					// Numeric values: simple colored output
					echo '<span style="color: ' . self::$colors[$type === 'double' ? 'float' : 'integer'] . '">' . $value . '</span>';
					break;
				
				case 'boolean':
					// Booleans: show as true/false text
					echo '<span style="color: ' . self::$colors['boolean'] . '">' . ($value ? 'true' : 'false') . '</span>';
					break;
				
				case 'NULL':
					// Null values: simple gray text
					echo '<span style="color: ' . self::$colors['null'] . '">null</span>';
					break;
				
				case 'array':
					// Arrays: delegate to specialized array renderer
					echo '</div>'; // Close the current line
					self::renderArray($value);
					return; // Don't close div again
				
				case 'object':
					// Objects: delegate to specialized object renderer
					echo '</div>'; // Close the current line
					self::renderObject($value);
					return; // Don't close div again
				
				case 'resource':
					// Resources: show type information
					echo '<span style="color: ' . self::$colors['resource'] . '">resource(' . get_resource_type($value) . ')</span>';
					break;
				
				default:
					// Unknown types: fallback display
					echo '<span style="color: ' . self::$colors['null'] . '">' . $type . '</span>';
			}
			
			echo '</div>'; // Close the line div
		}
		
		/**
		 * Render arrays with collapsible interface
		 * Creates an interactive HTML structure for arrays that can be expanded/collapsed.
		 * Shows element count and provides drill-down capability while respecting
		 * maximum depth limits to prevent infinite recursion.
		 * @param array $array The array to render
		 */
		private static function renderArray($array) {
			$count = count($array);
			$id = ++self::$uniqueId; // Generate unique ID for this array instance
			
			// Handle empty arrays as simple inline display
			if ($count === 0) {
				echo '<span style="color: ' . self::$colors['array'] . '">array:0</span> []';
				return;
			}
			
			// Create collapsible container with unique ID
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>'; // Start expanded
			echo '<span style="color: ' . self::$colors['array'] . '">array:' . $count . '</span> [';
			echo '</span>';
			
			// Render array contents if we haven't exceeded maximum depth
			echo '<div class="canvas-dump-content">';
			
			if (self::$depth < self::$maxDepth) {
				self::$depth++; // Increase nesting level
				
				// Render each array element
				foreach ($array as $key => $value) {
					self::renderValue($value, $key);
				}
				
				self::$depth--; // Restore previous nesting level
			} else {
				// Maximum depth reached - show truncation indicator
				echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth + 1) . '<span style="color: ' . self::$colors['null'] . '">...</span></div>';
			}
			
			echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth) . ']</div>';
			echo '</div>';
			echo '</div>';
		}
		
		/**
		 * Render objects with reflection-based property inspection
		 * Uses PHP's Reflection API to examine object properties including private
		 * and protected members. Creates collapsible interface showing class name,
		 * object hash, and all properties with visibility indicators.
		 * @param object $object The object to render
		 */
		private static function renderObject(object $object): void {
			$className = get_class($object);
			$hash = spl_object_hash($object); // Unique object identifier
			$id = ++self::$uniqueId; // Generate unique ID for this object instance
			
			// Create collapsible container
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>'; // Start expanded
			echo '<span style="color: ' . self::$colors['object'] . '">' . $className . '</span> {#' . substr($hash, -4); // Show last 4 chars of hash
			
			// Add special handling for common objects to show their string representation
			$objectValue = '';
			
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				$objectValue = ' "' . $object->format('Y-m-d H:i:s T') . '"';
			} elseif (method_exists($object, '__toString')) {
				try {
					$stringValue = (string)$object;
					if (strlen($stringValue) <= 100) { // Only show if reasonably short
						$objectValue = ' "' . htmlspecialchars($stringValue) . '"';
					}
				} catch (\Exception $e) {
					// Ignore if __toString() throws
				}
			}
			
			echo '<span style="color: ' . self::$colors['string'] . '">' . $objectValue . '</span>';
			echo '</span>';
			
			// Render object contents if we haven't exceeded maximum depth
			if (self::$depth < self::$maxDepth) {
				echo '<div class="canvas-dump-content">';
				self::$depth++; // Increase nesting level
				
				// Use reflection to examine all properties (public, protected, private)
				$reflection = new \ReflectionClass($object);
				$properties = $reflection->getProperties();
				
				foreach ($properties as $property) {
					$property->setAccessible(true); // Allow access to private/protected properties
					
					// Safely get property value (may throw exception for uninitialized properties)
					try {
						$value = $property->getValue($object);
					} catch (\Exception $e) {
						$value = '*** uninitialized ***';
					}
					
					// Determine property visibility and styling
					$visibility = $property->isPublic() ? '+' : ($property->isProtected() ? '#' : '-');
					$visibilityClass = $property->isPublic() ? '' : ($property->isProtected() ? 'canvas-dump-protected' : 'canvas-dump-private');
					
					// Render property name with visibility indicator
					echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth) . '<span class="' . $visibilityClass . '" style="color: ' . self::$colors['property'] . '">' . $visibility . $property->getName() . '</span>: ';
					
					// Render property value
					if ($value === '*** uninitialized ***') {
						echo '<span style="color: ' . self::$colors['null'] . '">*** uninitialized ***</span></div>';
					} else {
						// For simple values, render inline and close the div
						$type = gettype($value);
						if ($type === 'string') {
							echo '<span style="color: ' . self::$colors['string'] . '">"' . htmlspecialchars($value) . '"</span>';
							echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')</span></div>';
						} elseif ($type === 'integer' || $type === 'double') {
							echo '<span style="color: ' . self::$colors[$type === 'double' ? 'float' : 'integer'] . '">' . $value . '</span></div>';
						} elseif ($type === 'boolean') {
							echo '<span style="color: ' . self::$colors['boolean'] . '">' . ($value ? 'true' : 'false') . '</span></div>';
						} elseif ($type === 'NULL') {
							echo '<span style="color: ' . self::$colors['null'] . '">null</span></div>';
						} else {
							// For complex values (arrays/objects), close current div and render separately
							echo '</div>';
							self::renderValue($value);
						}
					}
				}
				
				self::$depth--; // Restore previous nesting level
				echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth) . '}</div>';
				echo '</div>';
			} else {
				// Maximum depth reached - show truncation indicator
				echo '<div class="canvas-dump-content">';
				echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth + 1) . '<span style="color: ' . self::$colors['null'] . '">...</span></div>';
				echo '<div class="canvas-dump-line">' . str_repeat('  ', self::$depth) . '}</div>';
				echo '</div>';
			}
			
			echo '</div>';
		}
		
		/**
		 * Handle CLI output with ANSI color codes
		 * @param array $vars Variables to dump
		 */
		private static function dumpCli($vars) {
			foreach ($vars as $var) {
				self::renderValueCli($var);
				echo "\n";
			}
		}
		
		/**
		 * Render a single value for CLI output
		 * @param mixed $value The value to render
		 * @param string|null $key Optional key name for array elements or object properties
		 */
		private static function renderValueCli($value, $key = null) {
			// Apply current indentation level
			$indent = str_repeat('  ', self::$depth);
			
			// Render key with ANSI blue color if this is part of an array or object
			if ($key !== null) {
				echo $indent . "\033[34m\"" . $key . "\"\033[0m => ";
			} else {
				echo $indent;
			}
			
			// Determine PHP data type and render with appropriate ANSI colors
			$type = gettype($value);
			
			switch ($type) {
				case 'string':
					// Red for strings with length indicator
					echo "\033[31m\"" . $value . "\"\033[0m (" . strlen($value) . ")";
					break;
				
				case 'integer':
				case 'double':
					// Cyan for numeric values
					echo "\033[36m" . $value . "\033[0m";
					break;
				
				case 'boolean':
					// Magenta for booleans
					echo "\033[35m" . ($value ? 'true' : 'false') . "\033[0m";
					break;
				
				case 'NULL':
					// Dark gray for null values
					echo "\033[90mnull\033[0m";
					break;
				
				case 'array':
					// Delegate to CLI array renderer
					self::renderArrayCli($value);
					return; // Early return since array renderer handles newlines
				
				case 'object':
					// Delegate to CLI object renderer
					self::renderObjectCli($value);
					return; // Early return since object renderer handles newlines
				
				default:
					// Plain text for unknown types
					echo $type;
			}
			
			// Add newline for top-level values
			if ($key === null) {
				echo "\n";
			}
		}
		
		/**
		 * Render arrays for CLI output
		 * @param array $array The array to render
		 */
		private static function renderArrayCli($array) {
			$count = count($array);
			// Yellow for array type indicator
			echo "\033[33marray:" . $count . "\033[0m [\n";
			
			// Render array contents if not empty and within depth limits
			if (self::$depth < self::$maxDepth && $count > 0) {
				self::$depth++; // Increase nesting level
				
				foreach ($array as $key => $value) {
					self::renderValueCli($value, $key);
					echo "\n";
				}
				
				self::$depth--; // Restore previous nesting level
			}
			
			echo str_repeat('  ', self::$depth) . "]";
		}
		
		/**
		 * Render objects for CLI output
		 * @param object $object The object to render
		 */
		private static function renderObjectCli($object) {
			$className = get_class($object);
			$hash = spl_object_hash($object);
			
			// Green for class name with object hash
			echo "\033[32m" . $className . "\033[0m {#" . substr($hash, -4) . "\n";
			
			// Render object contents if within depth limits
			if (self::$depth < self::$maxDepth) {
				self::$depth++; // Increase nesting level
				
				// Use reflection to examine all properties
				$reflection = new \ReflectionClass($object);
				$properties = $reflection->getProperties();
				
				foreach ($properties as $property) {
					$property->setAccessible(true); // Allow access to private/protected properties
					
					// Safely get property value
					try {
						$value = $property->getValue($object);
					} catch (\Exception $e) {
						$value = '*** uninitialized ***';
					}
					
					// Determine property visibility symbol
					$visibility = $property->isPublic() ? '+' : ($property->isProtected() ? '#' : '-');
					
					// Render property name with magenta color and visibility indicator
					echo str_repeat('  ', self::$depth) . "\033[35m" . $visibility . $property->getName() . "\033[0m: ";
					
					// Render property value
					if ($value === '*** uninitialized ***') {
						echo "\033[90m*** uninitialized ***\033[0m";
					} else {
						self::renderValueCli($value);
					}
					echo "\n";
				}
				
				self::$depth--; // Restore previous nesting level
			}
			
			echo str_repeat('  ', self::$depth) . "}";
		}
	}