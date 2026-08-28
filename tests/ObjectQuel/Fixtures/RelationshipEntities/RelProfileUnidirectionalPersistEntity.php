<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;

	/**
	 * Same unidirectional owning-side OneToOne shape as
	 * RelProfileUnidirectionalEntity (no 'referencedColumn'), but with
	 * Cascade(persist) instead of Cascade(remove). Proves
	 * scheduleEntitiesForPersistence() orders a cascaded, brand-new parent
	 * before this entity even though the relation is unidirectional —
	 * 'referencedColumn' only affects bidirectional setter-sync codegen and
	 * has no bearing on insert ordering, exactly as it has none on
	 * cascade-remove eligibility.
	 * @Orm\Table(name="rel_profiles_unidirectional_persist")
	 */
	class RelProfileUnidirectionalPersistEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\OneToOne(targetEntity=RelUserEntity::class, localColumn="userId", fetch="EAGER")
		 * @Orm\Cascade(operations={"persist"})
		 */
		public ?RelUserEntity $user = null;

		/**
		 * @Orm\Column(name="user_id", type="integer")
		 */
		protected ?int $userId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
