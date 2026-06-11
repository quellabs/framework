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
		 * Add multiple field nodes from an array in one call.
		 * Shorthand for chaining ->add() for flat field lists:
		 *   Section::make()->fields([
		 *       Field::text('name', 'Name'),
		 *       Field::email('email', 'Email'),
		 *   ])
		 * @param AbstractNode[] $fields
		 * @return static
		 */
		public function fields(array $fields): static {
			foreach ($fields as $field) {
				$this->children[] = $field;
			}
			
			return $this;
		}
		
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
				if ($child instanceof Field) {
					$childName = $child->get('name');
					$childDependsOn = $child->get('depends_on');
					
					if (is_string($childName) && is_string($childDependsOn) && $childDependsOn !== '') {
						$dependsOnMap[$childName] = $childDependsOn;
					}
				}
			}
			
			if (empty($dependsOnMap)) {
				return;
			}
			
			foreach ($this->children as $child) {
				if (!$child instanceof Field) {
					continue;
				}
				
				$childName = $child->get('name');
				$childDependsOn = $child->get('depends_on');
				
				if (!is_string($childName) || !is_string($childDependsOn) || $childDependsOn === '') {
					continue;
				}
				
				$chain = [];
				$current = $childName;
				
				while (isset($dependsOnMap[$current])) {
					$current = $dependsOnMap[$current];
					$chain[] = $current;
				}
				
				// Build foreach expression using pluralized field name as data key
				$dataKey = StringInflector::pluralize($childName);
				$expression = $dataKey . implode('', array_map(fn($k) => "[{$k}]", array_reverse($chain)));
				
				$child->set('foreach_expression', $expression);
			}
		}
		
		/**
		 * Resolve dependencies before serialising to array
		 * @return array<string, mixed>
		 */
		public function toArray(): array {
			$this->resolveDependencies();
			return parent::toArray();
		}
	}