<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\QueryBuilder;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelFriendshipEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelFriendUserEntity;

	/**
	 * Validates that addBridgeExpansionRanges() excludes the relation that reached a
	 * bridge by property identity, not by target entity type. RelFriendshipEntity is a
	 * self-referencing bridge: both $userA and $userB target RelFriendUserEntity, so a
	 * type-based exclusion would incorrectly drop both relations when the bridge is
	 * reached via $userA (their shared target type would match the exclusion for
	 * either one) — before this fix, $userB never got its extra hop at all.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] (SignalHub allows only one
	 * EntityManager per process) and its own tables, created idempotently in
	 * setUp() since another test class may have claimed the connection first.
	 */
	class EntityBridgeSelfReferencingEagerLoadTest extends TestCase {

		protected function setUp(): void {
			$em = $GLOBALS['test_em'];
			$adapter = $em->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_friend_users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_friendships (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_a_id INT, user_b_id INT) ENGINE=InnoDB');

			foreach (['rel_friendships', 'rel_friend_users'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			$em->getUnitOfWork()->clear();
		}

		/**
		 * Direct evidence of the query shape: querying the "self" side of a
		 * self-referencing bridge must still emit a far-side hop for $userB, even
		 * though $userB shares a target type with $userA (the relation that reached
		 * the bridge).
		 */
		public function testQueryBuilderStillJoinsTheFarSideOfASelfReferencingBridge(): void {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(RelFriendUserEntity::class, ['id' => 1]);

			$hop1Pattern = '/range of (\w+) is ' . preg_quote(RelFriendshipEntity::class, '/') . ' via main\.friendshipsAsA/';
			self::assertMatchesRegularExpression($hop1Pattern, $query, $query);

			preg_match($hop1Pattern, $query, $matches);
			$bridgeAlias = $matches[1];

			// The far side ($userB) must still be joined off the bridge's own alias,
			// not silently dropped for sharing RelFriendUserEntity as its target type.
			$hop2Pattern = '/range of \w+ is ' . preg_quote(RelFriendUserEntity::class, '/') . ' via ' . preg_quote($bridgeAlias, '/') . '\.userB/';
			self::assertMatchesRegularExpression($hop2Pattern, $query, $query);
		}

		/**
		 * End-to-end: fetching a RelFriendUserEntity must come back with $userB
		 * already hydrated off the bridge, not a lazy proxy.
		 */
		public function testFindEagerlyHydratesTheFarSideOfASelfReferencingBridge(): void {
			$em = $GLOBALS['test_em'];

			$userA = new RelFriendUserEntity();
			$em->persist($userA);
			$em->flush();

			$userB = new RelFriendUserEntity();
			$em->persist($userB);
			$em->flush();

			$friendship = new RelFriendshipEntity();
			$friendship->userA = $userA;
			$friendship->userB = $userB;
			$em->persist($friendship);
			$em->flush();

			$em->getUnitOfWork()->clear();

			/** @var RelFriendUserEntity $loadedUserA */
			$loadedUserA = $em->find(RelFriendUserEntity::class, $userA->getId());

			self::assertNotNull($loadedUserA);
			self::assertCount(1, $loadedUserA->friendshipsAsA);

			$loadedUserB = $loadedUserA->friendshipsAsA->first()->userB;

			self::assertInstanceOf(RelFriendUserEntity::class, $loadedUserB);
			self::assertFalse(
				$loadedUserB instanceof ProxyInterface,
				'Expected the far side of a self-referencing bridge to be eagerly hydrated, not a lazy proxy.'
			);
			self::assertSame($userB->getId(), $loadedUserB->getId());
		}
	}
