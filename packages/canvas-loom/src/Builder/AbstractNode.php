<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Base class for all Loom node builders.
	 * Provides common functionality for building node arrays,
	 * managing children and setting properties.
	 */
	abstract class AbstractNode {
		
		/** @var array Node properties */
		protected array $properties = [];
		
		/** @var array Child nodes */
		protected array $children = [];
		
		/**
		 * Add a child node
		 * @param AbstractNode $node
		 * @return static
		 */
		public function add(AbstractNode $node): static {
			$this->children[] = $node;
			return $this;
		}
		
		public function getProperties(): array {
			return $this->properties;
		}
		
		/**
		 * Get a property value
		 * @param string $key
		 * @param mixed  $default
		 * @return mixed
		 */
		public function get(string $key, mixed $default = null): mixed {
			return $this->properties[$key] ?? $default;
		}
		
		/**
		 * Set a property value
		 * @param string $key
		 * @param mixed  $value
		 * @return static
		 */
		public function set(string $key, mixed $value): static {
			$this->properties[$key] = $value;
			return $this;
		}
		
		/**
		 * Convert this node and all its children to an array
		 * suitable for Loom::render()
		 * @return array
		 */
		public function toArray(): array {
			return [
				'type'       => $this->getType(),
				'properties' => $this->properties,
				'children'   => array_map(fn($child) => $child->toArray(), $this->children),
			];
		}
		
		/**
		 * Append a raw JavaScript snippet to the WakaPAC abstraction object.
		 * Use this to add methods, getters, or properties that extend the
		 * component beyond what Loom generates automatically.
		 *
		 * The snippet is placed directly inside the abstraction object literal,
		 * so it must be valid JS in that context — method definitions, getters,
		 * arrow function properties, etc.
		 *
		 * Multiple calls are concatenated in order.
		 *
		 * Example:
		 *   ->script("resetForm() { this.first_name = ''; this.last_name = ''; }")
		 *   ->script("get fullName() { return this.first_name + ' ' + this.last_name; }")
		 *
		 * The code is emitted verbatim — never pass untrusted input here.
		 * @param string $code Raw JavaScript to inject into the abstraction
		 * @return static
		 */
		public function script(string $code): static {
			$scripts   = $this->properties['scripts'] ?? [];
			$scripts[] = $code;
			return $this->set('scripts', $scripts);
		}
		
		/**
		 * Set custom properties on the WakaPAC abstraction object.
		 * Useful for exposing named message constants and other scalar
		 * values that need to be accessible in bind expressions.
		 * Values must be JSON-serialisable (scalars and arrays only).
		 * @param array $properties Key-value pairs merged into the abstraction object
		 * @return static
		 */
		public function abstraction(array $properties): static {
			return $this->set('abstraction', $properties);
		}
		
		/**
		 * Returns the node type string used by the Loom renderer registry
		 * @return string
		 */
		abstract protected function getType(): string;
	}