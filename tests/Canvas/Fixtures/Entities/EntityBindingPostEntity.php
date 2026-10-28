<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Entities;

	/**
	 * Minimal entity for EntityBindingRequestDispatchTest. The EntityManager
	 * that resolves it is mocked (see that test for why), so this is a plain
	 * object, not an ObjectQuel-mapped one. No constructor, matching real
	 * ObjectQuel entities: Container::createInstance() autowires a concrete
	 * class's constructor before consulting its provider — harmless for an
	 * entity provider (which ignores that autowired instance and returns
	 * EntityManager::find()'s result instead) only as long as that
	 * construction doesn't require arguments nothing can supply.
	 */
	class EntityBindingPostEntity {

		private string $slug = '';
		private string $title = '';

		/**
		 * @return string
		 */
		public function getSlug(): string {
			return $this->slug;
		}

		/**
		 * @param string $slug
		 * @return void
		 */
		public function setSlug(string $slug): void {
			$this->slug = $slug;
		}

		/**
		 * @return string
		 */
		public function getTitle(): string {
			return $this->title;
		}

		/**
		 * @param string $title
		 * @return void
		 */
		public function setTitle(string $title): void {
			$this->title = $title;
		}
	}
