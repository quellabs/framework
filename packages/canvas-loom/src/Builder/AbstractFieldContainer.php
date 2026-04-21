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
			// This variable will receive the map
			$dependsOnMap = [];
			
			// Build a flat map of field name -> depends_on by walking the entire
			// subtree, not just direct children. Fields nested inside Columns or
			// Sections would otherwise never be resolved.
			$this->collectDependsOn($this->children, $dependsOnMap);
			
			// Do nothing when the map is empty
			if (empty($dependsOnMap)) {
				return;
			}
			
			// Recursively walk the subtree and inject foreach_expression on dependent fields.
			$this->applyForeachExpressions($this->children, $dependsOnMap);
		}
		
		/**
		 * Recursively collect depends_on declarations from the entire subtree.
		 * @param array $nodes
		 * @param array $map Passed by reference — populated as [field_name => depends_on_name]
		 */
		private function collectDependsOn(array $nodes, array &$map): void {
			foreach ($nodes as $child) {
				if ($child instanceof Field && $child->get('depends_on')) {
					$map[$child->get('name')] = $child->get('depends_on');
				}
				
				// Recurse into nested containers
				if (property_exists($child, 'children')) {
					$this->collectDependsOn($child->children ?? [], $map);
				}
			}
		}
		
		/**
		 * Recursively walk the subtree and inject foreach_expression on dependent fields.
		 * @param array $nodes
		 * @param array $dependsOnMap
		 */
		private function applyForeachExpressions(array $nodes, array $dependsOnMap): void {
			foreach ($nodes as $child) {
				if ($child instanceof Field && $child->get('depends_on')) {
					$chain = [];
					$current = $child->get('name');
					
					while (isset($dependsOnMap[$current])) {
						$current = $dependsOnMap[$current];
						$chain[] = $current;
					}
					
					// Build foreach expression using pluralized field name as data key
					$dataKey = StringInflector::pluralize($child->get('name'));
					$expression = $dataKey . implode('', array_map(fn($k) => "[{$k}]", array_reverse($chain)));
					
					$child->set('foreach_expression', $expression);
				}
				
				// Recurse into nested containers
				if (property_exists($child, 'children')) {
					$this->applyForeachExpressions($child->children ?? [], $dependsOnMap);
				}
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