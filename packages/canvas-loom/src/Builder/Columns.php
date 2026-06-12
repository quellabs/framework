<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a columns node — a flex layout container.
	 */
	final class Columns extends AbstractFieldContainer {
		
		/**
		 * Constructor
		 * @param int[] $widths Column widths as percentages e.g. [70, 30]
		 * @param string $gap CSS gap value between columns
		 */
		protected function __construct(array $widths = [], string $gap = '1rem') {
			if (!empty($widths)) {
				$this->properties['widths'] = $widths;
			}
			
			$this->properties['gap'] = $gap;
		}
		
		/**
		 * @param int[] $widths Column widths as percentages e.g. [70, 30]
		 * @param string $gap CSS gap value between columns
		 */
		public static function make(array $widths = [], string $gap = '1rem'): static {
			return new static($widths, $gap);
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'columns';
		}
	}