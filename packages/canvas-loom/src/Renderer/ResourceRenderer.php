<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Canvas\Loom\RendererInterface;
	
	/**
	 * Renders the top-level resource container for a Loom page.
	 * Generates a <form> element with a WakaPAC component for field
	 * interactivity. Form submission is handled natively by the browser —
	 * WakaPAC manages reactivity only.
	 */
	class ResourceRenderer implements RendererInterface {
		
		/**
		 * Render the resource container
		 * @param array  $properties Node properties from the JSON definition
		 * @param string $children   Already-rendered HTML of all child nodes
		 * @return RenderResult
		 */
		public function render(array $properties, string $children): RenderResult {
			$id     = $properties['id']     ?? '';
			$action = $properties['action'] ?? '';
			$method = strtoupper($properties['method'] ?? 'POST');
			$class  = $properties['class']  ?? 'loom-resource';
			
			// id is required — without it WakaPAC cannot be initialized
			if (!$id) {
				throw new \InvalidArgumentException('ResourceRenderer requires an "id" property.');
			}
			
			// HTML method attribute only supports GET and POST —
			// other methods (PUT, PATCH, DELETE) require a hidden _method field
			$methodAttr   = in_array($method, ['GET', 'POST']) ? $method : 'POST';
			$methodSpoofHtml = $methodAttr !== $method
				? "<input type=\"hidden\" name=\"loom_method\" value=\"{$method}\">"
				: '';
			
			$html = <<<HTML
        <form id="{$id}" action="{$action}" method="{$methodAttr}" class="{$class}" data-pac-id="{$id}">
            {$methodSpoofHtml}
            {$children}
        </form>
        HTML;
			
			// Generate WakaPAC initialisation script —
			// empty abstraction, hydrate reads all field values from the DOM
			$script = "wakaPAC('{$id}', {}, { hydrate: true });";
			
			return new RenderResult($html, [$script]);
		}
	}