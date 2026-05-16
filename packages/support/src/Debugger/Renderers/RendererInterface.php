<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	/**
	 * Interface for debugging renderers
	 * Defines the contract that all debugging renderers must implement
	 *
	 * @phpstan-type CallLocation array{
	 *     file: string,
	 *     line: int,
	 *     function: string,
	 *     class: string|null,
	 *     type: string|null
	 * }
	 */
	interface RendererInterface {
		/**
		 * Main render method - renders an array of variables
		 * @param array<int, mixed> $vars Variables to render
		 */
		public function render(array $vars): void;
		
		/**
		 * Set the call location (file, line, function, class, type)
		 * @param CallLocation $callLocation
		 */
		public function setCallLocation(array $callLocation): void;
		
		/**
		 * Set configuration value
		 * @param string $key Configuration key
		 * @param mixed $value Configuration value
		 */
		public function setConfig(string $key, mixed $value): void;
	}