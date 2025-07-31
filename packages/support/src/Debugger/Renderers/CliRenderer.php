<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * CLI renderer for terminal output with ANSI colors - Now properly utilizing BaseRenderer
	 */
	class CliRenderer extends BaseRenderer {
		
		/**
		 * Render multiple variables for CLI output
		 * @param array $vars Variables to render
		 */
		public function render(array $vars): void {
			// Initialize type renderers - maps PHP data types to their corresponding render methods
			// This allows for polymorphic rendering based on variable type
			$this->typeRenderers = [
				'string' => [$this, 'renderString'],    // Handle string values
				'integer' => [$this, 'renderInteger'],  // Handle integer values
				'double' => [$this, 'renderFloat'],     // Handle float/double values (PHP uses 'double' internally)
				'boolean' => [$this, 'renderBoolean'],  // Handle boolean true/false values
				'NULL' => [$this, 'renderNull'],        // Handle null values
				'array' => [$this, 'renderArray'],      // Handle array structures
				'object' => [$this, 'renderObject'],    // Handle object instances
				'resource' => [$this, 'renderResource'], // Handle resource types (file handles, etc.)
			];
			
			// Process each variable in the input array
			foreach ($vars as $var) {
				// Render the current variable using the appropriate type renderer
				$this->renderValue($var);
				
				// Add double newline for visual separation between variables in CLI output
				echo "\n\n";
				
				// Clear the processed objects cache to prevent memory leaks and allow
				// the same object to be rendered again in subsequent variables
				// This is particularly important for circular reference detection
				$this->processedObjects = [];
			}
		}
		
		/**
		 * Render string values with color coding, length display, and truncation handling
		 * @param string $value The string value to render
		 * @param string|null $key Optional key name (for array/object properties)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderString(string $value, ?string $key = null, array $context = []): void {
			// Display the key name if this string is part of an array or object property
			$this->renderKeyIfPresent($key);
			
			// Apply string truncation for display purposes (prevents extremely long strings from cluttering output)
			$truncated = $this->truncateString($value);
			
			// Track whether truncation occurred so we can inform the user
			$wasTruncated = $truncated !== $value;
			
			// Output the string value wrapped in quotes with color coding for better CLI visibility
			// The 'string' color scheme helps distinguish strings from other data types
			echo Colors::wrapAnsi('"' . $truncated . '"', 'string');
			
			// Display the original string length in parentheses - useful for debugging and data analysis
			echo ' (' . strlen($value) . ')';
			
			// If the string was truncated, add a visual indicator to let users know
			// the full content isn't being displayed
			if ($wasTruncated) {
				echo ' ' . Colors::wrapAnsi('[truncated]', 'null');
			}
			
			// Add a newline unless we're in inline rendering mode (e.g., within arrays or objects)
			// This maintains proper formatting while allowing compact display when needed
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render integer values with color coding for CLI output
		 * @param int $value The integer value to render
		 * @param string|null $key Optional key name (for array/object properties)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderInteger(int $value, ?string $key = null, array $context = []): void {
			// Display the key name if this integer is part of an array or object property
			$this->renderKeyIfPresent($key);
			
			// Cast integer to string and apply color coding specific to integer types
			// This helps users quickly distinguish integers from other numeric types (floats, strings containing numbers)
			echo Colors::wrapAnsi((string)$value, 'integer');
			
			// Add newline unless we're in inline rendering mode (e.g., within compact array display)
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render float values with color coding for CLI output
		 * @param float $value The float/double value to render
		 * @param string|null $key Optional key name (for array/object properties)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderFloat(float $value, ?string $key = null, array $context = []): void {
			// Display the key name if this float is part of an array or object property
			$this->renderKeyIfPresent($key);
			
			// Cast float to string and apply color coding specific to float types
			// Uses PHP's default float-to-string conversion which handles precision automatically
			// Color coding helps distinguish floats from integers and other numeric representations
			echo Colors::wrapAnsi((string)$value, 'float');
			
			// Add newline unless we're in inline rendering mode (maintains formatting consistency)
			$this->newLineIfNotInline($context);
		}
		/**
		 * Render boolean values with color coding for CLI output
		 * @param bool $value The boolean value to render (true or false)
		 * @param string|null $key Optional key name (for array/object properties)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderBoolean(bool $value, ?string $key = null, array $context = []): void {
			// Display the key name if this boolean is part of an array or object property
			$this->renderKeyIfPresent($key);
			
			// Convert boolean to its string representation ('true' or 'false') with color coding
			// Uses ternary operator to handle PHP's boolean-to-string conversion explicitly
			// Color coding helps distinguish booleans from strings that contain 'true'/'false'
			echo Colors::wrapAnsi($value ? 'true' : 'false', 'boolean');
			
			// Add newline unless we're in inline rendering mode (maintains consistent formatting)
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render null values with color coding for CLI output
		 * @param null $value The null value to render (always null by type hint)
		 * @param string|null $key Optional key name (for array/object properties)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderNull(null $value, ?string $key = null, array $context = []): void {
			// Display the key name if this null is part of an array or object property
			$this->renderKeyIfPresent($key);
			
			// Display 'null' with specific color coding to make null values easily identifiable
			// This is crucial for debugging as null values can be easily overlooked in plain text
			// Color coding distinguishes null from strings containing 'null'
			echo Colors::wrapAnsi('null', 'null');
			
			// Add newline unless we're in inline rendering mode (maintains formatting consistency)
			$this->newLineIfNotInline($context);
		}
		/**
		 * Render resource values with type information and color coding for CLI output
		 * @param resource $value The resource value to render (file handle, database connection, etc.)
		 * @param string|null $key Optional key name (for array/object properties)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderResource($value, ?string $key = null, array $context = []): void {
			// Display the key name if this resource is part of an array or object property
			$this->renderKeyIfPresent($key);
			
			// Format resource display as "resource(type)" where type indicates the resource kind
			// get_resource_type() returns the specific resource type (e.g., 'stream', 'curl', 'mysql link')
			// This provides valuable debugging information about what kind of resource is being handled
			// Examples: "resource(stream)" for file handles, "resource(curl)" for cURL handles
			echo Colors::wrapAnsi('resource(' . get_resource_type($value) . ')', 'resource');
			
			// Add newline unless we're in inline rendering mode (maintains consistent formatting)
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render arrays for CLI output with proper formatting, indentation, and truncation handling
		 * @param array $value The array to render
		 * @param string|null $key Optional key name (for nested arrays/object properties)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderArray(array $value, ?string $key = null, array $context = []): void {
			// Get the total number of elements in the array for display and truncation logic
			$count = count($value);
			
			// Display the key name if this array is part of a parent structure
			$this->renderKeyIfPresent($key);
			
			// Handle empty arrays with a compact representation
			if ($count === 0) {
				echo Colors::wrapAnsi('array:0', 'array') . ' []';
				$this->newLineIfNotInline($context);
				return;
			}
			
			// Determine if the array should be truncated to prevent overwhelming output
			// Large arrays can make CLI output difficult to read and consume excessive memory
			$shouldTruncate = $this->shouldTruncateArray($value);
			
			// Get either the full array or a truncated version for display
			$displayArray = $shouldTruncate ? $this->getTruncatedArray($value) : $value;
			$displayCount = count($displayArray);
			
			// Display array header with total count and color coding
			echo Colors::wrapAnsi('array:' . $count, 'array');
			
			// If truncated, show how many elements are being displayed vs. total
			if ($shouldTruncate) {
				echo ' ' . Colors::wrapAnsi('(showing ' . $displayCount . ')', 'null');
			}
			
			// Begin array structure with opening bracket
			echo " [\n";
			
			// Increase indentation depth for nested structure visualization
			$this->increaseDepth();
			
			// Iterate through the display array and render each element
			foreach ($displayArray as $arrayKey => $arrayValue) {
				// Add proper indentation for current depth level
				echo $this->getIndent();
				
				// Render each array element with its key, using non-inline mode for proper formatting
				// The key will be displayed as "key => value" for associative arrays
				$this->renderValue($arrayValue, $arrayKey, ['inline' => false]);
			}
			
			// If the array was truncated, show an indicator of remaining elements
			if ($shouldTruncate) {
				echo $this->getIndent();
				echo Colors::wrapAnsi('... and ' . ($count - $displayCount) . ' more elements', 'null');
				echo "\n";
			}
			
			// Restore previous indentation depth and close the array structure
			$this->decreaseDepth();
			echo $this->getIndent() . ']';
			
			// Add newline unless we're in inline rendering mode
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render objects for CLI output with class info, properties, and circular reference handling
		 * @param object $value The object instance to render
		 * @param string|null $key Optional key name (for nested objects/array elements)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderObject(object $value, ?string $key = null, array $context = []): void {
			// Get the fully qualified class name for identification
			$className = get_class($value);
			
			// Get unique object ID for distinguishing between different instances of the same class
			// This is crucial for debugging and circular reference detection
			$objectId = spl_object_id($value);
			
			// Display the key name if this object is part of a parent structure
			$this->renderKeyIfPresent($key);
			
			// Display object header with class name and unique ID
			// Format: "ClassName {#12345" where 12345 is the unique object ID
			echo Colors::wrapAnsi($className, 'object') . ' {#' . $objectId;
			
			// Attempt to get a meaningful string representation of the object
			// This could be from __toString(), __debugInfo(), or other methods
			$stringRepresentation = $this->getObjectStringRepresentation($value);
			
			// Display the string representation if available (helps with object identification)
			if ($stringRepresentation) {
				echo ' ' . Colors::wrapAnsi($stringRepresentation, 'string');
			}
			
			// Close the object header and begin property listing
			echo "\n";
			
			// Increase indentation depth for nested property visualization
			$this->increaseDepth();
			
			// Get all object properties (public, protected, private) using reflection
			$properties = $this->getObjectProperties($value);
			
			// Iterate through each property and render its value
			foreach ($properties as $property) {
				// Get the actual property value (handles private/protected access)
				$propertyValue = $this->getPropertyValue($property, $value);
				
				// Get visibility symbol (+ for public, # for protected, - for private)
				$visibility = $this->getVisibilitySymbol($property);
				
				// Add proper indentation for current depth level
				echo $this->getIndent();
				
				// Display property name with visibility indicator and color coding
				echo Colors::wrapAnsi($visibility . $property->getName(), 'property') . ': ';
				
				// Handle special cases like uninitialized properties or access errors
				// Properties starting with '***' indicate special states (uninitialized, inaccessible, etc.)
				if (is_string($propertyValue) && str_starts_with($propertyValue, '***')) {
					echo Colors::wrapAnsi($propertyValue, 'null');
					echo "\n";
				} else {
					// Render the property value inline (same line as property name)
					$this->renderValue($propertyValue, null, ['inline' => true]);
				}
			}
			
			// Restore previous indentation depth and close the object structure
			$this->decreaseDepth();
			echo $this->getIndent() . '}';
			
			// Add newline unless we're in inline rendering mode
			$this->newLineIfNotInline($context);
			
			// Clean up circular reference tracking for this object instance
			// This prevents memory leaks and allows the same object to be rendered again elsewhere
			$this->removeFromCircularTracking($value);
		}
		
		/**
		 * Override circular reference rendering for CLI output with clear visual indicators
		 * Prevents infinite recursion when objects reference themselves or create reference cycles
		 * @param object $object The object that creates a circular reference
		 * @param string|null $key Optional key name (for nested objects/array elements)
		 */
		protected function renderCircularReference(object $object, string $key = null): void {
			// Display the key name if this circular reference is part of a parent structure
			$this->renderKeyIfPresent($key);
			
			// Get object identification information for debugging purposes
			$className = get_class($object);
			$objectId = spl_object_id($object);
			
			// Display prominent warning indicator to alert developers of circular reference
			// Uses 'null' color scheme to make it visually distinct and attention-grabbing
			echo Colors::wrapAnsi('*CIRCULAR REFERENCE* ', 'null');
			
			// Show which specific object instance is causing the circular reference
			// This helps developers identify the exact object in complex nested structures
			// Format matches normal object display for consistency: "ClassName {#12345}"
			echo Colors::wrapAnsi($className, 'object') . ' {#' . $objectId . '}';
			echo "\n";
		}
		
		/**
		 * Override max depth rendering for CLI output with clear visual indicator
		 * Prevents excessive memory usage and output when deeply nested structures are encountered
		 * @param string|null $key Optional key name (for nested structures)
		 */
		protected function renderMaxDepthIndicator(string $key = null): void {
			// Display the key name if this depth limit is reached within a parent structure
			$this->renderKeyIfPresent($key);
			
			// Display clear indicator that rendering was stopped due to depth limits
			// This prevents stack overflow and excessive output in deeply nested data structures
			// Uses 'null' color scheme to make it visually prominent and easily noticeable
			echo Colors::wrapAnsi('*MAX DEPTH REACHED*', 'null');
			echo "\n";
		}
		
		/**
		 * Override unknown type rendering for CLI output with fallback handling
		 * Handles edge cases where PHP introduces new types or unexpected data structures
		 * @param mixed $value The value of unknown/unsupported type
		 * @param string|null $key Optional key name (for nested structures)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function renderUnknownType(mixed $value, string $key = null, array $context = []): void {
			// Display the key name if this unknown type is part of a parent structure
			$this->renderKeyIfPresent($key);
			
			// Get the actual PHP type name for diagnostic purposes
			// This helps developers understand what unexpected type was encountered
			$type = gettype($value);
			
			// Display a clear indicator that an unsupported type was encountered
			// Format: "unknown(actual_type_name)" with distinctive coloring
			// This provides both a warning and diagnostic information
			echo Colors::wrapAnsi('unknown(' . $type . ')', 'null');
			
			// Add newline unless we're in inline rendering mode (maintains formatting consistency)
			$this->newLineIfNotInline($context);
		}

		/**
		 * Apply proper indentation for scalar values in block rendering mode
		 * This ensures consistent visual hierarchy in nested structures
		 * @param mixed $value The value about to be rendered
		 * @param string|null $key Optional key name (for nested structures)
		 * @param array $context Rendering context (inline mode, depth, etc.)
		 */
		protected function beforeRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Check if we're NOT in inline rendering mode (block mode needs indentation)
			// Uses null coalescing operator to safely handle missing 'inline' context key
			if (!($context['inline'] ?? false) && !in_array(gettype($value), ['array', 'object'])) {
				// Add indentation only for scalar types (string, int, float, boolean, null, resource)
				// Arrays and objects handle their own indentation internally to control their structure
				// This prevents double-indentation for complex types while ensuring scalar values align properly
				echo $this->getIndent();
			}
			
			// Note: Arrays and objects are excluded because:
			// - Arrays manage indentation for their opening bracket and each element
			// - Objects manage indentation for their opening brace and each property
			// - Adding indentation here would create incorrect double-indentation for these types
		}
		
		
		/**
		 * Helper: Render key if present with proper formatting and color coding
		 * Handles the display of array keys and object property names consistently across all renderers
		 * @param string|null $key The key/property name to display, or null if no key
		 */
		private function renderKeyIfPresent(?string $key): void {
			// Only render if a key is actually provided (not null)
			if ($key !== null) {
				// Format key as quoted string with arrow separator for key-value association
				// Uses 'key' color scheme to distinguish keys from values
				// Format: "key_name" => (matching PHP's var_dump and print_r style)
				echo Colors::wrapAnsi('"' . $key . '"', 'key') . ' => ';
			}
		}
		
		/**
		 * Helper: Add newline if not inline with context-aware formatting
		 * Controls line breaks based on rendering context to support both compact and expanded display modes
		 * @param array $context Rendering context containing formatting flags
		 */
		private function newLineIfNotInline(array $context): void {
			// Check if we're in inline rendering mode (used for compact array/object display)
			// If not inline, add a newline for proper block formatting
			// Uses null coalescing operator to default to false if 'inline' key doesn't exist
			if (!($context['inline'] ?? false)) {
				echo "\n";
			}
		}
		
		/**
		 * Get string representation of common objects for enhanced CLI display
		 * Provides meaningful string representations to help identify and understand object instances
		 * @param object $object The object instance to get a string representation for
		 * @return string The string representation, or empty string if none available
		 */
		private function getObjectStringRepresentation(object $object): string {
			// Special handling for DateTime objects - format them in a standardized, readable way
			// DateTime objects are commonly used and benefit from showing their actual date/time value
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				// Format: "2024-07-31 14:30:45 UTC" - includes date, time, and timezone
				// This format is both human-readable and unambiguous for debugging
				return '"' . $object->format('Y-m-d H:i:s T') . '"';
			}
			
			// Check if the object implements __toString() method for custom string representation
			// Many objects provide meaningful __toString() implementations (URLs, exceptions, value objects, etc.)
			if (method_exists($object, '__toString')) {
				try {
					// Attempt to get the string representation by casting to string
					// This triggers the __toString() method if it exists
					$stringValue = (string)$object;
					
					// Apply string truncation to prevent excessively long output in CLI
					// Long string representations can make the output difficult to read
					$truncated = $this->truncateString($stringValue);
					
					// Only return the representation if we got a meaningful result
					// Empty strings or whitespace-only results aren't helpful for identification
					if ($truncated) {
						// Wrap in quotes to clearly distinguish it as a string representation
						return '"' . $truncated . '"';
					}
				} catch (\Exception $e) {
					// Handle cases where __toString() throws an exception
					// Some objects may have buggy or context-dependent __toString() implementations
					// This provides a clear indicator that the method failed rather than silently failing
					return '"*toString() error*"';
				}
			}
			
			// Return empty string if no meaningful representation is available
			// This indicates that only the class name and object ID should be shown
			return '';
		}
	}