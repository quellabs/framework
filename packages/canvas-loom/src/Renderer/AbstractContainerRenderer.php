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
					$name    = $node['properties']['name']    ?? '';
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
		 * Generate the WakaPAC initialisation script for a container component.
		 * Includes submit() and post() methods on the abstraction so buttons
		 * within the container can trigger form actions.
		 * @param string $id          WakaPAC component id
		 * @param array  $extra       Additional abstraction properties as JS string snippets
		 * @param array  $abstraction Key-value pairs from the node's abstraction property (scalars and arrays only)
		 * @return string
		 */
		protected function buildScript(string $id, array $extra = [], array $abstraction = [], array $scripts = []): string {
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
			$extraJs  = !empty($allExtra) ? implode(",\n        ", $allExtra) . ',' : '';
			$notificationsId = "{$id}-notifications";

			return <<<JS
(function() {
    wakaPAC('{$id}', {
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
    }, { hydrate: true });
})();
JS;
		}
	}