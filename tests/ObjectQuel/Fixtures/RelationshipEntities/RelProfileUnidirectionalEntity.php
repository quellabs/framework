<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;

	/**
	 * Same owning OneToOne + Cascade(remove) combo as RelProfileEntity, but with
	 * no 'referencedColumn' — i.e. unidirectional. Proves cascade-remove works
	 * for a unidirectional OneToOne too: referencedColumn only affects
	 * bidirectional setter-sync codegen in UnitOfWork::handleDependentEntityClass()
	 * and has no bearing on cascade-remove eligibility.
	 * @Orm\Table(name="rel_profiles_unidirectional")
	 */
	class RelProfileUnidirectionalEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\OneToOne(targetEntity=RelUserEntity::class, localColumn="userId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove"})
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
