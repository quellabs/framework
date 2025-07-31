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
		private bool $stylesOutputted = false;
		
		/**
		 * Render multiple variables for HTML output
		 * @param array $vars Variables to render
		 */
		public function render(array $vars): void {
			// Initialize type renderers
			$this->typeRenderers = [
				'string'   => [$this, 'renderString'],
				'integer'  => [$this, 'renderInteger'],
				'double'   => [$this, 'renderFloat'],
				'boolean'  => [$this, 'renderBoolean'],
				'NULL'     => [$this, 'renderNull'],
				'array'    => [$this, 'renderArray'],
				'object'   => [$this, 'renderObject'],
				'resource' => [$this, 'renderResource'],
			];
			
			// Only output styles once per request
			if (!$this->stylesOutputted) {
				echo StyleSheet::get();
				echo JavaScript::get();
				$this->stylesOutputted = true;
			}
			
			// Get call location info
			$callLocation = $this->getCallLocation();
			
			// Render each variable in its own container
			foreach ($vars as $var) {
				echo '<div class="canvas-dump">';
				
				// Add call location header
				$this->renderCallLocation($callLocation);
				
				// Render value
				$this->renderValue($var);
				
				// Close div
				echo '</div>';
			}
		}
		
		/**
		 * Render string values with proper HTML formatting and metadata
		 * @param string $value The string value to render
		 * @param string|null $key Optional key name if this string is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderString(string $value, ?string $key = null, array $context = []): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Apply string truncation if the value exceeds maximum display length
			$truncated = $this->truncateString($value);
			
			// Track whether truncation occurred for display purposes
			$wasTruncated = $truncated !== $value;
			
			// Output the string value wrapped in a colored span with proper HTML escaping
			echo '<span style="color: ' . Colors::getHtml('string') . '">"' . $this->escapeHtml($truncated) . '"</span>';
			
			// Display metadata: string length and truncation status
			echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')';
			
			// Add truncation indicator if the string was shortened
			if ($wasTruncated) {
				echo ' truncated';
			}
			
			// Close the metadata span
			echo '</span>';
			
			// Add line break unless we're in inline rendering mode
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render integer values with proper HTML formatting and syntax highlighting
		 * @param int $value The integer value to display
		 * @param string|null $key Optional key name if this integer is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderInteger(int $value, ?string $key = null, array $context = []): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Output the integer value wrapped in a colored span for syntax highlighting
			// No HTML escaping needed since integers are safe to output directly
			echo '<span style="color: ' . Colors::getHtml('integer') . '">' . $value . '</span>';
			
			// Add line break unless we're in inline rendering mode
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render float values with proper HTML formatting and syntax highlighting
		 * @param float $value The floating-point number to display
		 * @param string|null $key Optional key name if this float is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderFloat(float $value, ?string $key = null, array $context = []): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Output the float value wrapped in a colored span for syntax highlighting
			// PHP will automatically format the float (e.g., 1.5, 3.14159, 2.0E+10)
			// No HTML escaping needed since floats are safe to output directly
			echo '<span style="color: ' . Colors::getHtml('float') . '">' . $value . '</span>';
			
			// Add line break unless we're in inline rendering mode
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render boolean values with proper HTML formatting and syntax highlighting
		 * @param bool $value The boolean value to display (true or false)
		 * @param string|null $key Optional key name if this boolean is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderBoolean(bool $value, ?string $key = null, array $context = []): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Convert boolean to string representation and wrap in colored span
			// Uses ternary operator to convert: true -> "true", false -> "false"
			// This provides explicit string representation instead of PHP's default 1/0 or 1/empty
			echo '<span style="color: ' . Colors::getHtml('boolean') . '">' . ($value ? 'true' : 'false') . '</span>';
			
			// Add line break unless we're in inline rendering mode
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render null values with proper HTML formatting and syntax highlighting
		 * @param null $value The null value (parameter is purely for consistency with other render methods)
		 * @param string|null $key Optional key name if this null is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderNull($value, ?string $key = null, array $context = []): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Output the literal string "null" wrapped in a colored span for syntax highlighting
			// The $value parameter is ignored since null always displays as "null"
			// Uses explicit string "null" instead of empty output for clear debugging visibility
			echo '<span style="color: ' . Colors::getHtml('null') . '">null</span>';
			
			// Add line break unless we're in inline rendering mode
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render resource values with proper HTML formatting and type information
		 * @param resource $value The resource handle to display (e.g., file handle, curl handle, etc.)
		 * @param string|null $key Optional key name if this resource is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderResource($value, ?string $key = null, array $context = []): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Output the resource in the format "resource(type)" with syntax highlighting
			// Uses get_resource_type() to show the specific resource type (e.g., "stream", "curl", "gd")
			// HTML escape the resource type in case it contains special characters
			echo '<span style="color: ' . Colors::getHtml('resource') . '">resource(' . $this->escapeHtml(get_resource_type($value)) . ')</span>';
			
			// Add line break unless we're in inline rendering mode
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render arrays with collapsible HTML interface and truncation support
		 * @param array $value The array to display with all its elements
		 * @param string|null $key Optional key name if this array is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderArray(array $value, ?string $key = null, array $context = []): void {
			// Get array size and generate unique ID for collapsible functionality
			$count = count($value);
			$id = $this->getNextId();
			
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Handle empty arrays with simple inline display
			if ($count === 0) {
				echo '<span style="color: ' . Colors::getHtml('array') . '">array:0</span> []';
				$this->closeLineIfNotInline($context);
				return;
			}
			
			// Determine if array should be truncated for performance/readability
			// Large arrays are truncated to prevent overwhelming output
			$shouldTruncate = $this->shouldTruncateArray($value);
			$displayArray = $shouldTruncate ? $this->getTruncatedArray($value) : $value;
			$displayCount = count($displayArray);
			
			// Create collapsible container with unique ID for JavaScript toggle functionality
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			
			// Create clickable header that shows/hides array contents
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>'; // Collapse/expand indicator (− = expanded)
			echo '<span style="color: ' . Colors::getHtml('array') . '">array:' . $count . '</span>';
			
			// Show truncation indicator if array was shortened
			if ($shouldTruncate) {
				echo ' <span class="canvas-dump-type">(showing ' . $displayCount . ')</span>';
			}
			
			echo ' [</span>'; // Opening bracket for array
			
			// Container for array contents (can be hidden/shown via JavaScript)
			echo '<div class="canvas-dump-content">';
			
			// Increase indentation level for nested array elements
			$this->increaseDepth();
			
			// Render each array element with proper indentation and formatting
			foreach ($displayArray as $arrayKey => $arrayValue) {
				echo '<div class="canvas-dump-line">' . $this->getIndent();
				// Recursively render each value, passing the array key and non-inline context
				$this->renderValue($arrayValue, $arrayKey, ['inline' => false]);
				echo '</div>';
			}
			
			// Show truncation message if elements were hidden
			if ($shouldTruncate) {
				echo '<div class="canvas-dump-line">' . $this->getIndent();
				echo '<span style="color: ' . Colors::getHtml('null') . '">... and ' . ($count - $displayCount) . ' more elements</span>';
				echo '</div>';
			}
			
			// Restore previous indentation level
			$this->decreaseDepth();
			
			// Close array with proper indentation and closing bracket
			echo '<div class="canvas-dump-line">' . $this->getIndent() . ']</div>';
			echo '</div></div>'; // Close content container and main container
		}
		
		/**
		 * Render objects with HTML formatting, property visibility, and collapsible interface
		 * @param object $value The object instance to display with all its properties
		 * @param string|null $key Optional key name if this object is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderObject(object $value, ?string $key = null, array $context = []): void {
			// Get object metadata for display
			$className = get_class($value);           // Full class name (e.g., "App\Models\User")
			$objectId = spl_object_id($value);        // Unique object ID for this instance
			$id = $this->getNextId();                 // Unique HTML ID for collapsible functionality
			
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Create collapsible container with unique ID for JavaScript toggle functionality
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			
			// Create clickable header that shows/hides object contents
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>'; // Collapse/expand indicator (− = expanded)
			// Display class name with syntax highlighting and object ID for identification
			echo '<span style="color: ' . Colors::getHtml('object') . '">' . $this->escapeHtml($className) . '</span> {#' . $objectId;
			
			// Add string representation if the object implements __toString() or similar
			// This provides a quick preview of the object's content (e.g., "John Doe" for a User object)
			$stringRepresentation = $this->getObjectStringRepresentation($value);
			
			if ($stringRepresentation) {
				echo '<span style="color: ' . Colors::getHtml('string') . '">' . $stringRepresentation . '</span>';
			}
			
			echo '</span>'; // Close the clickable header
			
			// Container for object properties (can be hidden/shown via JavaScript)
			echo '<div class="canvas-dump-content">';
			
			// Increase indentation level for nested object properties
			$this->increaseDepth();
			
			// Get all properties using reflection (includes private/protected properties)
			$properties = $this->getObjectProperties($value);
			
			// Render each property with proper visibility indicators and formatting
			foreach ($properties as $property) {
				// Get property value (handles private/protected access via reflection)
				$propertyValue = $this->getPropertyValue($property, $value);
				// Get visibility symbol: + for public, # for protected, - for private
				$visibility = $this->getVisibilitySymbol($property);
				
				// Determine CSS class based on property visibility for styling
				if ($property->isPublic()) {
					$visibilityClass = '';                    // No special styling for public
				} elseif ($property->isProtected()) {
					$visibilityClass = 'canvas-dump-protected'; // Different color for protected
				} else {
					$visibilityClass = 'canvas-dump-private';   // Different color for private
				}
				
				// Render property line with proper indentation
				echo '<div class="canvas-dump-line">' . $this->getIndent();
				// Display property name with visibility symbol and appropriate styling
				echo '<span class="' . $visibilityClass . '" style="color: ' . Colors::getHtml('property') . '">';
				echo $visibility . $this->escapeHtml($property->getName()) . '</span>: ';
				
				// Handle special case for circular reference or access restriction messages
				if (is_string($propertyValue) && str_starts_with($propertyValue, '***')) {
					// Display error/warning messages (e.g., "***CIRCULAR REFERENCE***") in null color
					echo '<span style="color: ' . Colors::getHtml('null') . '">' . $this->escapeHtml($propertyValue) . '</span>';
				} else {
					// Recursively render the property value in inline mode
					$this->renderValue($propertyValue, null, ['inline' => true]);
				}
				
				echo '</div>';
			}
			
			// Restore previous indentation level
			$this->decreaseDepth();
			
			// Close object with proper indentation and closing brace
			echo '<div class="canvas-dump-line">' . $this->getIndent() . '}</div>';
			echo '</div></div>'; // Close content container and main container
			
			// Clean up circular reference tracking to prevent memory leaks
			// This allows the same object to be rendered again in different contexts
			$this->removeFromCircularTracking($value);
		}
		
		/**
		 * Render circular reference indicator to prevent infinite recursion
		 * This method is called when an object that's already being rendered is encountered again
		 * @param object $object The object that creates a circular reference
		 * @param string|null $key Optional key name if this circular reference is part of a key-value pair
		 */
		protected function renderCircularReference(object $object, ?string $key = null): void {
			// Get object identification info to help developers locate the circular reference
			$className = get_class($object);      // Class name for context
			$objectId = spl_object_id($object);   // Unique object ID to match with original instance
			
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Display circular reference warning with object identification
			// Uses null color (typically gray/muted) to indicate this is a special/warning message
			echo '<span style="color: ' . Colors::getHtml('null') . '">*CIRCULAR REFERENCE* ';
			// Show class name and object ID to help match with the original object instance
			echo $this->escapeHtml($className) . ' {#' . $objectId . '}</span>';
			
			// Close the line container (assumes we're inside a div that needs closing)
			echo '</div>';
		}
		
		/**
		 * Render max depth indicator to prevent excessive nesting and stack overflow
		 * This method is called when the rendering depth exceeds the configured maximum
		 * @param string|null $key Optional key name if this depth limit is reached within a key-value pair
		 */
		protected function renderMaxDepthIndicator(?string $key = null): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Display depth limit warning message
			// Uses null color (typically gray/muted) to indicate this is a special/warning message
			echo '<span style="color: ' . Colors::getHtml('null') . '">*MAX DEPTH REACHED*</span>';
			
			// Close the line container (assumes we're inside a div that needs closing)
			echo '</div>';
		}
		
		/**
		 * Render fallback for unknown or unsupported data types
		 * This method handles edge cases where PHP introduces new types or unexpected values
		 * @param mixed $value The value of unknown/unsupported type to display
		 * @param string|null $key Optional key name if this unknown type is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function renderUnknownType(mixed $value, ?string $key = null, array $context = []): void {
			// Render the key name if one was provided (for associative arrays/objects)
			$this->renderKeyIfPresent($key);
			
			// Get the actual PHP type name for debugging purposes
			$type = gettype($value);
			
			// Display unknown type indicator with the actual type name
			// Uses null color to indicate this is an unusual/error condition
			// Format: "unknown(actual_type_name)" - helps developers identify the issue
			echo '<span style="color: ' . Colors::getHtml('null') . '">unknown(' . $this->escapeHtml($type) . ')</span>';
			
			// Add line break unless we're in inline rendering mode
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Override beforeRenderValue to handle HTML-specific setup and line container management
		 * This method is called before any value is rendered to set up proper HTML structure
		 * @param mixed $value The value about to be rendered (used for type checking)
		 * @param string|null $key Optional key name if this value is part of a key-value pair
		 * @param array $context Rendering context options (e.g., inline mode, depth level)
		 */
		protected function beforeRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Determine if we need to create a new line container div
			// Skip line div creation in two scenarios:
			// 1. Inline mode - compact display within other elements
			// 2. Complex types (arrays/objects) - they manage their own HTML structure
			if (!($context['inline'] ?? false) && !in_array(gettype($value), ['array', 'object'])) {
				// Create line container with proper indentation for simple values
				// This div will be closed by closeLineIfNotInline() after value rendering
				echo '<div class="canvas-dump-line">' . $this->getIndent();
			}
		}
		
		/**
		 * Render call location information in HTML
		 * @param array $location Call location details
		 */
		private function renderCallLocation(array $location): void {
			echo '<div class="canvas-dump-location">';
			echo '<span class="canvas-dump-location-icon">📍</span>';
			echo '<span class="canvas-dump-location-text">';
			echo $this->escapeHtml($this->formatCallLocation($location));
			echo '</span>';
			
			// Add clickable file link if it's a local file
			if ($location['file'] !== 'unknown' && file_exists($location['file'])) {
				echo '<span class="canvas-dump-location-path" title="' . $this->escapeHtml($location['file']) . '">';
				echo $this->escapeHtml($location['file']);
				echo '</span>';
			}
			
			echo '</div>';
		}
		
		/**
		 * Helper: Render key if present for associative arrays and object properties
		 * This method handles the display of array keys and object property names
		 * @param string|null $key The key/property name to display, or null if not applicable
		 */
		private function renderKeyIfPresent(?string $key): void {
			// Only render if a key was actually provided (not null)
			if ($key !== null) {
				// Wrap key in styled span with appropriate color coding
				echo '<span class="canvas-dump-key" style="color: ' . Colors::getHtml('key') . '">';
				
				// Display key in quotes with HTML escaping for safety
				// Format: "key_name" => (matches PHP array syntax)
				echo '"' . $this->escapeHtml($key) . '"</span> => ';
			}
		}
		
		/**
		 * Helper: Close line div if not in inline rendering mode
		 * This method manages HTML structure based on the rendering context
		 * @param array $context Rendering context containing display mode information
		 */
		private function closeLineIfNotInline(array $context): void {
			// Check if we're in inline mode (used for compact display within other elements)
			// If not inline, we need to close the line container div that was opened elsewhere
			if (!($context['inline'] ?? false)) {
				echo '</div>'; // Close the canvas-dump-line div
			}
		}
		
		/**
		 * Get string representation of common objects for quick preview in collapsed view
		 * This method extracts meaningful string previews from objects to show alongside class names
		 * @param object $object The object to extract string representation from
		 * @return string Formatted string representation, or empty string if none available
		 */
		private function getObjectStringRepresentation(object $object): string {
			// Special handling for DateTime objects - show formatted date/time
			// This is more useful than the default __toString() output for dates
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				// Format: "2024-01-15 14:30:45 UTC" - includes timezone for clarity
				return ' "' . $this->escapeHtml($object->format('Y-m-d H:i:s T')) . '"';
			}
			
			// Check if object has a __toString() method before attempting to use it
			// Not all objects implement __toString(), so we need to verify first
			if (!method_exists($object, '__toString')) {
				return ''; // No string representation available
			}
			
			// Attempt to get string representation with error handling
			try {
				// Cast object to string, which calls __toString() method
				$stringValue = (string)$object;
				
				// Apply truncation to prevent overly long previews in collapsed view
				// Long strings would make the collapsed header unwieldy
				$truncated = $this->truncateString($stringValue);
				
				// Return formatted string with quotes and HTML escaping
				return ' "' . $this->escapeHtml($truncated) . '"';
			} catch (\Exception $e) {
				// Handle cases where __toString() throws an exception
				// Some objects may have buggy or failing __toString() implementations
				return ' "*toString() error: ' . $this->escapeHtml($e->getMessage()) . '*"';
			}
		}
		
		/**
		 * Safely escape any string for HTML output to prevent XSS and display issues
		 * Centralized escaping method for consistency across all render methods
		 * @param string $value The string to escape for safe HTML output
		 * @return string HTML-safe version of the input string
		 */
		private function escapeHtml(string $value): string {
			// Use comprehensive HTML escaping with modern standards
			// ENT_QUOTES: Escape both single and double quotes
			// ENT_HTML5: Use HTML5 entity names for better compatibility
			// UTF-8: Handle Unicode characters properly
			return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
	}