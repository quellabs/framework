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
		
		/** @var array Current data context for the active render pass, populated by render() and accessed by renderers via getData() */
		private array $currentData = [];
		
		/** @var array Notifications to display in the rendered form */
		private array $notifications = [];
		
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
		 * @param array $data Entity data to populate field values, keyed by field name
		 * @param array $options Supported options: 'part' => 'full' | 'header' | 'body'
		 * @return string        Rendered HTML with an inline script block if scripts were generated
		 */
		public function render(array $node, array $data = [], array $options = []): string {
			// Save and restore surrounding context so nested render() calls
			// (e.g. rendering header and body separately on the same instance)
			// do not pollute each other's data or notifications.
			$previousData          = $this->currentData;
			$previousNotifications = $this->notifications;
			
			$this->currentData = $data;

			// If the root node has use_wakaform, inject _use_wakaform into the
			// data context so FieldRenderer can read it during child rendering.
			if (!empty($node['properties']['use_wakaform'])) {
				$this->currentData['_use_wakaform'] = true;
			}
			
			// Inject render part into root node properties so ResourceRenderer
			// knows which part to render
			if (isset($options['part'])) {
				$node['properties']['_render_part'] = $options['part'];
			}
			
			$result = $this->renderNode($node);
			
			// Restore previous context so sequential calls on the same instance
			// are isolated from each other
			$this->currentData   = $previousData;
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
		private function renderNode(array $node, ?array $parent = null): RenderResult {
			$childHtml = '';
			$scripts   = [];
			$childCount = 0;
			
			foreach ($node['children'] ?? [] as $i => $child) {
				$result     = $this->renderNode($child, $node);
				$childHtml .= $result->html;
				$childCount++;
				
				if ($result->script !== null) {
					$scripts[] = $result->script;
				}
			}
			
			// Pass raw child nodes to renderer so container renderers can
			// inspect the node tree without relying on already-rendered HTML
			$properties              = $node['properties'] ?? [];
			$properties['_children'] = $node['children']   ?? [];
			
			$renderer = $this->getRenderer($node['type']);
			$result   = $renderer->render($properties, $childHtml, $parent, $childCount > 0 ? $i : 0);
			
			if ($result->script !== null) {
				$scripts[] = $result->script;
			}
			
			// Return a new result with accumulated scripts collapsed back to a single
			// string so the parent can continue accumulating
			return new RenderResult($result->html, !empty($scripts) ? implode("\n", $scripts) : null);
		}
		
		/**
		 * Validate submitted data against the rules defined on field nodes in the tree.
		 * Walks the node tree, finds all field nodes with rules, runs each rule against
		 * the corresponding value in $data, and returns the first failing error per field.
		 *
		 * Usage in a controller:
		 *   $result = $loom->validate($resource->build(), $request->post());
		 *   if ($result->fails()) {
		 *       return $loom->render($resource->build(), array_merge($request->post(), ['_errors' => $result->errors()]));
		 *   }
		 *
		 * @param array $node Root node of the page definition (from Resource::build())
		 * @param array $data Submitted form data, keyed by field name
		 * @return ValidationResult
		 */
		public function validate(array $node, array $data): ValidationResult {
			$errors = [];
			$this->validateNode($node, $data, $errors);
			return new ValidationResult($errors);
		}

		/**
		 * Recursively walk the node tree and validate all field nodes that have rules.
		 * @param array  $node
		 * @param array  $data
		 * @param array  $errors Collected errors, passed by reference
		 */
		private function validateNode(array $node, array $data, array &$errors): void {
			if (($node['type'] ?? '') === 'field') {
				$rules = $node['properties']['rules'] ?? [];
				$name  = $node['properties']['name']  ?? '';

				if ($name && !empty($rules)) {
					$value = $data[$name] ?? null;

					foreach ($rules as $rule) {
						if (!$rule->validate($value)) {
							$errors[$name] = $rule->getError();
							// First failing rule wins — consistent with WakaForm behaviour
							break;
						}
					}
				}
			}

			foreach ($node['children'] ?? [] as $child) {
				$this->validateNode($child, $data, $errors);
			}
		}

		/**
		 * Add a notification to display in the rendered form.
		 * @param string $type    Notification type: success|error|warning|info
		 * @param string $message Notification message
		 * @return static
		 */
		public function notification(string $type, string $message): static {
			$this->notifications[] = ['type' => $type, 'message' => $message];
			return $this;
		}
		
		/**
		 * Returns all queued notifications.
		 * @return array
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
				return $this->rendererCache[$class] ??= new $class($this);
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
			
			return $this->rendererCache[$class] ??= new $class($this);
		}
	}