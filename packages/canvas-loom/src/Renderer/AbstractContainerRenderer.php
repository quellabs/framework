<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Support\StringInflector;
	
	/**
	 * Base class for renderers that act as WakaPAC component containers.
	 * Provides shared functionality for collecting field state from the
	 * node tree and injecting it into data-pac-state.
	 */
	abstract class AbstractContainerRenderer extends AbstractRenderer {
		
		/**
		 * Recursively collect validation rules from all field nodes in the tree.
		 * Returns a map of fieldName => JS rules array string for use in createForm().
		 * Fields without rules are omitted — createForm() only needs entries where
		 * rules are defined.
		 * @param array $nodes
		 * @return array<string, string[]> fieldName => array of toJs() strings
		 */
		protected function collectFieldRules(array $nodes): array {
			$result = [];
			
			foreach ($nodes as $node) {
				if (($node['type'] ?? '') === 'field') {
					$name = $node['properties']['name'] ?? '';
					$rules = $node['properties']['rules'] ?? [];
					
					if ($name && !empty($rules)) {
						$jsRules = [];
						
						foreach ($rules as $rule) {
							$js = $rule->toJs();
							
							if ($js === null) {
								// Rule has no JS equivalent — skip silently
								continue;
							}
							
							$jsRules[] = $js;
						}
						
						if (!empty($jsRules)) {
							$result[$name] = $jsRules;
						}
					}
				}
				
				if (!empty($node['children'])) {
					foreach ($this->collectFieldRules($node['children']) as $name => $jsRules) {
						$result[$name] = $jsRules;
					}
				}
			}
			
			return $result;
		}
		
		/**
		 * Recursively collect options arrays from all field nodes in the tree.
		 * Used to inject dependent dropdown options into the WakaPAC state
		 * before the component is initialized.
		 * @param array $nodes
		 * @return array
		 */
		protected function collectFieldProperties(array $nodes): array {
			$state = [];
			
			foreach ($nodes as $node) {
				if (($node['type'] ?? '') === 'field') {
					$name = $node['properties']['name'] ?? '';
					$options = $node['properties']['options'] ?? null;
					
					if ($name && is_array($options)) {
						$state[StringInflector::pluralize($name)] = $options;
					}
				}
				
				// Recurse into children
				if (!empty($node['children'])) {
					foreach ($this->collectFieldProperties($node['children']) as $k => $v) {
						$state[$k] = $v;
					}
				}
			}
			
			return $state;
		}
		
		/**
		 * Recursively scan a node tree to determine whether WakaPAC initialisation
		 * is actually needed. Resource and Panel skip buildScript() entirely if not.
		 *
		 * WakaPAC is required if any node in the tree:
		 * - Is a tabs container (always reactive)
		 * - Has a pac_bind property (data-pac-bind attribute)
		 * - Has scripts or abstraction properties set on a container
		 * - Is a text node with {{ }} interpolation in its value
		 * @param array $nodes
		 * @return bool
		 */
		protected function requiresWakaPAC(array $nodes): bool {
			foreach ($nodes as $node) {
				$type = $node['type'] ?? '';
				$properties = $node['properties'] ?? [];
				
				// Field with validation rules needs WakaForm bindings (visible: !form.x.valid)
				if ($type === 'field' && !empty($properties['rules'])) {
					return true;
				}
				
				// Any node with an explicit pac_bind
				if (!empty($properties['pac_bind'])) {
					return true;
				}
				
				// Dependent dropdowns use implicit pac_bind via foreach_expression
				if (!empty($properties['foreach_expression']) || !empty($properties['depends_on'])) {
					return true;
				}
				
				// Container with scripts or abstraction defined
				if (!empty($properties['scripts']) || !empty($properties['abstraction'])) {
					return true;
				}
				
				// Text node with WakaPAC interpolation
				if ($type === 'text' && isset($properties['value']) && str_contains($properties['value'], '{{')) {
					return true;
				}
				
				// Field hint with WakaPAC interpolation
				if ($type === 'field' && isset($properties['hint']) && str_contains($properties['hint'], '{{')) {
					return true;
				}
				
				// Button with an action expression
				if ($type === 'button' && !empty($properties['action'])) {
					return true;
				}
				
				// Recurse into children
				if (!empty($node['children']) && $this->requiresWakaPAC($node['children'])) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Generate the WakaPAC initialisation script for a container component.
		 * Includes submit() and post() methods on the abstraction so buttons
		 * within the container can trigger form actions.
		 * @param string $id WakaPAC component id
		 * @param array $extra Additional abstraction properties as JS string snippets
		 * @param array $abstraction Key-value pairs from the node's abstraction property (scalars and arrays only)
		 * @param array $scripts Script snippets from the builder's script() calls
		 * @param array $fieldRules Map of fieldName => JS rule constructor strings, from collectFieldRules().
		 *                            When non-empty, a wakaForm.createForm() call is emitted and the form
		 *                            proxy is injected into the abstraction as 'form'.
		 * @return string
		 */
		protected function buildScript(string $id, array $extra = [], array $abstraction = [], array $scripts = [], array $fieldRules = [], bool $clientValidation = false, array $serverErrors = []): string {
			$abstractionJs = '';
			
			foreach ($abstraction as $key => $value) {
				if (!is_scalar($value) && !is_array($value)) {
					throw new \InvalidArgumentException("Abstraction property \"{$key}\" must be a scalar or array.");
				}
				
				$abstractionJs .= $key . ': ' . json_encode($value) . ",\n        ";
			}
			
			// Merge caller-supplied extra snippets with script() snippets from the builder.
			// Trim trailing commas and whitespace from each snippet so the join comma is never doubled.
			$allExtra = array_merge($extra, $scripts);
			$allExtra = array_map(fn($s) => rtrim(trim($s), ','), $allExtra);
			$extraJs = !empty($allExtra) ? implode(",\n        ", $allExtra) . ',' : '';
			$notificationsId = "{$id}-notifications";
			
			// Build createForm() call when field rules are present (client validation enabled).
			if (!empty($fieldRules)) {
				$schemaEntries = '';
				$data = $this->loom->getData();
				
				foreach ($fieldRules as $fieldName => $jsRules) {
					$rulesJs = implode(', ', $jsRules);
					$initialValid = array_key_exists($fieldName, $serverErrors) ? 'false' : 'true';
					$initialValue = json_encode((string)($data[$fieldName] ?? ''));
					$schemaEntries .= "        {$fieldName}: { value: {$initialValue}, valid: {$initialValid}, rules: [{$rulesJs}] },\n";
				}
				
				$formInit = <<<JS

    const form = wakaForm.createForm({
{$schemaEntries}    });

    wakaPAC.installMessageHook(function(event, callNextHook) {
        if (event.message === wakaPAC.MSG_SUBMIT && event.pacId === '{$id}') {
            event.originalEvent.preventDefault();
            var context = window.PACRegistry.get('{$id}');
            if (context) { context.abstraction.validateAndSubmit(); }
            return;
        }
        callNextHook();
    });

JS;
				$formProperty = "\n        submitted: " . (!empty($serverErrors) ? 'true' : 'false') . ",\n        form,";
			} else {
				$formInit = '';
				$formProperty = '';
			}
			
			return <<<JS
(function() {
{$formInit}
    wakaPAC('{$id}', {{$formProperty}
        {$abstractionJs}{$extraJs}

        /**
         * Submits the form natively via the browser.
         * Use as a click action: data-pac-bind="click: submit()"
         */
        submit() {
            this.container.submit();
        },

        /**
         * Posts the form data to a custom endpoint via fetch.
         * Use as a click action: data-pac-bind="click: post('/custom/endpoint')"
         * @param {string} url - The endpoint URL to post to
         */
        post(url) {
            fetch(url, {
                method: 'POST',
                body: new FormData(this.container)
            });
        },

        /**
         * Dismisses all notifications.
         */
        dismiss() {
            const el = document.getElementById('{$notificationsId}');

            if (el) {
                el.remove();
            }
        }
    }, {
        hydrate: true
    });
})();
JS;
		}
	}