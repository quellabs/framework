<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	/**
	 * Interface for debugging renderers
	 * Defines the contract that all debugging renderers must implement
	 */
	interface RendererInterface {
		/**
		 * Main render method - renders an array of variables
		 * @param array $vars Variables to render
		 */
		public function render(array $vars): void;
		
		/**
		 * Set configuration value
		 * @param string $key Configuration key
		 * @param mixed $value Configuration value
		 */
		public function setConfig(string $key, mixed $value): void;
	}