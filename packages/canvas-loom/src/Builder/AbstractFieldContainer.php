<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	use Quellabs\Support\StringInflector;
	
	/**
	 * Base class for nodes that can contain field children.
	 * Handles dependency resolution for dependent dropdowns before
	 * the node tree is serialised to an array.
	 */
	abstract class AbstractFieldContainer extends AbstractNode {
		
		/**
		 * Resolve dependency chains for dependent select fields.
		 * Walks the dependency chain for each field with a depends_on
		 * property and injects a foreach_expression that WakaPAC uses
		 * to filter the dropdown options.
		 */
		protected function resolveDependencies(): void {
			// Build a map of field name -> depends_on
			$dependsOnMap = [];
			
			foreach ($this->children as $child) {
				if ($child instanceof Field && $child->get('depends_on')) {
					$dependsOnMap[$child->get('name')] = $child->get('depends_on');
				}
			}
			
			if (empty($dependsOnMap)) {
				return;
			}
			
			foreach ($this->children as $child) {
				if (!$child instanceof Field || !$child->get('depends_on')) {
					continue;
				}
				
				$chain   = [];
				$current = $child->get('name');
				
				while (isset($dependsOnMap[$current])) {
					$current = $dependsOnMap[$current];
					$chain[] = $current;
				}
				
				// Build foreach expression using pluralized field name as data key
				$dataKey    = StringInflector::pluralize($child->get('name'));
				$expression = $dataKey . implode('', array_map(fn($k) => "[{$k}]", array_reverse($chain)));
				
				$child->set('foreach_expression', $expression);
			}
		}
		
		/**
		 * Resolve dependencies before serialising to array
		 * @return array
		 */
		public function toArray(): array {
			$this->resolveDependencies();
			return parent::toArray();
		}
	}