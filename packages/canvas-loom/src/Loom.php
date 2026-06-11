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
		 * @return string        Rendered HTML with an inline script block if scripts were generated
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
			if (!empty($node['properties']['use_wakaform'])) {
				$this->currentData['_use_wakaform'] = true;
			}
			
			// If the root node carries an entity_prefix (set by EntityReader), inject
			// it into the data context so FieldRenderer can scope the HTML name attribute
			// (e.g. PostEntity[title]) without affecting WakaPAC bindings or value resolution.
			if (!empty($node['properties']['entity_prefix'])) {
				$this->currentData['_entity_prefix'] = $node['properties']['entity_prefix'];
			}
			
			// If the root node carries entity_data (field values extracted from the entity
			// by EntityReader), inject it into the data context so FieldRenderer can use
			// it as a fallback when no explicit value is present in the caller's data array.
			if (!empty($node['properties']['entity_data'])) {
				$this->currentData['_entity_data'] = $node['properties']['entity_data'];
			}
			
			// Inject render part into root node properties so ResourceRenderer
			// knows which part to render
			if (isset($options['part'])) {
				$node['properties']['_render_part'] = $options['part'];
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
			
			foreach ($node['children'] ?? [] as $i => $child) {
				$result = $this->renderNode($child, $node);
				$childHtml .= $result->html;
				$childCount++;
				
				if ($result->script !== null) {
					$scripts[] = $result->script;
				}
			}
			
			// Pass raw child nodes to renderer so container renderers can
			// inspect the node tree without relying on already-rendered HTML
			$properties = $node['properties'] ?? [];
			$properties['_children'] = $node['children'] ?? [];
			
			$renderer = $this->getRenderer($node['type']);
			$result = $renderer->render($properties, $childHtml, $parent, $childCount > 0 ? $i : 0);
			
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
		 * When the resource was built via makeFromEntity(), submitted field names are scoped
		 * to the entity prefix (e.g. PostEntity[title]). validate() detects the entity_prefix
		 * on the root node and unwraps the prefixed subset automatically, so the controller
		 * can pass the raw request data without any manual extraction:
		 *
		 *   $result = $loom->validate($resource->build(), $request->request->all());
		 *
		 * @param array<string, mixed> $node Root node of the page definition (from Resource::build())
		 * @param array<string, mixed> $data Submitted form data — either flat or prefixed by entity name
		 * @return ValidationResult
		 */
		public function validate(array $node, array $data): ValidationResult {
			// When the form was built from an entity, submitted data arrives prefixed
			// (e.g. ['PostEntity' => ['title' => '...']]) — unwrap it automatically
			// so validation always works against bare field names.
			$prefix = $node['properties']['entity_prefix'] ?? null;
			
			if ($prefix !== null && isset($data[$prefix]) && is_array($data[$prefix])) {
				$data = $data[$prefix];
			}
			
			$errors = [];
			$this->validateNode($node, $data, $errors);
			return new ValidationResult($errors);
		}
		
		/**
		 * Recursively walk the node tree and validate all field nodes that have rules.
		 * @param array<string, mixed> $node
		 * @param array<string, mixed> $data
		 * @param array<string, string> $errors Collected errors, passed by reference
		 * @return void
		 */
		private function validateNode(array $node, array $data, array &$errors): void {
			if (($node['type'] ?? '') === 'field') {
				$rules = $node['properties']['rules'] ?? [];
				$name = $node['properties']['name'] ?? '';
				
				if ($name && !empty($rules)) {
					$value = $data[$name] ?? null;
					$errorOverride = $node['properties']['error_message'] ?? null;
					
					foreach ($rules as $rule) {
						if (!$rule->validate($value)) {
							$errors[$name] = $errorOverride ?? $rule->getError();
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