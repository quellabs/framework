<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\SoftDelete;

	/**
	 * A soft-deletable "one" side, paired with RelPlainChildOfSoftParentEntity
	 * (no @SoftDelete) and RelSoftChildOfSoftParentEntity (@SoftDelete too) —
	 * exercises how cascade-remove propagates a parent's actual soft/hard
	 * outcome to its Cascade(remove) dependents (see UnitOfWork::scheduleForDelete()).
	 * @Orm\Table(name="rel_soft_parents")
	 */
	class RelSoftParentEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="deleted_at", type="datetime", nullable=true)
		 * @Orm\SoftDelete
		 */
		protected ?\DateTime $deletedAt = null;

		public function getId(): ?int {
			return $this->id;
		}

		public function getDeletedAt(): ?\DateTime {
			return $this->deletedAt;
		}

		public function setDeletedAt(?\DateTime $deletedAt): self {
			$this->deletedAt = $deletedAt;
			return $this;
		}
	}
