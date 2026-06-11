<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a tab node — a single tab panel within a tabs container.
	 */
	final class Tab extends AbstractNode {
		
		/**
		 * @param string $id    Tab id, used for active state matching
		 * @param string $label Tab button label
		 */
		protected function __construct(string $id, string $label) {
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
			$id = $this->properties['id'];
			return is_string($id) ? $id : '';
		}
		
		/**
		 * Returns the tab label for use by the parent Tabs builder
		 * @return string
		 */
		public function getLabel(): string {
			$label = $this->properties['label'];
			return is_string($label) ? $label : '';
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'tab';
		}
	}