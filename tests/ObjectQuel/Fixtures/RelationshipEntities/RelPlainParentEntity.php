<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * A plain (non-soft-deletable) "one" side, paired with RelSoftChildOfPlainParentEntity.
	 * @Orm\Table(name="rel_plain_parents")
	 */
	class RelPlainParentEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
