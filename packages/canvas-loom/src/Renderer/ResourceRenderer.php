<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
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
			
			// id flows into JS string literals via buildScript() — restrict to safe identifier characters
			if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
				throw new \InvalidArgumentException('ResourceRenderer "id" must contain only alphanumerics, hyphens, and underscores.');
			}
			
			// HTML method attribute only supports GET and POST —
			// other methods (PUT, PATCH, DELETE) require a hidden _method field
			$methodAttr = in_array($method, ['GET', 'POST']) ? $method : 'POST';
			
			if ($methodAttr !== $method) {
				$methodSpoofHtml = "<input type=\"hidden\" name=\"loom_method\" value=\"{$this->e($method)}\">";
			} else {
				$methodSpoofHtml = '';
			}
			
			// Disabled attribute
			$saveDisabledAttr = $saveDisabled ? ' disabled' : '';
			
			switch ($part) {
				case 'header':
					$headerResult = $this->renderHeader($properties, $id, $saveDisabledAttr);
					$html = $headerResult->html;
					$scripts = $headerResult->script !== null ? [$headerResult->script] : [];
					break;
				
				case 'body':
					$bodyResult = $this->renderBody($properties, $children, $id, $methodAttr, $methodSpoofHtml);
					$html = $bodyResult->html;
					$scripts = $bodyResult->script !== null ? [$bodyResult->script] : [];
					break;
				
				default:
					$headerResult = $this->renderHeader($properties, $id, $saveDisabledAttr);
					$bodyResult = $this->renderBody($properties, $children, $id, $methodAttr, $methodSpoofHtml);
					$html = $headerResult->html . "\n" . $bodyResult->html;
					$scripts = [];
					
					if ($headerResult->script !== null) {
						$scripts[] = $headerResult->script;
					}
					
					if ($bodyResult->script !== null) {
						$scripts[] = $bodyResult->script;
					}
					break;
			}
			
			return new RenderResult($html, !empty($scripts) ? implode("\n", $scripts) : null);
		}
		
		/**
		 * Render the page header with title, cancel and save button.
		 * Override in a subclass to customise the header independently.
		 * @param array $properties Node properties
		 * @param string $id Form id, used to couple the submit button via the form attribute
		 * @param string $saveDisabledAttr Rendered disabled attribute or empty string
		 * @return RenderResult
		 */
		protected function renderHeader(array $properties, string $id, string $saveDisabledAttr): RenderResult {
			$title = $this->e($properties['title'] ?? '');
			$saveLabel = $this->e($properties['save_label'] ?? 'Save');
			$headerId = "{$id}-header";
			$headerButtons = $properties['header_buttons'] ?? [];
			
			// Render extra header buttons with visibility binding
			$extraButtons = '';
			
			foreach ($headerButtons as $button) {
				$name = $button->get('name');
				$label = $this->e($button->get('label') ?? '');
				$variant = $button->get('variant') ?? 'primary';
				$action = $button->get('action') ?? '';
				
				// name flows into JS property names and data-pac-bind expressions — restrict to identifier characters
				if ($name && !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
					throw new \InvalidArgumentException("Header button name \"{$name}\" must contain only alphanumerics and underscores.");
				}
				
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
			
			$saveButtonHtml = "<button type=\"submit\" form=\"{$id}\" class=\"{$this->saveClass}\"{$saveDisabledAttr}>{$saveLabel}</button>";
			
			$html = <<<HTML
    <div class="{$this->headerClass}" data-pac-id="{$headerId}">
        <h1 class="{$this->titleClass}">{$title}</h1>
        <div class="{$this->headerActionsClass}">
            {$extraButtons}
            <button type="button" class="{$this->cancelClass}" onclick="history.back()">Cancel</button>
            {$saveButtonHtml}
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
			
			$script = <<<JS
(function() {
    {$constants}
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
			
			return new RenderResult($html, $script);
		}
		
		/**
		 * Render the form body with all child nodes.
		 * Override in a subclass to customise the form independently.
		 * @param array $properties Node properties
		 * @param string $children Already-rendered HTML of all child nodes
		 * @param string $id Form id, also used as WakaPAC component id
		 * @param string $methodAttr HTML method attribute value (GET or POST)
		 * @param string $methodSpoofHtml Hidden _method field for PUT/PATCH/DELETE or empty string
		 * @return RenderResult
		 */
		protected function renderBody(array $properties, string $children, string $id, string $methodAttr, string $methodSpoofHtml): RenderResult {
			$class = $this->e($properties['class'] ?? $this->formClass);
			$action = $this->e($properties['action'] ?? '');
			$notifications = $this->loom->getNotifications();
			
			// Determine whether WakaPAC is needed.
			// Notifications force it because the dismiss button uses data-pac-bind.
			$needsWakaPAC = !empty($notifications) || $this->requiresWakaPAC($properties['_children'] ?? []);
			
			// Render notifications
			$notificationsHtml = '';
			
			if (!empty($notifications)) {
				$items = '';
				
				foreach ($notifications as $notification) {
					// Whitelist type to prevent CSS class injection — unknown types fall back to 'info'
					$type = in_array($notification['type'], ['success', 'error', 'warning', 'info'], true)
						? $notification['type']
						: 'info';
					$message = htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8');
					$items .= "<li class=\"loom-notification-item loom-notification-{$type}\">{$message}</li>\n";
				}
				$notificationsHtml = <<<HTML
<div id="{$id}-notifications" class="loom-notifications">
    <ul class="loom-notifications-list">
        {$items}
    </ul>
    <button type="button" class="loom-notifications-dismiss" data-pac-bind="click: dismiss">×</button>
</div>
HTML;
			}
			
			// data-pac-id and data-pac-state are only emitted when WakaPAC is initialised
			$pacIdAttr = $needsWakaPAC ? " data-pac-id=\"{$id}\"" : '';
			$data = $this->loom->getData();
			
			if ($needsWakaPAC) {
				// Collect options defined on field nodes in the tree (dependent dropdowns)
				// and merge with the caller-supplied data array. Caller data takes precedence
				// so runtime values override build-time defaults.
				$fieldOptions = $this->collectFieldProperties($properties['_children'] ?? []);
				$baseState = $data['_pac_state'] ?? array_filter($data, fn($value) => is_array($value));
				$stateData = array_merge($fieldOptions, $baseState);
			} else {
				$stateData = [];
			}
			
			$stateJson = !empty($stateData) ? htmlspecialchars(json_encode($stateData), ENT_QUOTES) : '';
			$stateAttr = $stateJson ? " data-pac-state=\"{$stateJson}\"" : '';
			
			$html = <<<HTML
    <form id="{$id}" action="{$action}" method="{$methodAttr}" class="{$class}"{$pacIdAttr}{$stateAttr}>
        {$methodSpoofHtml}
        {$notificationsHtml}
        {$children}
    </form>
    HTML;
			
			if ($needsWakaPAC) {
				$clientValidation = !empty($properties['use_wakaform']);
				$serverErrors = $this->loom->getData()['_errors'] ?? [];
				
				if ($clientValidation) {
					$fieldRules = $this->collectFieldRules($properties['_children'] ?? []);
				} else {
					$fieldRules = [];
				}
				
				$script = $this->buildScript($id, [], $properties['abstraction'] ?? [], $properties['scripts'] ?? [], $fieldRules, $clientValidation, $serverErrors);
			} else {
				$script = null;
			}
			
			return new RenderResult($html, $script);
		}
	}