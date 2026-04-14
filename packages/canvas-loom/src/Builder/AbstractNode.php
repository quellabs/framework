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