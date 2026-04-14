<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders the top-level resource container for a Loom page.
	 * Generates a header (outside the form) and a form body with
	 * WakaPAC component for field interactivity.
	 *
	 * Override renderHeader() or renderBody() in a subclass to
	 * customise either part independently.
	 */
	class ResourceRenderer extends AbstractContainerRenderer {
		
		/** @var string Form element class */
		protected string $formClass = 'loom-resource';
		
		/** @var string Header element class */
		protected string $headerClass = 'loom-resource-header';
		
		/** @var string Title element class */
		protected string $titleClass = 'loom-resource-title';
		
		/** @var string Header actions wrapper class */
		protected string $headerActionsClass = 'loom-resource-header-actions';
		
		/** @var string Save button class */
		protected string $saveClass = 'loom-resource-save';
		
		/** @var string Cancel button class */
		protected string $cancelClass = 'loom-resource-cancel';
		
		/**
		 * Render the resource — header outside the form, body as the form itself
		 * @param array $properties
		 * @param string $children
		 * @param array|null $parent
		 * @param int $index
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$id = $properties['id'] ?? '';
			$method = strtoupper($properties['method'] ?? 'POST');
			$saveDisabled = !empty($properties['save_disabled']);
			$part = $properties['_render_part'] ?? 'full';
			
			// id is required — without it WakaPAC cannot be initialised
			if (!$id) {
				throw new \InvalidArgumentException('ResourceRenderer requires an "id" property.');
			}
			
			// HTML method attribute only supports GET and POST —
			// other methods (PUT, PATCH, DELETE) require a hidden _method field
			$methodAttr = in_array($method, ['GET', 'POST']) ? $method : 'POST';
			
			if ($methodAttr !== $method) {
				$methodSpoofHtml = "<input type=\"hidden\" name=\"loom_method\" value=\"{$method}\">";
			} else {
				$methodSpoofHtml = '';
			}
			
			// Disabled attribute
			$saveDisabledAttr = $saveDisabled ? ' disabled' : '';
			
			// Scripts only generated for full or body — not for header-only renders
			if ($part !== 'header') {
				$scripts = [$this->buildScript($id)];
			} else {
				$scripts = [];
			}
			
			switch ($part) {
				case 'header':
					$headerResult = $this->renderHeader($properties, $id, $saveDisabledAttr);
					$html         = $headerResult->html;
					$scripts      = array_merge($scripts, $headerResult->scripts);
					break;
				
				case 'body':
					$html = $this->renderBody($properties, $children, $id, $methodAttr, $methodSpoofHtml);
					break;
				
				default:
					$headerResult = $this->renderHeader($properties, $id, $saveDisabledAttr);
					$html         = $headerResult->html . "\n" . $this->renderBody($properties, $children, $id, $methodAttr, $methodSpoofHtml);
					$scripts      = array_merge($scripts, $headerResult->scripts);
					break;
			}
			
			return new RenderResult($html, $scripts);
		}
		
		/**
		 * Render the page header with title, cancel and save button.
		 * Override in a subclass to customise the header independently.
		 * @param array $properties Node properties
		 * @param string $id Form id, used to couple the submit button via the form attribute
		 * @param string $saveDisabledAttr Rendered disabled attribute or empty string
		 * @return string
		 */
		protected function renderHeader(array $properties, string $id, string $saveDisabledAttr): RenderResult {
			$title = $properties['title'] ?? '';
			$saveLabel = $properties['save_label'] ?? 'Save';
			$headerId = "{$id}-header";
			$headerButtons = $properties['header_buttons'] ?? [];
			$scripts = [];
			
			// Render extra header buttons with visibility binding
			$extraButtons = '';
			
			foreach ($headerButtons as $button) {
				$name = $button->get('name');
				$label = $button->get('label') ?? '';
				$variant = $button->get('variant') ?? 'primary';
				$action = $button->get('action') ?? '';
				
				$variantClass = match ($variant) {
					'secondary' => 'loom-button loom-button-secondary',
					'danger' => 'loom-button loom-button-danger',
					default => 'loom-button loom-button-primary',
				};
				
				$binding = $name ? "visible: show_{$name}" : '';
				$binding = ($binding && $action) ? "{$binding}, click: {$action}" : ($action ? "click: {$action}" : $binding);
				$bindAttr = $binding ? " data-pac-bind=\"{$binding}\"" : '';
				
				$extraButtons .= "<button type=\"button\" class=\"{$variantClass}\"{$bindAttr}>{$label}</button>\n";
			}
			
			$html = <<<HTML
    <div class="{$this->headerClass}" data-pac-id="{$headerId}">
        <h1 class="{$this->titleClass}">{$title}</h1>
        <div class="{$this->headerActionsClass}">
            {$extraButtons}
            <button type="button" class="{$this->cancelClass}" onclick="history.back()">Cancel</button>
            <button type="submit" form="{$id}" class="{$this->saveClass}"{$saveDisabledAttr}>{$saveLabel}</button>
        </div>
    </div>
    HTML;
			
			// Generate header WakaPAC script with msgProc for visibility toggling
			$constants = '';
			$msgProcCases = '';
			$visibilityProps = '';
			
			foreach ($headerButtons as $button) {
				$name = $button->get('name');
				$showMessage = $button->get('show_message');
				$hideMessage = $button->get('hide_message');
				
				if ($name) {
					$visibilityProps .= "show_{$name}: false,\n        ";
					
					if ($showMessage !== null) {
						$constName = 'MSG_SHOW_' . strtoupper($name);
						$constants .= "const {$constName} = {$showMessage};\n";
						
						$msgProcCases .= <<<JS
                case {$constName}:
                    this.show_{$name} = true;
                    break;\n
JS;
					}
					
					if ($hideMessage !== null) {
						$constName = 'MSG_HIDE_' . strtoupper($name);
						$constants .= "const {$constName} = {$hideMessage};\n";
						$msgProcCases .= <<<JS

                case {$constName}:
                    this.show_{$name} = false;
                    break;\n
JS;
					}
				}
			}
			
			if ($constants) {
				$scripts[] = $constants;
			}
			
			$scripts[] = <<<JS
(function() {
    wakaPAC('{$headerId}', {
        {$visibilityProps}
        msgProc(event) {
            switch (event.message) {
{$msgProcCases}
            }
        }
    }, { hydrate: false });
})();
JS;
			
			return new RenderResult($html, $scripts);
		}
		
		/**
		 * Render the form body with all child nodes.
		 * Override in a subclass to customise the form independently.
		 * @param array $properties Node properties
		 * @param string $children Already-rendered HTML of all child nodes
		 * @param string $id Form id, also used as WakaPAC component id
		 * @param string $methodAttr HTML method attribute value (GET or POST)
		 * @param string $methodSpoofHtml Hidden _method field for PUT/PATCH/DELETE or empty string
		 * @return string
		 */
		protected function renderBody(array $properties, string $children, string $id, string $methodAttr, string $methodSpoofHtml): string {
			$class = $properties['class'] ?? $this->formClass;
			$action = $properties['action'] ?? '';
			
			// Separate field values from collection data —
			// field values are hydrated from the DOM, collections go into data-pac-state
			$data = $this->loom->getData();
			$stateData = array_filter($data, fn($value) => is_array($value));
			$stateJson = !empty($stateData) ? htmlspecialchars(json_encode($stateData), ENT_QUOTES) : '';
			$stateAttr = $stateJson ? " data-pac-state=\"{$stateJson}\"" : '';
			
			return <<<HTML
    <form id="{$id}" action="{$action}" method="{$methodAttr}" class="{$class}" data-pac-id="{$id}"{$stateAttr}>
        {$methodSpoofHtml}
        {$children}
    </form>
    HTML;
		}
	}