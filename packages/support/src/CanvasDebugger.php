<?php
	
	namespace Quellabs\Support;
	
	use Quellabs\Support\Debugger\Renderers\RendererFactory;
	
	/**
	 * CanvasDebugger - A sophisticated debugging utility for PHP
	 *
	 * Provides enhanced variable dumping with collapsible HTML output for web contexts
	 * and colored terminal output for CLI environments. Offers better visualization
	 * than standard var_dump() with syntax highlighting and interactive features.
	 */
	class CanvasDebugger {
		
		/**
		 * This method outputs variable information in a formatted way depending on the
		 * execution environment (web browser vs command line). For web contexts, it
		 * provides rich HTML output with styling, while CLI contexts get colored terminal output.
		 * @param mixed ...$vars Variables to dump - accepts any number of variables of any type
		 * @return void
		 */
		public static function dump(...$vars): void {
			// Capture call location IMMEDIATELY, before any other processing
			$callLocation = self::getCallLocation();
			
			// Check if we're in a web context (not command line interface)
			// php_sapi_name() returns 'cli' when running from command line
			if (php_sapi_name() !== 'cli') {
				// If headers already sent, we can't use fancy HTML - fall back to simple output
				// headers_sent() returns true if HTTP headers have already been sent to the browser
				// This prevents "Cannot modify header information" errors
				if (headers_sent()) {
					// Output a simple styled pre block since we can't send custom headers
					echo '<pre style="background:#f8f9fa;padding:10px;border:1px solid #ddd;margin:10px 0;">';
					
					// Loop through each variable and dump it using PHP's native var_dump
					foreach ($vars as $var) {
						var_dump($var);
					}
					
					echo '</pre>';
					return; // Exit early since we've handled the output
				}
				
				// Ensure we have output buffering for clean HTML output
				// ob_get_level() returns the nesting level of output buffering
				// Starting output buffering allows us to capture and manipulate output before sending to browser
				if (ob_get_level() === 0) {
					ob_start();
				}
			}
			
			// Route to appropriate renderer based on environment
			// Use specialized renderers that provide enhanced formatting for each context
			$renderer = RendererFactory::create(php_sapi_name());
			$renderer->setCallLocation($callLocation);
			$renderer->render($vars);
		}
		
		/**
		 * This is equivalent to dump() + die() but with better error handling.
		 * @param mixed ...$vars Variables to dump before dying - accepts any number of variables
		 * @return void This method never returns as it terminates execution
		 */
		public static function dumpAndDie(...$vars): void {
			// Capture call location IMMEDIATELY
			$callLocation = self::getCallLocation();
			
			try {
				if (php_sapi_name() !== 'cli') {
					while (ob_get_level()) {
						ob_end_clean();
					}
					ob_start();
				}
				
				$renderer = RendererFactory::create(php_sapi_name());
				$renderer->setCallLocation($callLocation);
				$renderer->render($vars);
				
				if (php_sapi_name() !== 'cli') {
					ob_end_flush();
				}
				
			} catch (\Throwable $e) {
				echo '<h3>Canvas Debug Error - Falling back to simple output:</h3>';
				echo '<pre>';
				
				foreach ($vars as $var) {
					var_dump($var);
				}
				
				echo '</pre>';
				echo '<p>Original error: ' . $e->getMessage() . '</p>';
			}
			
			die();
		}
		
		/**
		 * Get stack trace information for where dump was called
		 * THIS IS CALLED FROM THE ENTRY POINT, not from renderers
		 * @return array Stack trace info with file, line, function details
		 */
		private static function getCallLocation(): array {
			// Get the full stack trace without arguments to save memory
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			
			// Find the d() call and then look at what called it
			for ($i = 0; $i < count($trace); $i++) {
				$frame = $trace[$i];
				
				// Look for the d() or dd() function call in the current frame
				if (isset($frame['function']) && in_array($frame['function'], ['d', 'dd'])) {
					// The frame that shows d() contains the file/line where d() was called
					if (isset($frame['file']) && isset($frame['line'])) {
						// But we need to get the class/method context from the next frame
						// The next frame shows what method/class contained the d() call
						$contextFrame = $trace[$i + 1] ?? null;
						
						return [
							'file'     => $frame['file'],           // File where d() was called
							'line'     => $frame['line'],           // Line where d() was called
							'function' => $contextFrame['function'] ?? 'unknown',  // Method that contains the d() call
							'class'    => $contextFrame['class'] ?? null,            // Class that contains the d() call
							'type'     => $contextFrame['type'] ?? null              // Call type (-> or ::)
						];
					}
				}
			}
			
			// Fallback: skip our internal debugger stuff and find the first external caller
			foreach ($trace as $frame) {
				// Skip frames without file/line info (internal PHP functions)
				if (!isset($frame['file']) || !isset($frame['line'])) {
					continue;
				}
				
				// Skip frames from our own debugger classes to avoid showing internal calls
				if (isset($frame['class'])) {
					if (
						$frame['class'] === 'Quellabs\\Support\\CanvasDebugger' ||
						str_starts_with($frame['class'], 'Quellabs\\Support\\Debugger\\')
					) {
						continue; // Skip this frame, it's part of our debugger
					}
				}
				
				// Skip the d() and dd() helper functions themselves
				if (isset($frame['function']) && in_array($frame['function'], ['d', 'dd'])) {
					continue;
				}
				
				// This is the first external frame - return it
				return [
					'file'     => $frame['file'],
					'line'     => $frame['line'],
					'function' => $frame['function'] ?? 'unknown',
					'class'    => $frame['class'] ?? null,
					'type'     => $frame['type'] ?? null
				];
			}
			
			// Ultimate fallback if we can't find any valid frame
			return [
				'file'     => 'unknown',
				'line'     => 0,
				'function' => 'unknown',
				'class'    => null,
				'type'     => null
			];
		}
	}