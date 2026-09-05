<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;

	/**
	 * Test-support entity for upsert coverage (see objectquel-upsert-plan.md)
	 * — needs a real declared unique constraint, which UserEntity/PostEntity
	 * don't have (UserEntity's idx_username is a plain, non-unique index).
	 * Its table is created/dropped directly by the test that uses it (see
	 * tests/Integration/UpsertTest.php) since `create` has no QUEL syntax
	 * for declaring a unique index (v1 scope, see
	 * objectquel-create-table-plan.md).
	 * @Orm\Table(name="upsert_conflict_test")
	 * @Orm\UniqueIndex(name="uniq_email", columns={"email"})
	 */
	class UpsertConflictEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="email", type="string", limit=100)
		 */
		protected string $email;

		/**
		 * @Orm\Column(name="name", type="string", limit=255)
		 */
		protected string $name;

		public function getId(): ?int {
			return $this->id;
		}
	}
