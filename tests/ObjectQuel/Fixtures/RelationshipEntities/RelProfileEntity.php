<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * The owning side of a bidirectional OneToOne. 'referencedColumn' being
	 * set here only drives setter-sync codegen for the InverseOf side — it
	 * has no bearing on cascade-remove eligibility or insert ordering, both
	 * of which apply the same way to a unidirectional OneToOne (see
	 * RelProfileUnidirectionalEntity). Carries the same Cascade(remove,
	 * persist) + ForeignKey/ForeignKeyAction combo as FkOrderEntity, but for
	 * OneToOne rather than ManyToOne.
	 * @Orm\Table(name="rel_profiles")
	 */
	class RelProfileEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\OneToOne(targetEntity=RelUserEntity::class, referencedColumn="profile", localColumn="userId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove", "persist"})
		 */
		public ?RelUserEntity $user = null;

		/**
		 * @Orm\Column(name="user_id", type="integer")
		 * @Orm\ForeignKey(target=RelUserEntity::class, referencedColumn="id")
		 * @Orm\ForeignKeyAction(onDelete="CASCADE")
		 */
		protected ?int $userId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
