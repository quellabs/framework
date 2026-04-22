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
			$tabs   = $properties['tabs'] ?? [];
			
			// id flows into getElementById() and buildTabScript() — must be a safe JS identifier
			$id = $properties['id'] ?? 'loom-tabs';
			$this->validateIdentifier($id, 'id');
			
			// active flows into a JS string literal comparison — apply the same restriction
			if ($active) {
				$this->validateIdentifier($active, 'active');
			}
			
			// Build buttons first so the active class is stamped on the correct button at render time,
			// avoiding a flash of unstyled tabs before JS runs
			$buttons = $this->buildButtons($tabs, $active);
			
			// Return render result
			return new RenderResult(
				$this->buildTabHtml($id, $class, $buttons, $children),
				$this->buildTabScript($id)
			);
		}
		
		/**
		 * Validate that an identifier contains only safe characters for use in JS string literals.
		 * @param string $value
		 * @param string $fieldName
		 * @return void
		 * @throws \InvalidArgumentException
		 */
		protected function validateIdentifier(string $value, string $fieldName): void {
			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
				throw new \InvalidArgumentException("TabsRenderer \"{$fieldName}\" must contain only alphanumerics, hyphens, and underscores.");
			}
		}
		
		/**
		 * Build the tab bar button HTML.
		 * @param array $tabs
		 * @param string $active
		 * @return string
		 */
		protected function buildButtons(array $tabs, string $active): string {
			$buttons = '';
			
			foreach ($tabs as $tab) {
				$tabId = $tab['id'] ?? '';
				$tabLabel = $this->e($tab['label'] ?? $tabId);
				
				// tabId appears in JS string literals inside data-pac-bind — restrict to safe identifier characters
				if ($tabId) {
					$this->validateIdentifier($tabId, "tab id \"{$tabId}\"");
				}
				
				// Determine active class
				$activeClass = $tabId === $active ? ' active' : '';
				
				// Add button
				$buttons .= "<button type=\"button\" class=\"{$this->tabButtonClass}{$activeClass}\" data-tab=\"{$tabId}\">{$tabLabel}</button>\n";
			}
			
			return $buttons;
		}
		
		/**
		 * Build the tabs wrapper HTML.
		 * @param string $id
		 * @param string $class
		 * @param string $buttons
		 * @param string $children
		 * @return string
		 */
		protected function buildTabHtml(string $id, string $class, string $buttons, string $children): string {
			return <<<HTML
    <div id="{$id}" class="{$class}">
        <div class="{$this->tabBarClass}">
            {$buttons}
        </div>
        <div class="{$this->tabPanelsClass}">
            {$children}
        </div>
    </div>
    HTML;
		}
		
		/**
		 * Build the inline tab-switching script.
		 * @param string $id
		 * @return string
		 */
		protected function buildTabScript(string $id): string {
			return <<<JS
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
		}
	}