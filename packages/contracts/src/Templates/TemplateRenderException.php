<?php
	
	namespace Quellabs\Contracts\Templates;
	
	/**
	 * Exception thrown when template rendering fails.
	 *
	 * This exception provides additional context about which template
	 * failed to render, making debugging easier.
	 */
	class TemplateRenderException extends \Exception {
		/**
		 * The name of the template that failed to render.
		 */
		private string $templateName;
		
		/**
		 * Constructor for TemplateRenderException.
		 * @param string $templateName The name of the template that failed to render
		 * @param string $message The error message describing what went wrong
		 * @param \Throwable|null $previous Optional previous exception for chaining
		 */
		public function __construct(string $templateName, string $message, ?\Throwable $previous = null) {
			// Store the template name
			$this->templateName = $templateName;
			
			// Call parent constructor with error code 0 and optional previous exception
			parent::__construct($message, 0, $previous);
		}
		
		/**
		 * Get the name of the template that failed to render.
		 * @return string The template name
		 */
		public function getTemplateName(): string {
			return $this->templateName;
		}
	}