<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a column node — a single column within a columns container.
	 */
	class Column extends AbstractFieldContainer  {
		
		private function __construct() {}
		
		public static function make(): static {
			return new static();
		}
		
		/**
		 * Mark this column as a sidebar, rendering a title and hint text
		 * instead of form fields, separated from the content by a vertical line.
		 * @param string $title Title shown at the top of the sidebar
		 * @param string $hint  Optional hint text shown below the title
		 * @return static
		 */
		public function markAsSidebar(string $title, string $hint = ''): static {
			$this->set('sidebar', true);
			$this->set('sidebar_title', $title);
			$this->set('sidebar_hint', $hint);
			return $this;
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'column';
		}
	}