<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * The "self" side of a self-referencing bridge: RelFriendshipEntity links two
	 * RelFriendUserEntity rows via its own $userA/$userB ManyToOne relations, both
	 * targeting this same entity type. Reaching the bridge from here (via $userA)
	 * exercises the case where target-type-based exclusion would wrongly also skip
	 * $userB, since both relations share this type.
	 * @Orm\Table(name="rel_friend_users")
	 */
	class RelFriendUserEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelFriendshipEntity::class, relation="userA")
		 */
		public CollectionInterface $friendshipsAsA;

		public function __construct() {
			$this->friendshipsAsA = new Collection();
		}

		public function getId(): ?int {
			return $this->id;
		}
	}
