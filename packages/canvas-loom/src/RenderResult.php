<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Holds the result of rendering a single node.
	 * Each renderer produces at most one script — accumulation across
	 * the tree is handled by Loom::renderNode(), not by this class.
	 */
	readonly class RenderResult {
		
		/** Rendered HTML for this node */
		public string $html;
		
		/** WakaPAC initialisation script for this node, if any */
		public ?string $script;
		
		/**
		 * Constructor
		 * @param string $html Ren dered HTML
		 * @param string|null $script WakaPAC initialisation script, if any
		 */
		public function __construct(string $html, ?string $script = null) {
			$this->html = $html;
			$this->script = $script;
		}
	}