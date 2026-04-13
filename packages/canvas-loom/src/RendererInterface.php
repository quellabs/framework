<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Interface that all Loom renderers must implement.
	 * Renderers are responsible for transforming a single node's properties
	 * and its already-rendered children into an HTML string.
	 *
	 * The engine handles recursion — by the time render() is called,
	 * all child nodes have already been rendered and are available as
	 * a single HTML string in $children.
	 */
	interface RendererInterface {
		
		/**
		 * Render a single node
		 * @param array      $properties Node properties from the JSON definition
		 * @param string     $children   Already-rendered HTML of all child nodes
		 * @param array|null $parent     Parent node, available for renderers that need parent context
		 * @param int        $index      Index of this node within its parent's children array
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult;
	}