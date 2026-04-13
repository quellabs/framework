<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a section node — a neutral container grouping related fields.
	 */
	class Section extends AbstractFieldContainer {
		
		/**
		 * @param string $id Section id attribute
		 */
		private function __construct(string $id = '') {
			if ($id) {
				$this->properties['id'] = $id;
			}
		}
		
		/**
		 * @param string $id Section id attribute
		 */
		public static function make(string $id = ''): static {
			return new static($id);
		}
		
		protected function getType(): string {
			return 'section';
		}
	}