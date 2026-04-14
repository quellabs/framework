<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a WakaPAC component container without tab navigation.
	 * Provides the same reactive field hydration as TabsRenderer but
	 * as a simple panel without any tab bar or switching logic.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class PanelRenderer extends AbstractContainerRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-panel';
		
		/**
		 * Render the panel container
		 * @param array      $properties Node properties from the JSON definition
		 * @param string     $children   Already-rendered HTML of all child nodes
		 * @param array|null $parent     Parent node
		 * @param int        $index      Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			// id flows into both HTML attributes and a JS string literal in buildScript().
			// Restrict to characters safe in both contexts: alphanumerics, hyphens, underscores.
			$rawId = $properties['id'] ?? '';
			
			if (!$rawId) {
				throw new \InvalidArgumentException('PanelRenderer requires an "id" property.');
			}
			
			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $rawId)) {
				throw new \InvalidArgumentException('PanelRenderer "id" must contain only alphanumerics, hyphens, and underscores.');
			}
			
			$id    = $rawId;
			$class = $this->e($properties['class'] ?? $this->wrapperClass);
			$nodes = $properties['_children'] ?? [];
			
			// Collect array properties from all field nodes in the tree
			// so dependent dropdowns have their options available in WakaPAC state
			$fieldState = $this->collectFieldProperties($nodes);
			$stateJson  = !empty($fieldState) ? htmlspecialchars(json_encode($fieldState), ENT_QUOTES) : '';
			$stateAttr  = $stateJson ? " data-pac-state=\"{$stateJson}\"" : '';
			
			// HTML panel
			$html = <<<HTML
        <div id="{$id}" class="{$class}" data-pac-id="{$id}"{$stateAttr}>
            {$children}
        </div>
        HTML;
			
			// WakaPAC initialisation — hydrate reads field values from DOM,
			// data-pac-state provides collection data for dependent dropdowns
			$script = $this->buildScript($id, [], $properties['abstraction'] ?? []);
			
			// Return result
			return new RenderResult($html, [$script]);
		}
	}