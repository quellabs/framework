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
		 * Recursively collect array properties from all field nodes in the tree.
		 * Used to inject dependent dropdown options and other array data into
		 * the WakaPAC state before the component is initialised.
		 * @param array $nodes
		 * @return array
		 */
		protected function collectFieldProperties(array $nodes): array {
			$state = [];
			
			foreach ($nodes as $node) {
				if (($node['type'] ?? '') === 'field') {
					$name = $node['properties']['name'] ?? '';
					
					if ($name) {
						foreach ($node['properties'] as $key => $value) {
							// Only include array properties — use pluralized name to match foreach_expression
							if (is_array($value) && $key === 'options') {
								$state[StringInflector::pluralize($name)] = $value;
							}
						}
					}
				}
				
				// Recurse into children
				if (!empty($node['children'])) {
					$state = array_merge($state, $this->collectFieldProperties($node['children']));
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
        }
    }, { hydrate: true });
})();
JS;
		}
	}