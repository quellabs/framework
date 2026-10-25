<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\EntityBridge;

	/**
	 * A self-referencing bridge: both $userA and $userB target RelFriendUserEntity —
	 * the same entity type on both sides of the link. Exists to prove
	 * QueryBuilder::addBridgeExpansionRanges() excludes the reaching relation by
	 * property identity, not by target type; a type-based exclusion would incorrectly
	 * skip both relations here since they share a target type.
	 * @Orm\Table(name="rel_friendships")
	 * @Orm\EntityBridge
	 */
	class RelFriendshipEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelFriendUserEntity::class, localColumn="userAId", fetch="EAGER")
		 */
		public ?RelFriendUserEntity $userA = null;

		/**
		 * @Orm\Column(name="user_a_id", type="integer")
		 */
		protected ?int $userAId = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelFriendUserEntity::class, localColumn="userBId", fetch="EAGER")
		 */
		public ?RelFriendUserEntity $userB = null;

		/**
		 * @Orm\Column(name="user_b_id", type="integer")
		 */
		protected ?int $userBId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
