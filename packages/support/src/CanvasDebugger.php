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
			RendererFactory::create(php_sapi_name())->render($vars);
		}
		
		/**
		 * This is equivalent to dump() + die() but with better error handling.
		 * Useful for debugging when you want to stop execution at a specific point
		 * and examine variable states without continuing script execution.
		 * @param mixed ...$vars Variables to dump before dying - accepts any number of variables
		 * @return void This method never returns as it terminates execution
		 */
		public static function dumpAndDie(...$vars): void {
			try {
				// Clear any existing output buffers to prevent interference
				// This ensures our debug output appears cleanly without mixed content
				if (php_sapi_name() !== 'cli') {
					// ob_get_level() returns current nesting level of output buffers
					// We clear all buffers to start with a clean slate
					while (ob_get_level()) {
						ob_end_clean(); // Discard buffer contents and turn off buffering
					}
					
					ob_start(); // Start fresh output buffer for our debug output
				}
				
				// Use our standard dump method to output the variables
				self::dump(...$vars);
				
				// Flush the output buffer to ensure content reaches the browser
				if (php_sapi_name() !== 'cli') {
					ob_end_flush(); // Send buffer contents to browser and turn off buffering
				}
				
			} catch (\Throwable $e) {
				// Fallback to basic output if our fancy debug fails
				// This ensures we always get some output even if the renderers fail
				echo '<h3>Canvas Debug Error - Falling back to simple output:</h3>';
				echo '<pre>';
				
				// Use basic var_dump as last resort
				foreach ($vars as $var) {
					var_dump($var);
				}
				
				echo '</pre>';
				// Show the original error that caused the fallback
				echo '<p>Original error: ' . $e->getMessage() . '</p>';
			}
			
			// Terminate script execution - this method never returns
			die();
		}
		
		/**
		 * Simple, guaranteed-to-work dump method
		 * @param mixed ...$vars Variables to dump using basic formatting
		 * @return void
		 */
		public static function safeDump(...$vars): void {
			// Output a simple pre-formatted block with inline styling
			// This approach works even when CSS files aren't loaded or custom headers can't be sent
			echo '<pre style="background:#f8f9fa;padding:10px;border:1px solid #ddd;margin:10px 0;font-family:monospace;">';
			
			// Use PHP's native var_dump for each variable
			// This is the most reliable way to output variable information
			foreach ($vars as $var) {
				var_dump($var);
			}
			
			echo '</pre>';
		}
	}