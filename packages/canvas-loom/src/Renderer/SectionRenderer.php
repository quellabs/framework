<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a neutral container that groups related fields together.
	 * Does not carry any form semantics or WakaPAC initialisation —
	 * those responsibilities belong to the ResourceRenderer.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class SectionRenderer extends AbstractRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-section';
		
		/**
		 * Render the section container
		 * @param array $properties
		 * @param string $children
		 * @param array|null $parent
		 * @param int $index
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$id = $properties['id'] ?? '';
			$class = $properties['class'] ?? $this->wrapperClass;
			
			// Only render id attribute when explicitly provided
			$idAttr = $id ? " id=\"{$id}\"" : '';
			
			$html = <<<HTML
        <div{$idAttr} class="{$class}">
            {$children}
        </div>
        HTML;
			
			return new RenderResult($html);
		}
	}