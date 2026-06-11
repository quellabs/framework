<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a single column within a columns layout.
	 * Width is injected by the parent ColumnsRenderer as a percentage.
	 * A column renders all its children in order — Text nodes for descriptive
	 * content, Field nodes for form inputs, or any combination.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class ColumnRenderer extends AbstractRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-column';
		
		/**
		 * Render the column
		 * @param array<string, mixed> $properties Node properties from the JSON definition
		 * @param string $children Already-rendered HTML of all child nodes
		 * @param array<string, mixed>|null $parent Parent node
		 * @param int $index Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$rawWidth = $properties['width'] ?? null;
			$rawClass = $properties['class'] ?? $this->wrapperClass;
			$width = is_int($rawWidth) ? $rawWidth : (is_numeric($rawWidth) ? (int) $rawWidth : null);
			$class = is_string($rawClass) ? $rawClass : $this->wrapperClass;
			
			// Apply width as inline flex style if provided by the parent ColumnsRenderer,
			// otherwise let the column grow to fill available space
			if ($width !== null) {
				$styleAttr = " style=\"flex: 0 0 {$width}%; min-width: 0;\"";
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