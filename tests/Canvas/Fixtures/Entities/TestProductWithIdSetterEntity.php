<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture entity whose "id" identifier has a setter, unlike TestProductEntity.
	 * Exists solely to regression-test that a differently-cased request key
	 * (e.g. "ID") invoking setId() is still recognized as the identifier.
	 * @Orm\Table(name="test_products_with_id_setter")
	 */
	class TestProductWithIdSetterEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}

		/**
		 * @param int $id
		 * @return void
		 */
		public function setId(int $id): void {
			$this->id = $id;
		}
	}
