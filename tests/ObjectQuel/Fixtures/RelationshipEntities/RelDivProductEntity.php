<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Required-set range of the relational-division fixtures.
	 * @Orm\Table(name="rel_div_products")
	 */
	class RelDivProductEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="category", type="string", limit=100)
		 */
		protected ?string $category = null;

		/**
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}
	}
