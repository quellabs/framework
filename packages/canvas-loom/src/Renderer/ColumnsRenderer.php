<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Canvas\Loom\RendererInterface;
	
	/**
	 * Renders a multi-column layout container.
	 * Column widths are defined as percentages via the widths property
	 * on this node — each column reads its own width from the parent.
	 */
	class ColumnsRenderer implements RendererInterface {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-columns';
		
		/**
		 * Render the columns container
		 * @param array  $properties Node properties from the JSON definition
		 * @param string $children   Already-rendered HTML of all child nodes
		 * @param array|null $parent Parent node (unused at this level)
		 * @param int $index        Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$class = $properties['class'] ?? $this->wrapperClass;
			$gap   = $properties['gap']   ?? '1rem';
			
			$html = <<<HTML
        <div class="{$class}" style="display: flex; gap: {$gap};">
            {$children}
        </div>
        HTML;
			
			return new RenderResult($html);
		}
	}