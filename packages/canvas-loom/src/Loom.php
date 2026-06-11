<?php
	
	namespace Quellabs\Canvas\Loom;
	
	use Quellabs\Canvas\Loom\Validation\ValidationResult;
	
	/**
	 * The Loom render engine
	 */
	class Loom {
		
		/** @var array<string, class-string<RendererInterface>> Registered custom renderers, keyed by type */
		private array $registry = [];
		
		/** @var array<string, RendererInterface> Renderer instance cache, keyed by class name */
		private array $rendererCache = [];
		
		/** @var array<string, mixed> Current data context for the active render pass, populated by render() and accessed by renderers via getData() */
		private array $currentData = [];
		
		/** @var array<int, array<string, string>> Notifications to display in the rendered form */
		private array $notifications = [];
		
		/**
		 * Register a custom renderer for a given type, overriding the default convention.
		 * @param string $type
		 * @param class-string<RendererInterface> $class
		 * @throws \InvalidArgumentException if the class does not exist or does not implement RendererInterface
		 */
		public function register(string $type, string $class): void {
			if (!class_exists($class)) {
				throw new \InvalidArgumentException("Renderer class \"{$class}\" does not exist.");
			}
			
			if (!is_subclass_of($class, RendererInterface::class)) {
				throw new \InvalidArgumentException("Renderer class \"{$class}\" must implement " . RendererInterface::class . ".");
			}
			
			$this->registry[$type] = $class;
		}
		
		/**
		 * Render a node tree to an HTML string with an optional inline script block.
		 * This is the main entry point for rendering a Loom page definition.
		 * @param array<string, mixed> $node Root node of the JSON page definition
		 * @param array<string, mixed> $data Entity data to populate field values, keyed by field name
		 * @param array<string, mixed> $options Supported options: 'part' => 'full' | 'header' | 'body'
		 * @return string Rendered HTML with an inline script block if scripts were generated
		 */
		public function render(array $node, array $data = [], array $options = []): string {
			// Save and restore surrounding context so nested render() calls
			// (e.g. rendering header and body separately on the same instance)
			// do not pollute each other's data or notifications.
			$previousData = $this->currentData;
			$previousNotifications = $this->notifications;
			
			$this->currentData = $data;
			
			// If the root node has use_wakaform, inject _use_wakaform into the
			// data context so FieldRenderer can read it during child rendering.
			/** @var array<string, mixed> $rootProps */
			$rootProps = is_array($node['properties'] ?? null) ? $node['properties'] : [];
			
			if (!empty($rootProps['use_wakaform'])) {
				$this->currentData['_use_wakaform'] = true;
			}
			
			// If the root node carries an entity_prefix (set by EntityReader), inject
			// it into the data context so FieldRenderer can scope the HTML name attribute
			// (e.g. PostEntity[title]) without affecting WakaPAC bindings or value resolution.
			if (!empty($rootProps['entity_prefix'])) {
				$this->currentData['_entity_prefix'] = $rootProps['entity_prefix'];
			}
			
			// If the root node carries entity_data (field values extracted from the entity
			// by EntityReader), inject it into the data context so FieldRenderer can use
			// it as a fallback when no explicit value is present in the caller's data array.
			if (!empty($rootProps['entity_data'])) {
				$this->currentData['_entity_data'] = $rootProps['entity_data'];
			}
			
			// Inject render part into root node properties so ResourceRenderer
			// knows which part to render
			if (isset($options['part'])) {
				$rootProps['_render_part'] = $options['part'];
				$node['properties'] = $rootProps;
			}
			
			$result = $this->renderNode($node);
			
			// Restore previous context so sequential calls on the same instance
			// are isolated from each other
			$this->currentData = $previousData;
			$this->notifications = $previousNotifications;
			
			// No scripts generated — return HTML only
			if ($result->script === null) {
				return $result->html;
			}
			
			// Append inline script block
			return $result->html . "\n<script>\n{$result->script}\n</script>";
		}
		
		/**
		 * Returns the current data context for the active render pass.
		 * Called by renderers to resolve field values against entity data.
		 * @return array<string, mixed>
		 */
		public function getData(): array {
			return $this->currentData;
		}
		
		/**
		 * Recursively render a node and all its children, collecting HTML and scripts.
		 * Children are always rendered before their parent — the engine works depth-first,
		 * so each renderer receives its children as a fully rendered HTML string.
		 * @param array<string, mixed> $node Current node to render
		 * @param array<string, mixed>|null $parent Parent node, passed to renderers so they can read parent properties
		 * @return RenderResult      Combined HTML and scripts for this node and all its descendants
		 * @internal Do not call directly — use render() instead
		 */
		private function renderNode(array $node, ?array $parent = null): RenderResult {
			$childHtml = '';
			$scripts = [];
			$childCount = 0;
			
			/** @var array<int, array<string, mixed>> $nodeChildren */
			$nodeChildren = is_array($node['children'] ?? null) ? $node['children'] : [];
			
			foreach ($nodeChildren as $i => $child) {
				$result = $this->renderNode($child, $node);
				$childHtml .= $result->html;
				$childCount++;
				
				if ($result->script !== null) {
					$scripts[] = $result->script;
				}
			}
			
			// Pass raw child nodes to renderer so container renderers can
			// inspect the node tree without relying on already-rendered HTML
			/** @var array<string, mixed> $properties */
			$properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
			/** @var array<int, array<string, mixed>> $rawChildren */
			$rawChildren = is_array($node['children'] ?? null) ? $node['children'] : [];
			$properties['_children'] = $rawChildren;
			
			$nodeType = is_string($node['type'] ?? null) ? $node['type'] : '';
			$renderer = $this->getRenderer($nodeType);
			$lastIndex = ($childCount > 0 && isset($i) && is_int($i)) ? $i : 0;
			$result = $renderer->render($properties, $childHtml, $parent, $lastIndex);
			
			if ($result->script !== null) {
				$scripts[] = $result->script;
			}
			
			// Return a new result with accumulated scripts collapsed back to a single
			// string so the parent can continue accumulating
			return new RenderResult($result->html, !empty($scripts) ? implode("\n", $scripts) : null);
		}
		
		/**
		 * Add a notification to display in the rendered form.
		 * @param string $type Notification type: success|error|warning|info
		 * @param string $message Notification message
		 * @return static
		 */
		public function notification(string $type, string $message): static {
			$this->notifications[] = ['type' => $type, 'message' => $message];
			return $this;
		}
		
		/**
		 * Returns all queued notifications.
		 * @return array<int, array<string, string>>
		 */
		public function getNotifications(): array {
			return $this->notifications;
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
				
				/** @var RendererInterface $renderer */
				$renderer = new $class($this);
				return $this->rendererCache[$class] ??= $renderer;
			}
			
			// Fall back to naming convention: "button" -> ButtonRenderer
			$class = 'Quellabs\\Canvas\\Loom\\Renderer\\' . ucfirst($type) . 'Renderer';
			
			if (!class_exists($class)) {
				throw new \RuntimeException(
					"No renderer found for type \"{$type}\". " .
					"Create Quellabs\\Canvas\\Loom\\Renderer\\" . ucfirst($type) . "Renderer " .
					"or register a custom renderer via Loom::register()."
				);
			}
			
			/** @var RendererInterface $renderer */
			$renderer = new $class($this);
			return $this->rendererCache[$class] ??= $renderer;
		}
	}