<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a tabs node — a tabbed interface container.
	 */
	class Tabs extends AbstractNode {
		
		/**
		 * @param string $id     WakaPAC component id
		 * @param string $active Id of the initially active tab
		 */
		private function __construct(string $id, string $active = '') {
			$this->properties['id']   = $id;
			$this->properties['tabs'] = [];
			
			if ($active) {
				$this->properties['active'] = $active;
			}
		}
		
		/**
		 * @param string $id     WakaPAC component id
		 * @param string $active Id of the initially active tab
		 */
		public static function make(string $id, string $active = ''): static {
			return new static($id, $active);
		}
		
		/**
		 * Add a tab — registers it in the tabs index for the tab bar
		 * and as a child node for the content panel
		 * @param Tab $tab
		 * @return static
		 */
		public function add(AbstractNode $tab): static {
			// Register tab in the tabs index for the tab bar
			if ($tab instanceof Tab) {
				$this->properties['tabs'][] = [
					'id'    => $tab->getId(),
					'label' => $tab->getLabel(),
				];
			}
			
			return parent::add($tab);
		}
		
		protected function getType(): string {
			return 'tabs';
		}
	}