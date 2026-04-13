<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * The Loom render engine
	 */
	class Loom {
		
		/** @var array<string, class-string<RendererInterface>> */
		private array $registry = [];
		
		/** @var array Current data context for the active render pass, populated by render() and accessed by renderers via getData() */
		private array $currentData = [];
		
		/**
		 * Register a custom renderer for a given type, overriding the default convention
		 * @param string $type
		 * @param class-string<RendererInterface> $class
		 */
		public function register(string $type, string $class): void {
			$this->registry[$type] = $class;
		}
		
		/**
		 * Render a node tree to an HTML string with an optional inline script block.
		 * This is the main entry point for rendering a Loom page definition.
		 * @param array $node Root node of the JSON page definition
		 * @param array $options Supported options: 'part' => 'full' | 'header' | 'body'
		 * @param array $data Entity data to populate field values, keyed by field name
		 * @return string        Rendered HTML with an inline script block if scripts were generated
		 */
		public function render(array $node, array $options = [], array $data = []): string {
			// Store data as context so renderers can access it via getData()
			$this->currentData = $data;
			
			// Inject render part into root node properties so ResourceRenderer
			// knows which part to render
			if (isset($options['part'])) {
				$node['properties']['_render_part'] = $options['part'];
			}
			
			$result = $this->_render($node);
			
			// No scripts generated — return HTML only
			if (empty($result->scripts)) {
				return $result->html;
			}
			
			// Append inline script block with all collected scripts
			$scripts = implode("\n", $result->scripts);
			return $result->html . "\n<script>\n{$scripts}\n</script>";
		}
		
		/**
		 * Returns the current data context for the active render pass.
		 * Called by renderers to resolve field values against entity data.
		 * @return array
		 */
		public function getData(): array {
			return $this->currentData;
		}
		
		/**
		 * Recursively render a node and all its children, collecting HTML and scripts.
		 * Children are always rendered before their parent — the engine works depth-first,
		 * so each renderer receives its children as a fully rendered HTML string.
		 * @param array $node Current node to render
		 * @param array|null $parent Parent node, passed to renderers so they can read parent properties
		 * @return RenderResult      Combined HTML and scripts for this node and all its descendants
		 * @internal Do not call directly — use render() instead
		 */
		public function _render(array $node, ?array $parent = null): RenderResult {
			$childHtml = '';
			$scripts = [];
			
			// Render all children first (depth-first) so each renderer receives
			// already-rendered HTML for its children
			foreach ($node['children'] ?? [] as $i => $child) {
				$result = $this->_render($child, $node);
				$childHtml .= $result->html;
				
				// Collect scripts from children — they must appear before parent scripts
				// to ensure child components are initialised before their parents
				$scripts = array_merge($scripts, $result->scripts);
			}
			
			// Resolve and invoke the renderer for this node type
			$renderer = $this->getRenderer($node['type']);
			$result = $renderer->render($node['properties'] ?? [], $childHtml, $parent, $i ?? 0);
			
			// Merge child scripts before parent scripts
			$result->scripts = array_merge($scripts, $result->scripts);
			
			// Return result
			return $result;
		}
		
		/**
		 * Resolve a type string to a renderer instance.
		 * Checks the registry first, then falls back to the naming convention.
		 * @param string $type
		 * @return RendererInterface
		 * @throws \RuntimeException
		 */
		private function getRenderer(string $type): RendererInterface {
			// Check registry first (custom or overridden renderers)
			if (isset($this->registry[$type])) {
				$class = $this->registry[$type];
				return new $class();
			}
			
			// Fall back to naming convention: "button" -> ButtonRenderer
			$class = 'Quellabs\\Canvas\\Loom\\Renderer\\' . ucfirst($type) . 'Renderer';
			
			if (!class_exists($class)) {
				throw new \RuntimeException(
					"No renderer found for type \"{$type}\". " .
					"Create Quellabs\\Canvas\\Loom\\Renderer\\" . ucfirst($type) . "Renderer " .
					"or register a custom renderer via Engine::register()."
				);
			}
			
			return new $class($this);
		}
	}