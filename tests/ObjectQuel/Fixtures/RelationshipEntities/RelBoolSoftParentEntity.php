<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\SoftDelete;

	/**
	 * Boolean-typed soft-deletable "one" side, paired with RelBoolSoftChildEntity
	 * via a nullable FK (optional / LEFT JOIN relation).
	 * @Orm\Table(name="rel_bool_soft_parents")
	 */
	class RelBoolSoftParentEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="is_deleted", type="boolean", nullable=false, default=false)
		 * @Orm\SoftDelete
		 */
		protected bool $isDeleted = false;

		public function getId(): ?int {
			return $this->id;
		}

		public function isDeleted(): bool {
			return $this->isDeleted;
		}

		public function setIsDeleted(bool $isDeleted): self {
			$this->isDeleted = $isDeleted;
			return $this;
		}
	}
