<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Holds the result of rendering a single node.
	 * Each renderer produces at most one script — accumulation across
	 * the tree is handled by Loom::_render(), not by this class.
	 */
	class RenderResult {
		
		/** @var string Rendered HTML for this node */
		public string $html;
		
		/** @var string|null WakaPAC initialisation script for this node, if any */
		public ?string $script;
		
		/**
		 * Constructor
		 * @param string      $html   Rendered HTML
		 * @param string|null $script WakaPAC initialisation script, if any
		 */
		public function __construct(string $html, ?string $script = null) {
			$this->html   = $html;
			$this->script = $script;
		}
	}