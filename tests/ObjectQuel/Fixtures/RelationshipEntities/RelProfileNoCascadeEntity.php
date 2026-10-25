<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;

	/**
	 * Negative control for the OneToOne cascade-remove test: same shape as
	 * RelProfileEntity but with no Cascade annotation at all, proving removal
	 * of the related user does NOT cascade when Cascade is absent.
	 * @Orm\Table(name="rel_profiles_no_cascade")
	 */
	class RelProfileNoCascadeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\OneToOne(targetEntity=RelUserEntity::class, localColumn="userId", fetch="EAGER")
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
