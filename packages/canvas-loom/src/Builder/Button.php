<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a button node.
	 * The action is a WakaPAC binding expression — either a unit function
	 * call (e.g. Stdlib.sendMessage(...)) or a method on the container
	 * abstraction (e.g. submit(), post('/endpoint')).
	 */
	class Button extends AbstractNode {
		
		/**
		 * @param string $label Button label text
		 */
		private function __construct(string $label) {
			$this->properties['label'] = $label;
		}
		
		/**
		 * @param string $label Button label text
		 */
		public static function make(string $label): static {
			return new static($label);
		}
		
		/**
		 * Set the WakaPAC binding expression for the click action.
		 * Can be a unit function or a method on the container abstraction.
		 * @param string $action WakaPAC binding expression e.g. 'submit()' or 'Stdlib.sendMessage(...)'
		 * @return static
		 */
		public function action(string $action): static {
			return $this->set('action', $action);
		}
		
		/**
		 * Set the button variant
		 * @param string $variant primary|secondary|danger
		 * @return static
		 */
		public function variant(string $variant): static {
			return $this->set('variant', $variant);
		}
		
		/**
		 * Shorthand for variant('secondary')
		 */
		public function secondary(): static {
			return $this->variant('secondary');
		}
		
		/**
		 * Shorthand for variant('danger')
		 */
		public function danger(): static {
			return $this->variant('danger');
		}
		
		/**
		 * Set the HTML button type attribute
		 * @param string $type button|submit|reset
		 * @return static
		 */
		public function type(string $type): static {
			return $this->set('type', $type);
		}
		
		protected function getType(): string {
			return 'button';
		}
	}