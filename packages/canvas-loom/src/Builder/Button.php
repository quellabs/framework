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
		
		/**
		 * Set the name of this button, used as the visibility property key
		 * in the header component state (show_{name})
		 * @param string $name
		 * @return static
		 */
		public function name(string $name): static {
			return $this->set('name', $name);
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'button';
		}
		
		/**
		 * Set the WakaPAC message identifier that shows this button.
		 * @param int $message Message identifier integer
		 * @return static
		 */
		public function showMessage(int $message): static {
			return $this->set('show_message', $message);
		}
		
		/**
		 * Set the WakaPAC message identifier that hides this button.
		 * @param int $message Message identifier integer
		 * @return static
		 */
		public function hideMessage(int $message): static {
			return $this->set('hide_message', $message);
		}
	}