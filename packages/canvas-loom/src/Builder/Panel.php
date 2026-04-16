<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a panel node — a layout container without its own WakaPAC instance.
	 * Fields inside a Panel are bound to the parent Resource component.
	 */
	class Panel extends AbstractNode {
		
		/**
		 * Constructor
		 * @param string $id Panel id
		 */
		private function __construct(string $id) {
			$this->properties['id'] = $id;
		}
		
		/**
		 * @param string $id Panel id
		 */
		public static function make(string $id): static {
			return new static($id);
		}
		
		/**
		 * Not supported — Panel has no WakaPAC instance.
		 * Use Resource::script() instead.
		 */
		public function script(string $code): static {
			throw new \LogicException('Panel::script() is not supported. Panel has no WakaPAC instance — use Resource::script() instead.');
		}
		
		/**
		 * Not supported — Panel has no WakaPAC instance.
		 * Use Resource::abstraction() instead.
		 */
		public function abstraction(array $properties): static {
			throw new \LogicException('Panel::abstraction() is not supported. Panel has no WakaPAC instance — use Resource::abstraction() instead.');
		}
		
		/**
		 * Return the node type
		 * @return string
		 */
		protected function getType(): string {
			return 'panel';
		}
	}