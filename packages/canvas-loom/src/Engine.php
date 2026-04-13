<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * The Loom render engine
	 */
	class Engine {
		
		/** @var array<string, class-string<RendererInterface>> */
		private array $registry = [];
		
		/**
		 * Register a custom renderer for a given type, overriding the default convention
		 * @param string $type
		 * @param class-string<RendererInterface> $class
		 */
		public function register(string $type, string $class): void {
			$this->registry[$type] = $class;
		}
		
		/**
		 * Render a node tree and return the combined HTML and scripts
		 * @param array $node
		 * @return RenderResult
		 */
		public function render(array $node): RenderResult {
			// Render children first, collecting their scripts along the way
			$childHtml = '';
			$scripts = [];
			
			foreach ($node['children'] ?? [] as $child) {
				$result = $this->render($child);
				$childHtml .= $result->html;
				$scripts = array_merge($scripts, $result->scripts);
			}
			
			// Render this node using the appropriate renderer
			$renderer = $this->getRenderer($node['type']);
			$result = $renderer->render($node['properties'] ?? [], $childHtml);
			
			// Merge children scripts with this node's scripts
			$result->scripts = array_merge($scripts, $result->scripts);
			
			return $result;
		}
		
		/**
		 * Render a node tree and return the final HTML with an inline script block
		 * @param array $node
		 * @return string
		 */
		public function renderToString(array $node): string {
			$result = $this->render($node);
			
			if (empty($result->scripts)) {
				return $result->html;
			}
			
			$scripts = implode("\n", $result->scripts);
			return $result->html . "\n<script>\n{$scripts}\n</script>";
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
			
			return new $class();
		}
	}