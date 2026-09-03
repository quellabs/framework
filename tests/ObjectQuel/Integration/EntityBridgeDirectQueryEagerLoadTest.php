<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\QueryBuilder;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelCategoryEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostTagEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelTagEntity;

	/**
	 * Validates a third @Orm\EntityBridge case, distinct from both the child-side
	 * (EntityBridgeEagerLoadTest) and forward (EntityBridgeForwardEagerLoadTest) ones:
	 * querying the bridge entity itself directly. RelPostTagEntity::$post/$tag must
	 * still be eager-joined off 'main' (subject to fetch) even though nothing reached
	 * the bridge via another entity's relation — there is no relation to exclude,
	 * since the bridge itself is 'main'.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] (SignalHub allows only one
	 * EntityManager per process) and its own tables, created idempotently in
	 * setUp() since another test class may have claimed the connection first.
	 */
	class EntityBridgeDirectQueryEagerLoadTest extends TestCase {

		protected function setUp(): void {
			$em = $GLOBALS['test_em'];
			$adapter = $em->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_posts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_tags (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_categories (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_post_tags (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT, tag_id INT, category_id INT) ENGINE=InnoDB');

			foreach (['rel_post_tags', 'rel_posts', 'rel_tags', 'rel_categories'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			$em->getUnitOfWork()->clear();
		}

		/**
		 * Direct evidence of the query shape: querying the bridge itself must emit
		 * ranges for both of its own EAGER relations, anchored on 'main' rather than
		 * a joined alias, since the bridge itself is what was queried.
		 */
		public function testQueryBuilderEagerJoinsABridgesOwnRelationsWhenQueriedDirectly(): void {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(RelPostTagEntity::class, ['id' => 1]);

			self::assertMatchesRegularExpression(
				'/range of \w+ is ' . preg_quote(RelPostEntity::class, '/') . ' via main\.post/',
				$query,
				$query
			);

			self::assertMatchesRegularExpression(
				'/range of \w+ is ' . preg_quote(RelTagEntity::class, '/') . ' via main\.tag/',
				$query,
				$query
			);
		}

		/**
		 * End-to-end: fetching a RelPostTagEntity directly must come back with both
		 * $post and $tag already hydrated, not lazy proxies needing a further query.
		 */
		public function testFindEagerlyHydratesABridgesOwnRelationsWhenQueriedDirectly(): void {
			$em = $GLOBALS['test_em'];

			$post = new RelPostEntity();
			$em->persist($post);
			$em->flush();

			$tag = new RelTagEntity();
			$em->persist($tag);
			$em->flush();

			$postTag = new RelPostTagEntity();
			$postTag->post = $post;
			$postTag->tag = $tag;
			$em->persist($postTag);
			$em->flush();

			$em->getUnitOfWork()->clear();

			/** @var RelPostTagEntity $loadedPostTag */
			$loadedPostTag = $em->find(RelPostTagEntity::class, $postTag->getId());

			self::assertNotNull($loadedPostTag);

			self::assertFalse(
				$loadedPostTag->post instanceof ProxyInterface,
				'Expected the bridge\'s own $post relation to be eagerly hydrated when queried directly.'
			);
			self::assertSame($post->getId(), $loadedPostTag->post->getId());

			self::assertFalse(
				$loadedPostTag->tag instanceof ProxyInterface,
				'Expected the bridge\'s own $tag relation to be eagerly hydrated when queried directly.'
			);
			self::assertSame($tag->getId(), $loadedPostTag->tag->getId());
		}

		/**
		 * Control: RelPostTagEntity::$category is LAZY, so it must not get eager-joined
		 * even when the bridge itself is the entity being queried directly.
		 */
		public function testLazyRelationOnADirectlyQueriedBridgeGetsNoEagerJoin(): void {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(RelPostTagEntity::class, ['id' => 1]);

			self::assertStringNotContainsString(RelCategoryEntity::class, $query, $query);
		}
	}
