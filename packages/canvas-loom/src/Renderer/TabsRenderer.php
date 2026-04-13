<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a tabbed interface container.
	 * Generates a tab bar and content panels, with WakaPAC managing
	 * the active tab state. Tab definitions are provided explicitly
	 * via the tabs property to avoid engine-level pre-processing.
	 */
	class TabsRenderer extends AbstractRenderer {
		
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
			$id = $properties['id'] ?? 'loom-tabs';
			$active = $properties['active'] ?? '';
			$class = $properties['class'] ?? $this->wrapperClass;
			$tabs = $properties['tabs'] ?? [];
			
			// Build tab bar buttons from the explicit tabs property
			$buttons = '';
			
			foreach ($tabs as $tab) {
				$tabId = $tab['id'] ?? '';
				$tabLabel = $tab['label'] ?? $tabId;
				$buttons .= "<button type=\"button\" class=\"{$this->tabButtonClass}\" data-pac-bind=\"click: setTab('{$tabId}'), class: { active: activeTab === '{$tabId}'}\">{$tabLabel}</button>\n";
			}
			
			$html = <<<HTML
        <div id="{$id}" class="{$class}" data-pac-id="{$id}">
            <div class="{$this->tabBarClass}">
                {$buttons}
            </div>
            <div class="{$this->tabPanelsClass}">
                {$children}
            </div>
        </div>
        HTML;
			
			// WakaPAC initialisation — manages active tab state
			$script = <<<JS
        wakaPAC('{$id}', {
            activeTab: '{$active}',
            setTab(tabId) {
                this.activeTab = tabId;
            }
        }, { hydrate: true });
        JS;
			
			return new RenderResult($html, [$script]);
		}
	}