<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Support\StringInflector;
	
	/**
	 * Renders a tabbed interface container.
	 * Generates a tab bar and content panels, with WakaPAC managing
	 * the active tab state. Tab definitions are provided explicitly
	 * via the tabs property to avoid engine-level pre-processing.
	 */
	class TabsRenderer extends AbstractContainerRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-tabs';
		
		/** @var string Tab bar class */
		protected string $tabBarClass = 'loom-tabs-bar';
		
		/** @var string Tab button class */
		protected string $tabButtonClass = 'loom-tabs-button';
		
		/** @var string Tab panels wrapper class */
		protected string $tabPanelsClass = 'loom-tabs-panels';
		
		/**
		 * Render the tabs container
		 * @param array $properties
		 * @param string $children
		 * @param array|null $parent
		 * @param int $index
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$active = $properties['active'] ?? '';
			$class  = $this->e($properties['class'] ?? $this->wrapperClass);
			$tabs   = $properties['tabs']     ?? [];
			$nodes  = $properties['_children'] ?? [];
			
			// id flows into JS string literals via buildScript() — restrict to safe identifier characters
			$id = $properties['id'] ?? 'loom-tabs';
			
			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
				throw new \InvalidArgumentException('TabsRenderer "id" must contain only alphanumerics, hyphens, and underscores.');
			}
			
			// active tab id flows into a JS string literal — apply the same restriction
			if ($active && !preg_match('/^[a-zA-Z0-9_-]+$/', $active)) {
				throw new \InvalidArgumentException('TabsRenderer "active" must contain only alphanumerics, hyphens, and underscores.');
			}
			
			// Build tab bar buttons from the explicit tabs property
			$buttons = '';
			
			foreach ($tabs as $tab) {
				$tabId    = $tab['id'] ?? '';
				$tabLabel = $this->e($tab['label'] ?? $tabId);
				
				// tabId appears in JS string literals inside data-pac-bind — restrict to safe identifier characters
				if ($tabId && !preg_match('/^[a-zA-Z0-9_-]+$/', $tabId)) {
					throw new \InvalidArgumentException("Tab id \"{$tabId}\" must contain only alphanumerics, hyphens, and underscores.");
				}
				
				$buttons .= "<button type=\"button\" class=\"{$this->tabButtonClass}\" data-pac-bind=\"click: setTab('{$tabId}'), class: {active: activeTab === '{$tabId}'}\">{$tabLabel}</button>\n";
			}
			
			// Collect array properties from all field nodes in the tree
			// so dependent dropdowns have their options available in WakaPAC state
			$fieldState = $this->collectFieldProperties($nodes);
			$stateJson = !empty($fieldState) ? htmlspecialchars(json_encode($fieldState), ENT_QUOTES) : '';
			$stateAttr = $stateJson ? " data-pac-state=\"{$stateJson}\"" : '';
			
			$html = <<<HTML
    <div id="{$id}" class="{$class}" data-pac-id="{$id}"{$stateAttr}>
        <div class="{$this->tabBarClass}">
            {$buttons}
        </div>
        <div class="{$this->tabPanelsClass}">
            {$children}
        </div>
    </div>
    HTML;
			
			$script = $this->buildScript($id, [
				"activeTab: '{$active}'",
				"setTab(tabId) {\n            this.activeTab = tabId;\n        }"
			], $properties['abstraction'] ?? [], $properties['scripts'] ?? []);
			
			// Return result
			return new RenderResult($html, $script);
		}
	}