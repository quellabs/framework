<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Native-attribute counterpart of GenPacProductEntity, for
	 * PacCanvasControllerGeneratorTest's annotation_source=attributes case.
	 */
	#[Table(name: "gen_pac_product_attributes")]
	class GenPacProductAttributeEntity {

		#[Column(name: "id", type: "integer", unsigned: true, primary_key: true)]
		#[PrimaryKeyStrategy(strategy: "identity")]
		protected ?int $id = null;

		#[Column(name: "name", type: "string", limit: 255)]
		protected string $name = '';

		#[Column(name: "role", type: "string", limit: 50)]
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
