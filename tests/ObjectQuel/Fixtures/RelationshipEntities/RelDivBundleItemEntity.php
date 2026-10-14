<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Required-set range of the relational-division fixtures that may list a product more than once.
	 * @Orm\Table(name="rel_div_bundle_items")
	 */
	class RelDivBundleItemEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="bundle", type="string", limit=100)
		 */
		protected ?string $bundle = null;

		/**
		 * @Orm\Column(name="product_id", type="integer")
		 */
		protected ?int $productId = null;

		/**
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}
	}
