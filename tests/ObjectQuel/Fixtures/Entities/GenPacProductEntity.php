<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture entity for PacCanvasControllerGenerator's writable-fields stub test.
	 * @Orm\Table(name="gen_pac_products")
	 */
	class GenPacProductEntity {

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
		 * Returns the product's identifier.
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}

		/**
		 * Returns the product's name.
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * Sets the product's name.
		 * @param string $name
		 * @return void
		 */
		public function setName(string $name): void {
			$this->name = $name;
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
		public function setRole(string $role): void {
			$this->role = $role;
		}
	}
