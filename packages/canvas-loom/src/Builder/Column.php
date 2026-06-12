<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a column node — a single column within a columns container.
	 * A column can contain any node type via add(): Text nodes for descriptive
	 * content, Field nodes for form inputs, or any combination.
	 * Fields routed in by Resource::wrapInColumns() are appended after any
	 * nodes already added manually, so decorative content always appears first.
	 */
	final class Column extends AbstractFieldContainer {
		
		protected function __construct() {
		}
		
		public static function make(): static {
			return new static();
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'column';
		}
	}