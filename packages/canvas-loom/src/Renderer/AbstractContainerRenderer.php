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
		 * @param array<int, array<string, mixed>> $nodes
		 * @return array<string, string[]> fieldName => array of toJs() strings
		 */
		protected function collectFieldRules(array $nodes): array {
			$result = [];
			
			foreach ($nodes as $node) {
				/** @var array<string, mixed> $nodeProps */
				$nodeProps = is_array($node['properties'] ?? null) ? $node['properties'] : [];
				
				if (($node['type'] ?? '') === 'field') {
					$name = $nodeProps['name'] ?? '';
					$rules = $nodeProps['rules'] ?? [];
					
					if (is_string($name) && $name !== '' && is_array($rules) && !empty($rules)) {
						$jsRules = [];
						
						foreach ($rules as $rule) {
							if (!is_object($rule) || !method_exists($rule, 'wakaFormSupported') || !$rule->wakaFormSupported()) {
								// Rule has no JS equivalent — skip silently
								continue;
							}
							
							$jsRules[] = $rule->toJs();
						}
						
						if (!empty($jsRules)) {
							$result[$name] = $jsRules;
						}
					}
				}
				
				/** @var array<int, array<string, mixed>> $nodeChildren */
				$nodeChildren = is_array($node['children'] ?? null) ? $node['children'] : [];
				
				if (!empty($nodeChildren)) {
					foreach ($this->collectFieldRules($nodeChildren) as $name => $jsRules) {
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
		 * @param array<int, array<string, mixed>> $nodes
		 * @return array<string, mixed>
		 */
		protected function collectFieldProperties(array $nodes): array {
			$state = [];
			
			foreach ($nodes as $node) {
				/** @var array<string, mixed> $nodeProps */
				$nodeProps = is_array($node['properties'] ?? null) ? $node['properties'] : [];
				
				if (($node['type'] ?? '') === 'field') {
					$name = $nodeProps['name'] ?? '';
					$options = $nodeProps['options'] ?? null;
					
					if (is_string($name) && $name !== '' && is_array($options)) {
						$state[StringInflector::pluralize($name)] = $options;
					}
				}
				
				/** @var array<int, array<string, mixed>> $nodeChildren */
				$nodeChildren = is_array($node['children'] ?? null) ? $node['children'] : [];
				
				// Recurse into children
				if (!empty($nodeChildren)) {
					foreach ($this->collectFieldProperties($nodeChildren) as $k => $v) {
						$state[$k] = $v;
					}
				}
			}
			
			return $state;
		}
		
		/**
		 * Recursively collect all field names from a node subtree.
		 * Used by TabsRenderer to enumerate which fields belong to each tab,
		 * both for server-side error detection at render time and for emitting
		 * the data-loom-tab-fields attribute that drives client-side JS updates.
		 * Hidden fields are excluded — they carry no validation state.
		 * @param array<int, array<string, mixed>> $nodes
		 * @return string[] Flat list of field name strings
		 */
		protected function collectFieldNames(array $nodes): array {
			$names = [];
			
			foreach ($nodes as $node) {
				/** @var array<string, mixed> $nodeProps */
				$nodeProps = is_array($node['properties'] ?? null) ? $node['properties'] : [];
				
				if (($node['type'] ?? '') === 'field') {
					$name = $nodeProps['name'] ?? '';
					$input = $nodeProps['input'] ?? '';
					
					// Hidden fields have no visible validation state — exclude them
					if (is_string($name) && $name !== '' && $input !== 'hidden') {
						$names[] = $name;
					}
				}
				
				/** @var array<int, array<string, mixed>> $nodeChildren */
				$nodeChildren = is_array($node['children'] ?? null) ? $node['children'] : [];
				
				if (!empty($nodeChildren)) {
					foreach ($this->collectFieldNames($nodeChildren) as $name) {
						$names[] = $name;
					}
				}
			}
			
			return $names;
		}
		
		/**
		 * Recursively scan a node tree to determine whether WakaPAC initialisation
		 * is actually needed. Resource and Panel skip buildScript() entirely if not.
		 *
		 * WakaPAC is required if any node in the tree:
		 * - Has a pac_bind property (data-pac-bind attribute)
		 * - Has scripts or abstraction properties set on a container
		 * - Is a text node with {{ }} interpolation in its value
		 * - Has validation rules (field.valid bindings)
		 * - Has use_wakaform enabled on the resource
		 * @param array<int, array<string, mixed>> $nodes
		 * @return bool
		 */
		protected function requiresWakaPAC(array $nodes): bool {
			foreach ($nodes as $node) {
				$type = is_string($node['type'] ?? null) ? $node['type'] : '';
				/** @var array<string, mixed> $properties */
				$properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
				
				// Field with validation rules needs WakaForm bindings (visible: !form.x.valid)
				if ($type === 'field' && !empty($properties['rules'])) {
					return true;
				}
				
				// Resource with WakaForm enabled always needs WakaPAC
				if (!empty($properties['use_wakaform'])) {
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
				if ($type === 'text' && isset($properties['value']) && is_string($properties['value']) && str_contains($properties['value'], '{{')) {
					return true;
				}
				
				// Field hint with WakaPAC interpolation
				if ($type === 'field' && isset($properties['hint']) && is_string($properties['hint']) && str_contains($properties['hint'], '{{')) {
					return true;
				}
				
				// Button with an action expression
				if ($type === 'button' && !empty($properties['action'])) {
					return true;
				}
				
				// Recurse into children
				/** @var array<int, array<string, mixed>> $recurseChildren */
				$recurseChildren = is_array($node['children'] ?? null) ? $node['children'] : [];
				
				if (!empty($recurseChildren) && $this->requiresWakaPAC($recurseChildren)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Generate the WakaPAC initialisation script for a container component.
		 *
		 * Acts as the top-level assembler: serialises the abstraction object,
		 * optionally prepends a WakaForm block, then wraps everything in a
		 * self-contained IIFE with the standard submit/post/dismiss methods.
		 *
		 * @param string $id WakaPAC component id
		 * @param array<int, string> $extra Additional abstraction method snippets as raw JS strings
		 * @param array<string, mixed> $abstraction Key-value pairs from the node's abstraction property
		 *                                 (scalars and arrays only — anything else throws)
		 * @param array<int, string> $scripts Script snippets from the builder's script() calls,
		 *                                 appended after $extra in the abstraction body
		 * @param array<string, string[]> $fieldRules Map of fieldName => JS rule constructor strings from
		 *                                 collectFieldRules(). When non-empty and $clientValidation
		 *                                 is true, a wakaForm.createForm() block is emitted and the
		 *                                 form proxy is injected as the 'form' property.
		 * @param bool $clientValidation Whether WakaForm client-side validation is active
		 * @param array<string, string> $serverErrors Map of fieldName => error(s) from the last server round-trip,
		 *                                 used to pre-mark fields invalid after a failed submission
		 * @return string Ready-to-emit JavaScript wrapped in an IIFE
		 */
		protected function buildScript(string $id, array $extra = [], array $abstraction = [], array $scripts = [], array $fieldRules = [], bool $clientValidation = false, array $serverErrors = []): string {
			// Serialise the node's abstraction map to `key: value,` JS property lines
			$abstractionJs = $this->serializeAbstraction($abstraction);
			
			// Merge caller extra snippets and builder script() snippets into a comma-joined JS block
			$extraJs = $this->serializeExtraSnippets($extra, $scripts);
			$notificationsId = "{$id}-notifications";
			
			// Build the WakaForm createForm() preamble and the form/submitted property injection.
			// Both are empty strings when client validation is off so the IIFE template is always
			// interpolated unconditionally regardless of whether validation is active.
			if ($clientValidation && !empty($fieldRules)) {
				$wakaForm = $this->buildWakaFormBlock($id, $fieldRules, $serverErrors);
				$formInit = $wakaForm['formInit'];
				$formProperty = $wakaForm['formProperty'];
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
            // Call the native HTMLFormElement.prototype.submit() directly.
            // Unlike form.requestSubmit(), the native .submit() does not fire
            // the submit event, so the wakaPAC listener does not re-intercept
            // this call and no bypass flag is needed.
            HTMLFormElement.prototype.submit.call(this.container);
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
		
		/**
		 * Serialise the node's abstraction map to inline JS object properties.
		 *
		 * Each entry becomes a `key: <json_value>,` line ready to be placed
		 * directly inside a wakaPAC() abstraction literal. Only scalars and
		 * arrays are accepted — closures or objects would not survive
		 * json_encode and are rejected early with a clear message.
		 *
		 * @param array<string, mixed> $abstraction Key-value pairs from the node's abstraction property
		 * @return string Zero or more `key: value,\n` lines, or an empty string when $abstraction is empty
		 * @throws \InvalidArgumentException When a value is neither scalar nor array
		 */
		protected function serializeAbstraction(array $abstraction): string {
			$js = '';
			
			foreach ($abstraction as $key => $value) {
				if (!is_scalar($value) && !is_array($value)) {
					throw new \InvalidArgumentException("Abstraction property \"{$key}\" must be a scalar or array.");
				}
				
				$js .= $key . ': ' . json_encode($value) . ",\n        ";
			}
			
			return $js;
		}
		
		/**
		 * Merge and normalise the caller extra snippets and builder script() snippets
		 * into a single JS string ready for interpolation inside a wakaPAC() literal.
		 *
		 * Snippets are joined with commas. Trailing commas already present on individual
		 * snippets are stripped first so the separator is never doubled. When there are
		 * no snippets the returned string is empty, producing no output in the template.
		 *
		 * @param array<int, string> $extra Raw JS method/property snippets supplied by the renderer
		 * @param array<int, string> $scripts Raw JS snippets from the node builder's script() calls
		 * @return string Comma-joined snippet block with a trailing comma, or an empty string
		 */
		protected function serializeExtraSnippets(array $extra, array $scripts): string {
			$all = array_merge($extra, $scripts);
			
			if (empty($all)) {
				return '';
			}
			
			// Normalise each snippet: strip surrounding whitespace and any trailing comma
			// so the re-join below never produces `method() { ... },,` double-comma artefacts.
			$all = array_map(fn($s) => rtrim(trim($s), ','), $all);
			
			return implode(",\n        ", $all) . ',';
		}
		
		/**
		 * Build the WakaForm createForm() block and the accompanying MSG_SUBMIT hook.
		 *
		 * This block is only emitted when client-side validation is active and at
		 * least one field has rules. It produces two things returned as a tuple:
		 *
		 * 1. $formInit  — the IIFE-level preamble: a `const form = wakaForm.createForm({…})`
		 *                 call (with per-field value, valid, and rules entries) followed by a
		 *                 wakaPAC.installMessageHook() that intercepts MSG_SUBMIT and routes it
		 *                 through validateAndSubmit() instead of a native submit.
		 *
		 * 2. $formProperty — the `submitted` flag and `form,` shorthand property injected at
		 *                    the top of the wakaPAC() abstraction literal so every method in
		 *                    the component has access to the form proxy.
		 *
		 * Fields that appear in $serverErrors are pre-marked invalid (valid: false) so
		 * validation feedback is visible immediately after a failed server round-trip
		 * without requiring the user to interact with the field first.
		 *
		 * @param string $id WakaPAC component id, used in the message hook comparison
		 * @param array<string, string[]> $fieldRules Map of fieldName => JS rule constructor strings
		 * @param array<string, string> $serverErrors Map of fieldName => error(s) from the last server submission
		 * @return array{formInit: string, formProperty: string}
		 */
		protected function buildWakaFormBlock(string $id, array $fieldRules, array $serverErrors): array {
			$schemaEntries = '';
			$data = $this->loom->getData();
			
			foreach ($fieldRules as $fieldName => $jsRules) {
				$rulesJs = implode(', ', $jsRules);
				
				// Pre-mark the field invalid when the server returned an error for it
				// so the user sees inline feedback immediately on page load.
				$initialValid = array_key_exists($fieldName, $serverErrors) ? 'false' : 'true';
				$initialValue = json_encode((string)($data[$fieldName] ?? ''));
				$schemaEntries .= "        {$fieldName}: { value: {$initialValue}, valid: {$initialValid}, rules: [{$rulesJs}] },\n";
			}
			
			$formInit = <<<JS

    const form = wakaForm.createForm({
{$schemaEntries}    });

    wakaPAC.installMessageHook(function(event, callNextHook) {
        if (event.message === wakaPAC.MSG_SUBMIT && event.pacId === '{$id}') {
            event.preventDefault();
            
            const context = wakaPAC.getContextByPacId('{$id}');
            
            if (context) {
                context.abstraction.validateAndSubmit();
            }
            
            return;
        }
        
        callNextHook();
    });

JS;
			// submitted is seeded true when there are server errors so the form
			// immediately shows validation state without a first submit attempt.
			$formProperty = "\n        submitted: " . (!empty($serverErrors) ? 'true' : 'false') . ",\n        form,";
			
			return ['formInit' => $formInit, 'formProperty' => $formProperty];
		}
	}