<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Canvas\Loom\RendererInterface;
	
	/**
	 * Renders a single column within a columns layout.
	 * Width is injected by the parent ColumnsRenderer as a percentage.
	 */
	class ColumnRenderer implements RendererInterface {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-column';
		
		/**
		 * Render the column
		 * @param array $properties Node properties from the JSON definition
		 * @param string $children Already-rendered HTML of all child nodes
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$class = $properties['class'] ?? $this->wrapperClass;
			$widths = $parent['properties']['widths'] ?? [];
			
			// Determine width from parent widths array, fall back to equal distribution
			if (isset($widths[$index])) {
				$styleAttr = " style=\"flex: 0 0 {$widths[$index]}%; min-width: 0;\"";
			} else {
				$styleAttr = " style=\"flex: 1; min-width: 0;\"";
			}
			
			$html = <<<HTML
    <div class="{$class}"{$styleAttr}>
        {$children}
    </div>
    HTML;
			
			return new RenderResult($html);
		}
	}