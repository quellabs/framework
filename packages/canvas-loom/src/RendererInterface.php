<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Interface that all renderers must implement
	 */
	interface RendererInterface {
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult;
	}