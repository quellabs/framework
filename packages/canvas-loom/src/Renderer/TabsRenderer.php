<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a tabbed interface container.
	 * Tab switching is handled by a small inline script — no WakaPAC required.
	 * Tabs that contain reactive fields will still trigger WakaPAC initialisation
	 * via the parent Resource or Panel container.
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
			
			// id flows into JS string literals via buildScript() — restrict to safe identifier characters
			$id = $properties['id'] ?? 'loom-tabs';
			
			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
				throw new \InvalidArgumentException('TabsRenderer "id" must contain only alphanumerics, hyphens, and underscores.');
			}
			
			// active tab id flows into a JS string literal — apply the same restriction
			if ($active && !preg_match('/^[a-zA-Z0-9_-]+$/', $active)) {
				throw new \InvalidArgumentException('TabsRenderer "active" must contain only alphanumerics, hyphens, and underscores.');
			}
			
			// Build tab bar buttons — active class set directly on the initial active tab
			$buttons = '';
			
			foreach ($tabs as $tab) {
				$tabId = $tab['id'] ?? '';
				$tabLabel = $this->e($tab['label'] ?? $tabId);
				
				// tabId appears in JS string literals inside data-pac-bind — restrict to safe identifier characters
				if ($tabId && !preg_match('/^[a-zA-Z0-9_-]+$/', $tabId)) {
					throw new \InvalidArgumentException("Tab id \"{$tabId}\" must contain only alphanumerics, hyphens, and underscores.");
				}
				
				$activeClass = $tabId === $active ? " active" : '';
				$buttons .= "<button type=\"button\" class=\"{$this->tabButtonClass}{$activeClass}\" data-tab=\"{$tabId}\">{$tabLabel}</button>\n";
			}
			
			// Build HTML
			$html = <<<HTML
    <div id="{$id}" class="{$class}">
        <div class="{$this->tabBarClass}">
            {$buttons}
        </div>
        <div class="{$this->tabPanelsClass}">
            {$children}
        </div>
    </div>
    HTML;
			
			// Inline script for tab switching — no WakaPAC needed
			$script = <<<JS
(function() {
    var el = document.getElementById('{$id}');
    el.querySelectorAll('.{$this->tabBarClass} button[data-tab]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            el.querySelectorAll('.{$this->tabPanelsClass} > div').forEach(function(p) { p.hidden = true; });
            el.querySelectorAll('.{$this->tabBarClass} button').forEach(function(b) { b.classList.remove('active'); });
            document.getElementById(btn.dataset.tab).hidden = false;
            btn.classList.add('active');
        });
    });
})();
JS;
			
			// Return result
			return new RenderResult($html, $script);
		}
	}