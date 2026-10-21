<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture entity whose setters are capitalized as acronyms (setID(), setROLE())
	 * rather than the single-leading-capital convention (setId(), setRole()).
	 * Exists solely to regression-test that resolving a request key to its
	 * canonical property name does not depend on the setter's own casing: a
	 * naive `lcfirst(substr($setterName, 3))` unmangling turns `setID()` into
	 * `iD`, not `id`, which would let a differently-cased request key bypass
	 * both the identifier guard and isAttributeWritable().
	 * @Orm\Table(name="test_products_with_acronym_setters")
	 */
	class TestProductWithAcronymSettersEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="role", type="string", limit=50)
		 */
		protected string $role = 'user';

		/**
		 * Returns the product's identifier.
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}

		/**
		 * Sets the product's identifier.
		 * @param int $id
		 * @return void
		 */
		public function setID(int $id): void {
			$this->id = $id;
		}

		/**
		 * Returns the product's role.
		 * @return string
		 */
		public function getRole(): string {
			return $this->role;
		}

		/**
		 * Sets the product's role.
		 * @param string $role
		 * @return void
		 */
		public function setROLE(string $role): void {
			$this->role = $role;
		}
	}
