<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a panel node — a WakaPAC component container without tab navigation.
	 */
	class Panel extends AbstractNode {
		
		/**
		 * Constructor
		 * @param string $id WakaPAC component id
		 */
		private function __construct(string $id) {
			$this->properties['id'] = $id;
		}
		
		/**
		 * @param string $id WakaPAC component id
		 */
		public static function make(string $id): static {
			return new static($id);
		}
		
		/**
		 * Return the node type
		 * @return string
		 */
		protected function getType(): string {
			return 'panel';
		}
	}