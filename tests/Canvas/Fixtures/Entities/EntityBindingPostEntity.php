<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Entities;

	/**
	 * Minimal entity for the real HTTP end-to-end entity-binding test. The
	 * EntityManager that resolves it is mocked (see EntityBindingHttpEndToEndTest
	 * for why), so this is a plain object, not an ObjectQuel-mapped one. No
	 * constructor, matching real ObjectQuel entities: Container::createInstance()
	 * autowires a concrete class's constructor before consulting its provider —
	 * harmless for an entity provider (which ignores that autowired instance and
	 * returns EntityManager::find()'s result instead) only as long as that
	 * construction doesn't require arguments nothing can supply.
	 */
	class EntityBindingPostEntity {

		private string $slug = '';
		private string $title = '';

		public function getSlug(): string {
			return $this->slug;
		}

		public function setSlug(string $slug): void {
			$this->slug = $slug;
		}

		public function getTitle(): string {
			return $this->title;
		}

		public function setTitle(string $title): void {
			$this->title = $title;
		}
	}
