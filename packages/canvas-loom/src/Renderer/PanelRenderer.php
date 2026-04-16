<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a panel container — a layout grouping without its own WakaPAC instance.
	 * Fields inside a Panel are bound to the parent Resource component.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class PanelRenderer extends AbstractRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-panel';
		
		/**
		 * Render the panel container
		 * @param array $properties Node properties from the JSON definition
		 * @param string $children Already-rendered HTML of all child nodes
		 * @param array|null $parent Parent node
		 * @param int $index Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$id = $this->e($properties['id'] ?? '');
			$class = $this->e($properties['class'] ?? $this->wrapperClass);
			
			$html = <<<HTML
        <div id="{$id}" class="{$class}">
            {$children}
        </div>
        HTML;
			
			return new RenderResult($html);
		}
	}