<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * The Loom render engine
	 *
	 * @phpstan-import-type LoomNode from NodeUtil
	 * @phpstan-import-type LoomNodeList from NodeUtil
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
		 * @phpstan-param LoomNode $node Root node of the JSON page definition
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
			$rootProps = NodeUtil::properties($node);
			
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
		 * Returns the server-side validation errors from the current render pass.
		 * Keys are field names; values are error message strings.
		 * Returns an empty array when no errors are present.
		 * @return array<string, string>
		 */
		public function getErrors(): array {
			$raw = $this->currentData['_errors'] ?? [];
			
			if (!is_array($raw)) {
				return [];
			}
			
			return array_filter($raw, function ($value, $key) {
				return is_string($key) && is_string($value);
			}, ARRAY_FILTER_USE_BOTH);
		}
		
		/**
		 * Returns the entity field values extracted by EntityReader for the current render pass.
		 * Keys are field names; values are the raw scalar values from the entity getters.
		 * Returns an empty array when no entity data is present.
		 * @return array<string, mixed>
		 */
		public function getEntityData(): array {
			$raw = $this->currentData['_entity_data'] ?? [];
			
			if (!is_array($raw)) {
				return [];
			}
			
			// Re-index to guarantee string keys, matching array<string, mixed>
			return array_map(fn($v) => $v, $raw);
		}
		
		/**
		 * Extract validation rules from the node tree for use with Canvas\Validation.
		 *
		 * Walks the node tree and collects all 'field' nodes that carry a 'rules'
		 * property. Returns the rules keyed by field name, ready to be returned from
		 * ValidationInterface::getRules() and consumed by Validator::validate().
		 *
		 * Entity-generated forms scope their HTML name attributes to an entity prefix
		 * (e.g. PostEntity[title]), which PHP parses into a nested array on submission:
		 *   $_POST = ['PostEntity' => ['title' => 'Hello']]
		 * Validator::validateFields() recurses into nested arrays using the same key
		 * structure, so the rules must mirror that shape:
		 *   ['PostEntity' => ['title' => [new NotBlank()]]]
		 *
		 * When no entity prefix is present the rules are returned flat:
		 *   ['title' => [new NotBlank()]]
		 *
		 * @phpstan-param LoomNode $node Root node of the JSON page definition
		 * @return array<string, mixed> Validation rules keyed by field name, optionally nested under the entity prefix
		 */
		public function getValidationData(array $node): array {
			$prefix = null;
			$rootProps = NodeUtil::properties($node);
			
			if (!empty($rootProps['entity_prefix']) && is_string($rootProps['entity_prefix'])) {
				$prefix = $rootProps['entity_prefix'];
			}
			
			$flat = $this->collectFieldRules($node);
			
			// No entity prefix — return flat rules directly
			if ($prefix === null) {
				return $flat;
			}
			
			// Entity prefix is present — nest the rules so that Validator can recurse
			// into the submitted array shape: ['PostEntity' => ['field' => value]]
			return [$prefix => $flat];
		}
		
		/**
		 * Recursively walk the node tree and collect 'rules' from every 'field' node.
		 * Returns a flat map of field name => rules array.
		 * @phpstan-param LoomNode $node
		 * @return array<string, mixed>
		 */
		private function collectFieldRules(array $node): array {
			$rules = [];
			
			if (($node['type'] ?? null) === 'field') {
				$props = NodeUtil::properties($node);
				$name = isset($props['name']) && is_string($props['name']) ? $props['name'] : null;
				
				if ($name !== null && !empty($props['rules']) && is_array($props['rules'])) {
					$rules[$name] = $props['rules'];
				}
			}
			
			foreach (NodeUtil::children($node) as $child) {
				$rules += $this->collectFieldRules($child);
			}
			
			return $rules;
		}
		
		/**
		 * Recursively render a node and all its children, collecting HTML and scripts.
		 * Children are always rendered before their parent — the engine works depth-first,
		 * so each renderer receives its children as a fully rendered HTML string.
		 * @phpstan-param LoomNode $node Current node to render
		 * @phpstan-param LoomNode|null $parent Parent node, passed to renderers so they can read parent properties
		 * @return RenderResult      Combined HTML and scripts for this node and all its descendants
		 * @internal Do not call directly — use render() instead
		 */
		private function renderNode(array $node, ?array $parent = null): RenderResult {
			$childHtml = '';
			$scripts = [];
			
			$nodeChildren = NodeUtil::children($node);
			$lastIndex = 0;
			
			foreach ($nodeChildren as $i => $child) {
				$result = $this->renderNode($child, $node);
				$childHtml .= $result->html;
				$lastIndex = $i;
				
				if ($result->script !== null) {
					$scripts[] = $result->script;
				}
			}
			
			// Pass raw child nodes to renderer so container renderers can
			// inspect the node tree without relying on already-rendered HTML
			$properties = NodeUtil::properties($node);
			$rawChildren = NodeUtil::children($node);
			$properties['_children'] = $rawChildren;
			
			$nodeType = is_string($node['type'] ?? null) ? $node['type'] : '';
			$renderer = $this->getRenderer($nodeType);
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
				return $this->rendererCache[$class] ??= $this->instantiateRenderer($class);
			}
			
			// Fall back to naming convention: "button" -> ButtonRenderer
			$className = 'Quellabs\\Canvas\\Loom\\Renderer\\' . ucfirst($type) . 'Renderer';
			
			if (!class_exists($className)) {
				throw new \RuntimeException(
					"No renderer found for type \"{$type}\". " .
					"Create Quellabs\\Canvas\\Loom\\Renderer\\" . ucfirst($type) . "Renderer " .
					"or register a custom renderer via Loom::register()."
				);
			}
			
			if (!is_subclass_of($className, RendererInterface::class)) {
				throw new \RuntimeException(
					"Renderer class \"{$className}\" must implement " . RendererInterface::class . "."
				);
			}
			
			// At this point class_exists() and is_subclass_of() have both passed,
			// so $className is a valid class-string<RendererInterface>.
			/** @var class-string<RendererInterface> $className */
			return $this->rendererCache[$className] ??= $this->instantiateRenderer($className);
		}
		
		/**
		 * Instantiate a renderer class and return it typed as RendererInterface.
		 * The caller has already verified class existence and RendererInterface compliance,
		 * so the assertion is guaranteed to hold.
		 * @param class-string<RendererInterface> $class
		 * @return RendererInterface
		 */
		private function instantiateRenderer(string $class): RendererInterface {
			return new $class($this);
		}
	}