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
		 * @param string $id        WakaPAC component id
		 * @param array  $extra     Additional abstraction properties as JS string snippets
		 * @return string
		 */
		protected function buildScript(string $id, array $extra = []): string {
			$extraJs = !empty($extra) ? implode(",\n        ", $extra) . ',' : '';
			$notificationsId = "{$id}-notifications";

			return <<<JS
(function() {
    wakaPAC('{$id}', {
        {$extraJs}

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