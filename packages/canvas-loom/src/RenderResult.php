<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Holds the result of rendering a single node
	 */
	class RenderResult {
		public string $html;
		public array $scripts;
		
		public function __construct(string $html, array $scripts = []) {
			$this->html = $html;
			$this->scripts = $scripts;
		}
	}