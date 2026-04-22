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
		 * @param array  $properties     Node properties
		 * @param string $id             Form id, used to couple the submit button via the form attribute
		 * @param string $saveDisabledAttr Rendered disabled attribute or empty string
		 * @return RenderResult
		 */
		protected function renderHeader(array $properties, string $id, string $saveDisabledAttr): RenderResult {
			$title = $this->e($properties['title'] ?? '');
			$saveLabel = $this->e($properties['save_label'] ?? 'Save');
			$headerId = "{$id}-header";
			$headerButtons = $properties['header_buttons'] ?? [];
			
			// Build the extra <button> elements from the header_buttons property list
			$extraButtons   = $this->renderHeaderButtons($headerButtons);
			$saveButtonHtml = "<button type=\"submit\" form=\"{$id}\" class=\"{$this->saveClass}\"{$saveDisabledAttr}>{$saveLabel}</button>";
			
			// Build the HTML
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
			
			// Build the WakaPAC IIFE that drives button visibility toggling via msgProc
			$script = $this->buildHeaderScript($headerId, $headerButtons);
			
			// Return the result
			return new RenderResult($html, $script);
		}
		
		/**
		 * Render the extra action buttons in the header bar.
		 *
		 * Each button node may carry a name (used to build a visibility binding),
		 * a variant (primary/secondary/danger), and an action expression (a WakaPAC
		 * click handler). Buttons without a name are rendered without a visibility
		 * binding and are therefore always visible.
		 *
		 * Button names flow into JS property names and data-pac-bind expressions,
		 * so they are restricted to alphanumerics and underscores.
		 *
		 * @param array $headerButtons List of button node objects exposing a get() method
		 * @return string Concatenated <button> HTML, or an empty string when the list is empty
		 * @throws \InvalidArgumentException When a button name contains disallowed characters
		 */
		protected function renderHeaderButtons(array $headerButtons): string {
			$html = '';
			
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
				
				// Compose the data-pac-bind value: visibility first, then click action.
				// Either part is omitted when not applicable.
				$binding = $name ? "visible: show_{$name}" : '';
				$binding = ($binding && $action) ? "{$binding}, click: {$action}" : ($action ? "click: {$action}" : $binding);
				$bindAttr = $binding ? " data-pac-bind=\"{$binding}\"" : '';
				
				$html .= "<button type=\"button\" class=\"{$variantClass}\"{$bindAttr}>{$label}</button>\n";
			}
			
			return $html;
		}
			
		/**
		 * Build the WakaPAC initialisation script for the header component.
		 *
		 * Iterates the header buttons to produce three things that are injected
		 * into the IIFE:
		 *
		 * - $constants      — numeric message-id constants (MSG_SHOW_X / MSG_HIDE_X)
		 *                     declared at the top of the IIFE scope so the switch
		 *                     cases are readable rather than raw numbers.
		 * - $visibilityProps — `show_x: false` reactive properties, one per named
		 *                      button, that drive the visible: bindings on the buttons.
		 * - $msgProcCases   — switch cases that set the relevant show_x property to
		 *                     true or false when the matching message arrives.
		 *
		 * Buttons without a name are skipped because they have no visibility state.
		 * Buttons without show_message or hide_message simply get no case for that
		 * direction — they can still be shown/hidden by other means.
		 *
		 * @param string $headerId      WakaPAC component id for the header div
		 * @param array  $headerButtons List of button node objects exposing a get() method
		 * @return string Ready-to-emit JavaScript IIFE
		 */
		protected function buildHeaderScript(string $headerId, array $headerButtons): string {
			$constants = '';
			$visibilityProps = '';
			$msgProcCases = '';
			
			foreach ($headerButtons as $button) {
				$name = $button->get('name');
				$showMessage = $button->get('show_message');
				$hideMessage = $button->get('hide_message');
				
				if (!$name) {
					continue;
				}
				
				// Each named button gets a reactive show_x property, defaulting to hidden.
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
			
			return <<<JS
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
		}
		
		/**
		 * Render the form body with all child nodes.
		 *
		 * Assembles the <form> element from its three independent concerns —
		 * notification bar, WakaPAC state attributes, and the body script —
		 * each delegated to a dedicated helper so this method stays as a
		 * thin orchestrator. Override the helpers in a subclass to customise
		 * any individual concern without touching the others.
		 *
		 * @param array $properties Node properties
		 * @param string $children Already-rendered HTML of all child nodes
		 * @param string $id Form id, also used as WakaPAC component id
		 * @param string $methodAttr HTML method attribute value (GET or POST)
		 * @param string $methodSpoofHtml Hidden _method field for PUT/PATCH/DELETE, or empty string
		 * @return RenderResult
		 */
		protected function renderBody(array $properties, string $children, string $id, string $methodAttr, string $methodSpoofHtml): RenderResult {
			$class = $this->e($properties['class'] ?? $this->formClass);
			$action = $this->e($properties['action'] ?? '');
			$notifications = $this->loom->getNotifications();
			$childNodes = $properties['_children'] ?? [];
			
			// Notifications force WakaPAC because the dismiss button uses data-pac-bind.
			$needsWakaPAC = !empty($notifications) || $this->requiresWakaPAC($childNodes);
			
			// Render the notifications
			$notificationsHtml = $this->renderNotifications($notifications, $id);
			$pacAttrs  = $this->resolveWakaPACAttributes($needsWakaPAC, $id, $childNodes);
			$pacIdAttr = $pacAttrs['pacIdAttr'];
			$stateAttr = $pacAttrs['stateAttr'];
			
			$html = <<<HTML
    <form id="{$id}" action="{$action}" method="{$methodAttr}" class="{$class}"{$pacIdAttr}{$stateAttr}>
        {$methodSpoofHtml}
        {$notificationsHtml}
        {$children}
    </form>
    HTML;
		
			// Build scripts
			if ($needsWakaPAC) {
				$script = $this->buildBodyScript($id, $properties, $childNodes);
			} else {
				$script = null;
			}
			
			return new RenderResult($html, $script);
		}
		
		/**
		 * Build the notifications bar HTML for the top of the form.
		 *
		 * Returns an empty string when there are no notifications so the
		 * caller can interpolate the result unconditionally.
		 * Notification types are whitelisted to prevent CSS class injection;
		 * anything outside the known set falls back to 'info'.
		 *
		 * @param array $notifications Flat list of ['type' => string, 'message' => string] entries
		 * @param string $id Form id, used to give the notification container a scoped id
		 * @return string Rendered HTML, or an empty string when $notifications is empty
		 */
		protected function renderNotifications(array $notifications, string $id): string {
			if (empty($notifications)) {
				return '';
			}
			
			$allowedTypes = ['success', 'error', 'warning', 'info'];
			$items = '';
			
			foreach ($notifications as $notification) {
				// Restrict type to the whitelist so user-supplied values cannot inject arbitrary CSS classes.
				$type = in_array($notification['type'], $allowedTypes, true) ? $notification['type'] : 'info';
				$message = htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8');
				$items .= "<li class=\"loom-notification-item loom-notification-{$type}\">{$message}</li>\n";
			}
			
			return <<<HTML
<div id="{$id}-notifications" class="loom-notifications">
    <ul class="loom-notifications-list">
        {$items}
    </ul>
    <button type="button" class="loom-notifications-dismiss" data-pac-bind="click: dismiss">×</button>
</div>
HTML;
		}
		
		/**
		 * Resolve the data-pac-id and data-pac-state HTML attributes for the form element.
		 *
		 * Both attributes are omitted entirely when WakaPAC is not needed so the
		 * rendered HTML stays clean for plain server-side-only forms.
		 * When WakaPAC is active, state is assembled by merging build-time field
		 * options (e.g. dependent dropdown option lists) with runtime caller data,
		 * with caller data taking precedence so it can override build-time defaults.
		 *
		 * @param bool $needsWakaPAC Whether the form requires a WakaPAC component
		 * @param string $id Component id, becomes the data-pac-id value
		 * @param array $childNodes Raw child node tree, scanned for field-level state
		 * @return array{pacIdAttr: string, stateAttr: string}
		 */
		protected function resolveWakaPACAttributes(bool $needsWakaPAC, string $id, array $childNodes): array {
			if (!$needsWakaPAC) {
				return ['pacIdAttr' => '', 'stateAttr' => ''];
			}
			
			$pacIdAttr = " data-pac-id=\"{$id}\"";
			$data = $this->loom->getData();
			
			// Collect field-level state from the child tree (e.g. option lists for dependent dropdowns),
			// then merge with caller-supplied data so runtime values win over build-time defaults.
			$fieldOptions = $this->collectFieldProperties($childNodes);
			$baseState = $data['_pac_state'] ?? array_filter($data, fn($value) => is_array($value));
			$stateData = array_merge($fieldOptions, $baseState);
			
			$stateJson = !empty($stateData) ? htmlspecialchars(json_encode($stateData), ENT_QUOTES) : '';
			$stateAttr = $stateJson ? " data-pac-state=\"{$stateJson}\"" : '';
			
			return ['pacIdAttr' => $pacIdAttr, 'stateAttr' => $stateAttr];
		}
		
		/**
		 * Build the WakaPAC initialisation script for the form body component.
		 *
		 * Handles both the plain-reactive case (notifications dismiss, dependent
		 * dropdowns) and the optional WakaForm client-validation case. When
		 * client validation is enabled via the 'use_wakaform' property, field
		 * rules are collected from the child tree and a validateAndSubmit()
		 * method is injected so the save button can trigger validated submission.
		 *
		 * @param string $id WakaPAC component id (matches the form's data-pac-id)
		 * @param array $properties Full node properties array
		 * @param array $childNodes Raw child node tree, scanned for validation rules
		 * @return string            Ready-to-emit JavaScript, already wrapped in an IIFE by buildScript()
		 */
		protected function buildBodyScript(string $id, array $properties, array $childNodes): string {
			$extra = [];
			$clientValidation = !empty($properties['use_wakaform']);
			$serverErrors = $this->loom->getData()['_errors'] ?? [];
			$fieldRules = $clientValidation ? $this->collectFieldRules($childNodes) : [];
			
			if ($clientValidation) {
				// Inject a validateAndSubmit() method that WakaForm calls instead of a plain submit,
				// so client-side validation runs before the form is dispatched to the server.
				$extra[] = <<<JS
		validateAndSubmit() {
            this.submitted = true;
            
            if (!form.validate()) {
                return false;
            }
            
            this.container.submit();
        }
JS;
			}
			
			return $this->buildScript($id, $extra, $properties['abstraction'] ?? [], $properties['scripts'] ?? [], $fieldRules, $clientValidation, $serverErrors);
		}
	}