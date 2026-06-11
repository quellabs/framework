<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a tabs node — a tabbed interface container.
	 * Tab switching is handled by a vanilla JS inline script.
	 * Tabs has no WakaPAC instance — fields inside are bound to the parent Resource.
	 */
	final class Tabs extends AbstractNode {
		
		/**
		 * @param string $id Tabs container id
		 * @param string $active Id of the initially active tab
		 */
		protected function __construct(string $id, string $active = '') {
			$this->properties['id'] = $id;
			$this->properties['tabs'] = [];
			
			if ($active) {
				$this->properties['active'] = $active;
			}
		}
		
		/**
		 * @param string $id Tabs container id
		 * @param string $active Id of the initially active tab
		 */
		public static function make(string $id, string $active = ''): static {
			return new static($id, $active);
		}
		
		/**
		 * Add one or more Tab nodes.
		 * Each Tab is registered in the tabs index for the tab bar
		 * and as a child node for the content panel.
		 * Accepts a single Tab or multiple Tabs in one call:
		 *   ->add($tabA)
		 *   ->add($tabA, $tabB, $tabC)
		 * @param AbstractNode ...$nodes
		 * @return static
		 */
		public function add(AbstractNode ...$nodes): static {
			foreach ($nodes as $node) {
				if ($node instanceof Tab) {
					$existing = $this->properties['tabs'];
					$tabs = is_array($existing) ? $existing : [];
					$tabs[] = [
						'id'    => $node->getId(),
						'label' => $node->getLabel(),
					];
					$this->properties['tabs'] = $tabs;
				}
			}
			
			return parent::add(...$nodes);
		}
		
		/**
		 * Not supported — Tabs has no WakaPAC instance.
		 * Use Resource::script() instead.
		 */
		public function script(string $code): static {
			throw new \LogicException('Tabs::script() is not supported. Tabs has no WakaPAC instance — use Resource::script() instead.');
		}
		
		/**
		 * Not supported — Tabs has no WakaPAC instance.
		 * Use Resource::abstraction() instead.
		 */
		public function abstraction(array $properties): static {
			throw new \LogicException('Tabs::abstraction() is not supported. Tabs has no WakaPAC instance — use Resource::abstraction() instead.');
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'tabs';
		}
	}