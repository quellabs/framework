<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftParentEntity;

	/**
	 * Regression coverage: JoinOptimizer's LEFT->INNER promotion (via
	 * DetectNonNullableField) must not trigger on a non-nullable field
	 * reference that's reachable only through an OR. The other OR branch can
	 * still be true with no match for that range at all, so promoting on the
	 * reference alone would silently drop rows an unmatched LEFT JOIN should
	 * keep. Companion to SoftDeleteBooleanLeftJoinTest::testSoftDeleteConditionStillAppliesOnceRangeIsPromotedToInnerJoin(),
	 * which covers the same fixtures' legitimate (AND-only) promotion case.
	 */
	class JoinOptimizerOrEliminatesNullsTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_bool_soft_parents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, is_deleted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_bool_soft_children (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id INT UNSIGNED NULL) ENGINE=InnoDB');

			foreach (['rel_bool_soft_children', 'rel_bool_soft_parents'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			self::em()->getUnitOfWork()->clear();
		}

		/**
		 * `where p.id > 0` alone promotes 'p' to INNER JOIN (see
		 * SoftDeleteBooleanLeftJoinTest). OR-ing it with a condition on the
		 * childless row's own range must keep 'p' LEFT: that row satisfies the
		 * WHERE clause through the OR's other branch while having no match for
		 * 'p' at all, so an incorrect promotion would drop it.
		 */
		public function testOrBranchOnTheOtherRangeKeepsTheJoinLeftAndKeepsTheUnmatchedChild(): void {
			$em = self::em();

			$parent = new RelBoolSoftParentEntity();
			$em->persist($parent);

			$childWithParent = new RelBoolSoftChildEntity();
			$childWithParent->parent = $parent;

			$childWithoutParent = new RelBoolSoftChildEntity();

			$em->persist($childWithParent);
			$em->persist($childWithoutParent);
			$em->flush();
			$em->getUnitOfWork()->clear();

			$plan = $em->explainQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id, p.id)
				where p.id > 0 or ch.id = {$childWithoutParent->getId()}
				sort by ch.id asc
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);

			$rows = $em->executeQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id, p.id)
				where p.id > 0 or ch.id = {$childWithoutParent->getId()}
				sort by ch.id asc
			");

			$rowsById = [];
			foreach ($rows as $row) {
				$rowsById[$row['ch.id']] = $row;
			}

			// Before the fix, 'p' was wrongly promoted to INNER JOIN because
			// 'p.id' (non-nullable) is referenced in the WHERE clause — ignoring
			// that the reference sits inside an OR — which silently dropped the
			// childless row (0 matches for 'p') even though it satisfies the
			// WHERE clause through the OR's other branch.
			$this->assertCount(2, $rowsById, 'Both the parented child (via p.id > 0) and the childless row (via the OR branch) must survive');
			$this->assertSame($parent->getId(), $rowsById[$childWithParent->getId()]['p.id']);
			$this->assertNull($rowsById[$childWithoutParent->getId()]['p.id'], 'The childless row has no match for p at all — must stay NULL, not be dropped by an incorrect INNER JOIN promotion');
		}
	}
