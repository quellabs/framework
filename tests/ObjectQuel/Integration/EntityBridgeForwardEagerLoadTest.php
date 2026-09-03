<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\QueryBuilder;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelAuditLogEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostTagEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelTagEntity;

	/**
	 * Validates the reverse direction of @Orm\EntityBridge eager-loading: when the
	 * entity being fetched (RelAuditLogEntity) itself owns a ManyToOne pointing
	 * *at* a bridge entity (RelPostTagEntity), QueryBuilder must eager-join the
	 * bridge directly off 'main' and then extend one hop further through the
	 * bridge's own relations — the same chaining EntityBridgeEagerLoadTest proves
	 * for the child-side (InverseOf) case, but reached from the parent side.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] (SignalHub allows only one
	 * EntityManager per process) and its own table, created idempotently in
	 * setUp() since another test class may have claimed the connection first.
	 */
	class EntityBridgeForwardEagerLoadTest extends TestCase {

		protected function setUp(): void {
			$em = $GLOBALS['test_em'];
			$adapter = $em->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_posts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_tags (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_categories (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_post_tags (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT, tag_id INT, category_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_audit_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_tag_id INT, lazy_post_tag_id INT NULL) ENGINE=InnoDB');

			foreach (['rel_audit_logs', 'rel_post_tags', 'rel_posts', 'rel_tags', 'rel_categories'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			$em->getUnitOfWork()->clear();
		}

		/**
		 * Direct evidence of the query shape: QueryBuilder must emit a range for the
		 * bridge itself directly off 'main' (RelAuditLogEntity's own ManyToOne), then
		 * a second hop for RelTagEntity chained off the bridge's own alias.
		 */
		public function testQueryBuilderAddsABridgeHopThroughItsOwnForwardManyToOne(): void {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(RelAuditLogEntity::class, ['id' => 1]);

			// Hop 1: the bridge itself, reached directly off 'main' via RelAuditLogEntity's own relation.
			$hop1Pattern = '/range of (\w+) is ' . preg_quote(RelPostTagEntity::class, '/') . ' via main\.postTag/';
			self::assertMatchesRegularExpression($hop1Pattern, $query, $query);

			preg_match($hop1Pattern, $query, $matches);
			$bridgeAlias = $matches[1];

			// Hop 2: the far side, chained off the bridge's own alias rather than 'main'.
			// Both of the bridge's own relations (post, tag) qualify since neither points
			// back at RelAuditLogEntity.
			$hop2PostPattern = '/range of \w+ is ' . preg_quote(RelPostEntity::class, '/') . ' via ' . preg_quote($bridgeAlias, '/') . '\.post/';
			$hop2TagPattern = '/range of \w+ is ' . preg_quote(RelTagEntity::class, '/') . ' via ' . preg_quote($bridgeAlias, '/') . '\.tag/';
			self::assertMatchesRegularExpression($hop2PostPattern, $query, $query);
			self::assertMatchesRegularExpression($hop2TagPattern, $query, $query);
		}

		/**
		 * End-to-end: fetching a RelAuditLogEntity must come back with an already-hydrated
		 * RelPostTagEntity at $auditLog->postTag, and its own $post/$tag already hydrated
		 * too — not lazy proxies needing further queries. This is the behavioral proof the
		 * forward extra hop actually fired.
		 */
		public function testFindEagerlyHydratesTheBridgeAndItsFarSideThroughAForwardManyToOne(): void {
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

			$auditLog = new RelAuditLogEntity();
			$auditLog->postTag = $postTag;
			$em->persist($auditLog);
			$em->flush();

			$em->getUnitOfWork()->clear();

			/** @var RelAuditLogEntity $loadedAuditLog */
			$loadedAuditLog = $em->find(RelAuditLogEntity::class, $auditLog->getId());

			self::assertNotNull($loadedAuditLog);

			$loadedPostTag = $loadedAuditLog->postTag;
			self::assertInstanceOf(RelPostTagEntity::class, $loadedPostTag);
			self::assertFalse(
				$loadedPostTag instanceof ProxyInterface,
				'Expected the bridge itself to be eagerly hydrated, not a lazy proxy.'
			);

			$loadedTag = $loadedPostTag->tag;
			self::assertInstanceOf(RelTagEntity::class, $loadedTag);
			self::assertFalse(
				$loadedTag instanceof ProxyInterface,
				'Expected the far side of the forward bridge relation to be eagerly hydrated in the same query, not a lazy proxy.'
			);
			self::assertSame($tag->getId(), $loadedTag->getId());
		}

		/**
		 * Direct evidence of the skip: a LAZY forward relation into a bridge
		 * (RelAuditLogEntity::$lazyPostTag) must not get the extra hop, even though
		 * it points at a bridge entity that would otherwise qualify like $postTag does.
		 */
		public function testLazyForwardBridgeRelationGetsNoExtraHop(): void {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(RelAuditLogEntity::class, ['id' => 1]);

			self::assertStringNotContainsString('main.lazyPostTag', $query, $query);
		}
	}
