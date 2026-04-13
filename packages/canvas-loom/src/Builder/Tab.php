<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a tab node — a single tab panel within a tabs container.
	 */
	class Tab extends AbstractNode {
		
		/**
		 * @param string $id    Tab id, used for active state matching
		 * @param string $label Tab button label
		 */
		private function __construct(string $id, string $label) {
			$this->properties['id']    = $id;
			$this->properties['label'] = $label;
		}
		
		/**
		 * @param string $id    Tab id, used for active state matching
		 * @param string $label Tab button label
		 */
		public static function make(string $id, string $label): static {
			return new static($id, $label);
		}
		
		/**
		 * Returns the tab id for use by the parent Tabs builder
		 * @return string
		 */
		public function getId(): string {
			return $this->properties['id'];
		}
		
		/**
		 * Returns the tab label for use by the parent Tabs builder
		 * @return string
		 */
		public function getLabel(): string {
			return $this->properties['label'];
		}
		
		protected function getType(): string {
			return 'tab';
		}
	}