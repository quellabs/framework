<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a columns node — a flex layout container.
	 */
	class Columns extends AbstractNode {
		
		/**
		 * @param array  $widths Column widths as percentages e.g. [70, 30]
		 * @param string $gap    CSS gap value between columns
		 */
		private function __construct(array $widths = [], string $gap = '1rem') {
			if (!empty($widths)) {
				$this->properties['widths'] = $widths;
			}
			
			$this->properties['gap'] = $gap;
		}
		
		/**
		 * @param array  $widths Column widths as percentages e.g. [70, 30]
		 * @param string $gap    CSS gap value between columns
		 */
		public static function make(array $widths = [], string $gap = '1rem'): static {
			return new static($widths, $gap);
		}
		
		protected function getType(): string {
			return 'columns';
		}
	}