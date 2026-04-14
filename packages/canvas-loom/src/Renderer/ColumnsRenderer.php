<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a multi-column layout container.
	 * Column widths are defined as percentages via the widths property
	 * on this node — each column reads its own width from the parent.
	 */
	class ColumnsRenderer extends AbstractRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-columns';
		
		/**
		 * Render the columns container
		 * @param array $properties
		 * @param string $children
		 * @param array|null $parent
		 * @param int $index
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$class = $this->e($properties['class'] ?? $this->wrapperClass);
			
			// Sanitize gap: allow only digits, dots, spaces, and valid CSS units.
			// Prevents CSS injection via inline style attribute.
			$rawGap = $properties['gap'] ?? '1rem';
			
			if (preg_match('/^[\d.\s]+(px|rem|em|%|vw|vh|ch|ex|cm|mm|in|pt|pc)(\s[\d.\s]+(px|rem|em|%|vw|vh|ch|ex|cm|mm|in|pt|pc))*$/', $rawGap)) {
				$gap = $rawGap;
			} else {
				$gap = '1rem';
			}
			
			$html = <<<HTML
        <div class="{$class}" style="display: flex; gap: {$gap};">
            {$children}
        </div>
        HTML;
			
			return new RenderResult($html);
		}
	}