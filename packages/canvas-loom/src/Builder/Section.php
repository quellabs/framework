<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a section node — a neutral container grouping related fields.
	 */
	final class Section extends AbstractFieldContainer {
		
		/**
		 * @param string $id Section id attribute
		 */
		protected function __construct(string $id = '') {
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
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'section';
		}
	}