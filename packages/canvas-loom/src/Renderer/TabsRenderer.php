<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a tabbed interface container.
	 * Tab switching is handled by a small inline script — no WakaPAC required.
	 * Tabs that contain reactive fields will still trigger WakaPAC initialisation
	 * via the parent Resource or Panel container.
	 *
	 * Error indicators on tab buttons are driven by two separate mechanisms:
	 *
	 * 1. Server-side errors (_errors in the render data) — stamped at render time
	 *    by buildButtons() by cross-referencing each tab's field names against the
	 *    error map. No JS required.
	 *
	 * 2. Client-side validation (WakaForm) — driven by validateAndSubmit() in
	 *    ResourceRenderer, which runs after form.validate() and has direct access
	 *    to the form proxy. It reads data-loom-tab-fields on each button and calls
	 *    form[fieldName].valid to determine which tabs have errors.
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
		
		/** @var string Error indicator class applied to tab buttons that contain at least one invalid field */
		protected string $tabButtonErrorClass = 'loom-tabs-button--has-error';
		
		/**
		 * Render the tabs container.
		 *
		 * Passes the raw child node tree and active server errors down to
		 * buildButtons() so each tab button can be stamped with an error
		 * indicator at render time. The field names per tab are also emitted
		 * as a data-loom-tab-fields attribute so validateAndSubmit() in
		 * ResourceRenderer can update indicators after a failed client-side
		 * validation attempt without any additional PHP involvement.
		 *
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
			$childNodes = $properties['_children'] ?? [];
			
			// id flows into getElementById() and buildTabScript() — must be a safe JS identifier
			$id = $properties['id'] ?? 'loom-tabs';
			$this->validateIdentifier($id, 'id');
			
			// active flows into a JS string literal comparison — apply the same restriction
			if ($active) {
				$this->validateIdentifier($active, 'active');
			}
			
			// Collect active server-side errors once so buildButtons() can check them
			// without calling getData() repeatedly inside the loop.
			$errors = $this->loom->getData()['_errors'] ?? [];
			
			// Build buttons first so the active class and any error indicators are
			// stamped at render time, avoiding a flash of unstyled tabs before JS runs.
			$buttons = $this->buildButtons($tabs, $active, $childNodes, $errors);
			
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
		 *
		 * Each button receives:
		 * - An error indicator class when the tab contains at least one field
		 *   with a server-side validation error, determined by cross-referencing
		 *   the tab's field names against the _errors map from the render data.
		 * - A data-loom-tab-fields attribute listing the field names owned by
		 *   this tab (comma-separated). validateAndSubmit() in ResourceRenderer
		 *   reads this to update error indicators after a failed WakaForm
		 *   client-side validation attempt.
		 *
		 * @param array $tabs     Tab index from the Tabs builder (id + label pairs)
		 * @param string $active  Id of the initially active tab
		 * @param array $childNodes Raw child node tree of the Tabs container
		 * @param array $errors   Active server-side validation errors, keyed by field name
		 * @return string
		 */
		protected function buildButtons(array $tabs, string $active, array $childNodes = [], array $errors = []): string {
			$buttons = '';
			
			// Index the raw child nodes by tab id so we can look up each tab's
			// subtree in O(1) rather than searching the array on every iteration.
			$tabNodeIndex = [];
			
			foreach ($childNodes as $child) {
				if (($child['type'] ?? '') === 'tab') {
					$tabNodeIndex[$child['properties']['id'] ?? ''] = $child;
				}
			}
			
			foreach ($tabs as $tab) {
				$tabId = $tab['id'] ?? '';
				$tabLabel = $this->e($tab['label'] ?? $tabId);
				
				// tabId appears in JS string literals inside data-pac-bind — restrict to safe identifier characters
				if ($tabId) {
					$this->validateIdentifier($tabId, "tab id \"{$tabId}\"");
				}
				
				// Collect the field names that belong to this tab so we can check
				// server errors and emit the data-loom-tab-fields attribute.
				$tabChildren  = isset($tabNodeIndex[$tabId]) ? [$tabNodeIndex[$tabId]] : [];
				$tabFieldNames = $this->collectFieldNames($tabChildren);
				
				// Server-side error check: does any field in this tab have an error
				// from the last validation pass?
				$hasError = !empty($errors) && !empty(array_intersect($tabFieldNames, array_keys($errors)));
				
				// Build class list
				$activeClass = $tabId === $active ? ' active' : '';
				$errorClass  = $hasError ? " {$this->tabButtonErrorClass}" : '';
				
				// data-loom-tab-fields is consumed by validateAndSubmit() to drive client-side
				// indicator updates after a failed WakaForm submission attempt.
				// Emitted unconditionally so the JS can always rely on the attribute being present.
				$fieldsAttr = ' data-loom-tab-fields="' . implode(',', $tabFieldNames) . '"';
				
				$buttons .= "<button type=\"button\" class=\"{$this->tabButtonClass}{$activeClass}{$errorClass}\" data-tab=\"{$tabId}\"{$fieldsAttr}>{$tabLabel}</button>\n";
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
		 *
		 * This script handles tab switching only. Error indicator updates are
		 * performed by validateAndSubmit() in ResourceRenderer after form.validate()
		 * runs, which gives it direct access to the form proxy and field validity
		 * state — no DOM observation needed.
		 *
		 * @param string $id
		 * @return string
		 */
		protected function buildTabScript(string $id): string {
			return <<<JS
(function() {
    const el = document.getElementById('{$id}');
    
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