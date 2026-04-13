<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a column node — a single column within a columns container.
	 */
	class Column extends AbstractNode {
		
		private function __construct() {}
		
		public static function make(): static {
			return new static();
		}
		
		protected function getType(): string {
			return 'column';
		}
	}