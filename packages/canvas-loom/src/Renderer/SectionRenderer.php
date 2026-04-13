<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Canvas\Loom\RendererInterface;
	
	/**
	 * Renders a neutral container that groups related fields together.
	 * Does not carry any form semantics or WakaPAC initialisation —
	 * those responsibilities belong to the ResourceRenderer.
	 */
	class SectionRenderer implements RendererInterface {
		
		/**
		 * Render the section container
		 * @param array  $properties Node properties from the JSON definition
		 * @param string $children   Already-rendered HTML of all child nodes
		 * @return RenderResult
		 */
		public function render(array $properties, string $children): RenderResult {
			$id    = $properties['id']    ?? '';
			$class = $properties['class'] ?? 'loom-section';
			
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