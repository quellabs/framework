<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	/**
	 * Factory class for creating renderer instances based on type
	 */
	class RendererFactory {
		
		/**
		 * Creates a renderer instance based on the specified type
		 * @param string $type The type of renderer to create ('cli' or any other value for HTML)
		 * @return RendererInterface The created renderer instance
		 */
		static public function create(string $type): RendererInterface {
			return match ($type) {
				// Return CLI renderer for command-line interface output
				'cli' => new CliRenderer(),
				
				// Default to HTML renderer for web-based output
				default => new HtmlRenderer(),
			};
		}
	}