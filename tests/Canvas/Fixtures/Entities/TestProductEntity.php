<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture entity for JsonApiController's writable-field tests. "role" stands
	 * in for a server-owned field with a normal setter — deny-by-default must
	 * protect it whenever a controller simply omits it from its allowlist.
	 * "internalNotes" has no setter, exercising the no-setter-means-no-write path.
	 * @Orm\Table(name="test_products")
	 */
	class TestProductEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="name", type="string", limit=255)
		 */
		protected string $name = '';

		/**
		 * @Orm\Column(name="role", type="string", limit=50)
		 */
		protected string $role = 'user';

		/**
		 * @Orm\Column(name="internal_notes", type="string", limit=255, nullable=true)
		 */
		protected ?string $internalNotes = null;

		public function getId(): ?int {
			return $this->id;
		}

		public function getName(): string {
			return $this->name;
		}

		public function setName(string $name): void {
			$this->name = $name;
		}

		public function getRole(): string {
			return $this->role;
		}

		public function setRole(string $role): void {
			$this->role = $role;
		}

		public function getInternalNotes(): ?string {
			return $this->internalNotes;
		}
	}
