<?php
	
	
	namespace Quellabs\Sculpt\Console;
	
	/**
	 * Console Output Handler
	 *
	 * Provides formatted console output with ANSI color support, table rendering,
	 * and styled messages (success, warning, error). Automatically detects terminal
	 * capabilities and handles multibyte characters correctly.
	 */
	class ConsoleOutput implements \Quellabs\Contracts\IO\ConsoleOutput {
		
		/**
		 * @var resource The output stream (usually STDOUT)
		 */
		protected $output;
		
		/**
		 * ANSI color and style codes
		 */
		protected array $styles = [
			// Colors
			'black'      => "\033[30m",
			'red'        => "\033[31m",
			'green'      => "\033[32m",
			'yellow'     => "\033[33m",
			'blue'       => "\033[34m",
			'magenta'    => "\033[35m",
			'cyan'       => "\033[36m",
			'white'      => "\033[37m",
			
			// Background colors
			'bg_black'   => "\033[40m",
			'bg_red'     => "\033[41m",
			'bg_green'   => "\033[42m",
			'bg_yellow'  => "\033[43m",
			'bg_blue'    => "\033[44m",
			'bg_magenta' => "\033[45m",
			'bg_cyan'    => "\033[46m",
			'bg_white'   => "\033[47m",
			
			// Formatting
			'bold'       => "\033[1m",
			'dim'        => "\033[2m",
			'italic'     => "\033[3m",
			'underline'  => "\033[4m",
			'blink'      => "\033[5m",
			'reverse'    => "\033[7m",
			'hidden'     => "\033[8m",
			
			// Reset
			'reset'      => "\033[0m",
		];
		
		/**
		 * ConsoleOutput Constructor
		 * @param resource|null $output Output stream (defaults to STDOUT)
		 */
		public function __construct($output = null) {
			$this->output = $output ?? STDOUT;
		}
		
		/**
		 * Print a table
		 * @param array $headers
		 * @param array $rows
		 * @return void
		 */
		public function table(array $headers, array $rows): void {
			// Normalize headers to sequential array
			$headers = array_values($headers);
			
			// Calculate column widths using multibyte-aware strlen
			$widths = array_map(function($header) {
				return mb_strlen($header, 'UTF-8');
			}, $headers);
			
			foreach ($rows as $row) {
				// Normalize row to sequential array
				$row = array_values($row);
				
				foreach ($row as $key => $value) {
					if (!isset($widths[$key])) {
						$widths[$key] = 0;
					}
					$widths[$key] = max($widths[$key], mb_strlen((string)$value, 'UTF-8'));
				}
			}
			
			// Print headers
			$this->printRow($headers, $widths);
			$this->printSeparator($widths);
			
			// Print rows
			foreach ($rows as $row) {
				$this->printRow(array_values($row), $widths);
			}
		}
		
		/**
		 * Print table row
		 * @param array $row
		 * @param array $widths
		 * @return void
		 */
		public function printRow(array $row, array $widths): void {
			$cells = [];
			
			foreach ($widths as $index => $width) {
				$value = isset($row[$index]) ? (string)$row[$index] : '';
				$valueLength = mb_strlen($value, 'UTF-8');
				$padding = str_repeat(' ', max(0, $width - $valueLength));
				$cells[] = $value . $padding;
			}
			
			$this->write("| " . implode(" | ", $cells) . " |\n");
		}
		
		/**
		 * Print separator
		 * @param array $widths
		 * @return void
		 */
		public function printSeparator(array $widths): void {
			$separator = array_map(function ($width) {
				return str_repeat('-', $width);
			}, $widths);
			
			$this->write("+-" . implode("-+-", $separator) . "-+\n");
		}
		
		/**
		 * Output text
		 * @param string $message
		 * @return void
		 */
		public function write(string $message): void {
			fwrite($this->output, $this->format($message));
		}
		
		/**
		 * Output text + newline
		 * @param string $message
		 * @return void
		 */
		public function writeLn(string $message): void {
			fwrite($this->output, $this->format($message) . "\n");
		}
		
		/**
		 * Display a success message
		 * @param string $message
		 * @return void
		 */
		public function success(string $message): void {
			// Use ASCII alternative that works everywhere instead of Unicode checkmark
			$prefix = "<bg_green><white> SUCCESS:</white></bg_green> ";
			$this->writeLn($prefix . "<green>{$message}</green>");
		}
		
		/**
		 * Display a warning message
		 * @param string $message
		 * @return void
		 */
		public function warning(string $message): void {
			// Use ASCII alternative that works everywhere instead of Unicode warning symbol
			$prefix = "<yellow>! WARNING:</yellow> ";
			$this->writeLn($prefix . $message);
		}
		
		/**
		 * Display an error message
		 * @param string $message
		 * @return void
		 */
		public function error(string $message): void {
			// Use ASCII alternative that works everywhere instead of Unicode error symbol
			$prefix = "<bg_red><white> ERROR:</white></bg_red> ";
			$this->writeLn($prefix . "<red>{$message}</red>");
		}
		
		/**
		 * Detect if the console supports colors
		 * @return bool
		 */
		protected function supportsColors(): bool {
			// Check if output is not a TTY (e.g., redirected to file)
			if (function_exists('stream_isatty') && !@stream_isatty($this->output)) {
				return false;
			}
			
			// Windows detection - modern Windows 10+ supports ANSI
			if (DIRECTORY_SEPARATOR === '\\') {
				// Windows 10+ with VT100 support
				$version = php_uname('v');
				if (preg_match('/build (\d+)/', $version, $matches) && (int)$matches[1] >= 10586) {
					return true;
				}
				
				// Legacy Windows terminal emulators
				return false !== getenv('ANSICON')
					|| 'ON' === getenv('ConEmuANSI')
					|| 'xterm' === getenv('TERM')
					|| 'Hyper' === getenv('TERM_PROGRAM')
					|| false !== getenv('WT_SESSION'); // Windows Terminal
			}
			
			// Unix/Linux/macOS detection
			if (function_exists('posix_isatty')) {
				return @posix_isatty($this->output);
			}
			
			// Fallback: check TERM environment variable
			$term = getenv('TERM');
			return $term && $term !== 'dumb';
		}
		
		/**
		 * Format a string by replacing style tags with ANSI codes
		 * @param string $text Text with style tags
		 * @return string Formatted text with ANSI codes
		 */
		protected function format(string $text): string {
			// Skip formatting if colors are not supported
			if (!$this->supportsColors()) {
				return preg_replace('/<[^>]+>/', '', $text);
			}
			
			// Replace opening style tags with ANSI codes
			foreach ($this->styles as $style => $code) {
				$text = str_replace("<{$style}>", $code, $text);
			}
			
			// Replace all closing tags with reset code
			return preg_replace('/<\/[^>]+>/', $this->styles['reset'], $text);
		}
	}