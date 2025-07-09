<?php
	
	namespace Quellabs\Contracts\Templates;
	
	/**
	 * Defines the contract that all template engines must implement within the Canvas framework.
	 * This interface provides a consistent API for template rendering operations regardless of
	 * the underlying template engine (Smarty, Twig, etc.).
	 * @package Quellabs\Canvas\Templating
	 */
	interface TemplateEngineInterface {
		
		/**
		 * Renders a template with the provided data
		 * @param string $template The template file name or path to render
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content
		 * @throws TemplateRenderException If template rendering fails
		 */
		public function render(string $template, array $data = []): string;
		
		/**
		 * Renders a template string with the provided data
		 * @param string $templateString The template content as a string
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content
		 * @throws TemplateRenderException If template rendering fails
		 */
		public function renderString(string $templateString, array $data = []): string;
		
		/**
		 * Adds a global variable available to all templates
		 * @param string $key The variable name to use in templates
		 * @param mixed $value The value to assign (can be any type)
		 * @return void
		 */
		public function addGlobal(string $key, mixed $value): void;
		
		/**
		 * Checks if a template file exists and is accessible
		 * @param string $template The template file name or path to check
		 * @return bool True if the template exists and is accessible, false otherwise
		 */
		public function exists(string $template): bool;
		
		/**
		 * Clears the template cache
		 * @return void
		 * @throws \RuntimeException If cache clearing fails
		 */
		public function clearCache(): void;
	}