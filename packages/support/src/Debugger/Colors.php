<?php
	
	namespace Quellabs\Support\Debugger;
	
	/**
	 * Color configuration for syntax highlighting
	 */
	class Colors {
		
		/**
		 * Color scheme for syntax highlighting different data types (HTML/hex colors)
		 * @var array
		 */
		public const HTML_COLORS = [
			'string'   => '#d14',      // Red for strings
			'integer'  => '#005cc5',   // Blue for integers
			'float'    => '#005cc5',   // Blue for floats
			'boolean'  => '#6f42c1',   // Purple for booleans
			'null'     => '#6a737d',   // Gray for null values
			'array'    => '#e36209',   // Orange for arrays
			'object'   => '#28a745',   // Green for objects
			'resource' => '#fd7e14',   // Orange-red for resources
			'property' => '#6f42c1',   // Purple for object properties
			'method'   => '#005cc5',   // Blue for methods
			'key'      => '#032f62'    // Dark blue for array keys
		];
		
		/**
		 * ANSI color codes for CLI output
		 * @var array
		 */
		public const ANSI_COLORS = [
			'string'   => "\033[31m",  // Red
			'integer'  => "\033[36m",  // Cyan
			'float'    => "\033[36m",  // Cyan
			'boolean'  => "\033[35m",  // Magenta
			'null'     => "\033[90m",  // Dark gray
			'array'    => "\033[33m",  // Yellow
			'object'   => "\033[32m",  // Green
			'key'      => "\033[34m",  // Blue
			'property' => "\033[35m",  // Magenta
			'resource' => "\033[37m",  // White
			'method'   => "\033[36m",  // Cyan
			'reset'    => "\033[0m"    // Reset
		];
		
		/**
		 * Get HTML color for a specific type
		 * @param string $type The type to get color for
		 * @return string The hex color code
		 */
		public static function getHtml(string $type): string {
			return self::HTML_COLORS[$type] ?? self::HTML_COLORS['null'];
		}
		
		/**
		 * Get ANSI color code for a specific type
		 * @param string $type The type to get color for
		 * @return string The ANSI color code
		 */
		public static function getAnsi(string $type): string {
			return self::ANSI_COLORS[$type] ?? self::ANSI_COLORS['null'];
		}
		
		/**
		 * Get ANSI reset code
		 * @return string The ANSI reset code
		 */
		public static function getAnsiReset(): string {
			return self::ANSI_COLORS['reset'];
		}
		
		/**
		 * Wrap text with ANSI color codes
		 * @param string $text The text to colorize
		 * @param string $type The color type
		 * @return string Colorized text
		 */
		public static function wrapAnsi(string $text, string $type): string {
			return self::getAnsi($type) . $text . self::getAnsiReset();
		}
		
		/**
		 * Legacy method for backward compatibility
		 * @param string $type The type to get color for
		 * @return string The hex color code
		 * @deprecated Use getHtml() instead
		 */
		public static function get(string $type): string {
			return self::getHtml($type);
		}
	}