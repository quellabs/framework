<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftParentEntity;

	/**
	 * Regression coverage for bug-soft-delete-left-join-boolean-null.md:
	 * InjectSoftDeleteCondition's boolean branch compiled to a plain
	 * `range.property = false` WHERE condition, which is not NULL-safe. On an
	 * optional (LEFT JOIN) range, a child row with no related parent at all
	 * produced `NULL = false` (SQL's three-valued UNKNOWN), silently dropping
	 * the child row — even though it has nothing to do with any parent being
	 * soft-deleted.
	 *
	 * RelBoolSoftChildEntity.parentId is nullable, so `via child.parent`
	 * compiles to a real LEFT JOIN with some rows having no matching parent.
	 *
	 * Also covers the follow-up the bug report raised but left as an open
	 * question: for an optional relation, a soft-deleted related row is now
	 * ANDed onto the JOIN's own ON clause rather than the query's WHERE
	 * clause, so it behaves the same as an absent related row — the child
	 * survives with the parent's columns coming back NULL — instead of the
	 * child vanishing entirely the way a WHERE-clause filter forces
	 * regardless of join type.
	 */
	class SoftDeleteBooleanLeftJoinTest extends TestCase {

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
		 * A child row with no related parent at all (parentId NULL) must
		 * always survive the parent's boolean soft-delete filter, since
		 * there is nothing to soft-delete-check.
		 */
		public function testChildWithNoParentAtAllSurvivesBooleanSoftDeleteFilter(): void {
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

			$rows = $em->executeQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id)
				sort by ch.id asc
			");

			$ids = array_map(fn($row) => $row['ch.id'], iterator_to_array($rows));

			$this->assertCount(2, $ids, 'Both the child with a parent and the parentless child must survive the filter');
			$this->assertContains($childWithParent->getId(), $ids);
			$this->assertContains($childWithoutParent->getId(), $ids);
		}

		/**
		 * A child pointing at a soft-deleted parent must survive too — with
		 * the parent's columns coming back NULL, exactly as if the child had
		 * no parent at all. The filter still does its job (the soft-deleted
		 * parent's own data isn't visible through this join), but it no
		 * longer turns the LEFT JOIN into a de facto INNER JOIN.
		 */
		public function testChildOfSoftDeletedParentSurvivesWithParentColumnsNull(): void {
			$em = self::em();

			$deletedParent = new RelBoolSoftParentEntity();
			$deletedParent->setIsDeleted(true);
			$em->persist($deletedParent);

			$activeParent = new RelBoolSoftParentEntity();
			$em->persist($activeParent);

			$childOfDeletedParent = new RelBoolSoftChildEntity();
			$childOfDeletedParent->parent = $deletedParent;

			$childOfActiveParent = new RelBoolSoftChildEntity();
			$childOfActiveParent->parent = $activeParent;

			$childWithoutParent = new RelBoolSoftChildEntity();

			$em->persist($childOfDeletedParent);
			$em->persist($childOfActiveParent);
			$em->persist($childWithoutParent);
			$em->flush();
			$em->getUnitOfWork()->clear();

			$rows = $em->executeQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id, p.id)
				sort by ch.id asc
			");

			$rowsById = [];
			foreach ($rows as $row) {
				$rowsById[$row['ch.id']] = $row;
			}

			$this->assertCount(3, $rowsById, 'All three children must survive, including the one pointing at a soft-deleted parent');
			$this->assertNull($rowsById[$childOfDeletedParent->getId()]['p.id'], 'The soft-deleted parent must not be visible through the join');
			$this->assertSame($activeParent->getId(), $rowsById[$childOfActiveParent->getId()]['p.id']);
			$this->assertNull($rowsById[$childWithoutParent->getId()]['p.id']);
		}

		/**
		 * When a WHERE reference to a non-nullable field of the parent range
		 * promotes the LEFT JOIN to INNER (JoinOptimizer's ordinary
		 * optimization, unrelated to soft-delete), the soft-delete condition
		 * still lives in that range's ON clause. For an INNER JOIN, a
		 * predicate in ON filters identically to the same predicate in WHERE,
		 * so the child with a soft-deleted parent must still be excluded —
		 * this fix must not accidentally change results once the join type
		 * changes underneath it.
		 */
		public function testSoftDeleteConditionStillAppliesOnceRangeIsPromotedToInnerJoin(): void {
			$em = self::em();

			$activeParent = new RelBoolSoftParentEntity();
			$em->persist($activeParent);

			$deletedParent = new RelBoolSoftParentEntity();
			$deletedParent->setIsDeleted(true);
			$em->persist($deletedParent);

			$childOfActiveParent = new RelBoolSoftChildEntity();
			$childOfActiveParent->parent = $activeParent;

			$childOfDeletedParent = new RelBoolSoftChildEntity();
			$childOfDeletedParent->parent = $deletedParent;

			$childWithoutParent = new RelBoolSoftChildEntity();

			$em->persist($childOfActiveParent);
			$em->persist($childOfDeletedParent);
			$em->persist($childWithoutParent);
			$em->flush();
			$em->getUnitOfWork()->clear();

			// "p.id > 0" references a non-nullable column with no null check,
			// so JoinOptimizer promotes range 'p' from LEFT to INNER.
			$plan = $em->explainQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id, p.id)
				where p.id > 0
			");

			$this->assertStringContainsString('INNER JOIN', $plan->getSql()[0], 'Range p must have been promoted to INNER JOIN for this assertion to be meaningful');

			$rows = $em->executeQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id, p.id)
				where p.id > 0
			");

			$ids = array_map(fn($row) => $row['ch.id'], iterator_to_array($rows));

			// childWithoutParent is excluded here by "p.id > 0" itself (no match once
			// required) — an expected consequence of referencing a joined column in
			// WHERE, not something this fix changes.
			$this->assertCount(1, $ids);
			$this->assertContains($childOfActiveParent->getId(), $ids);
			$this->assertNotContains($childOfDeletedParent->getId(), $ids);
			$this->assertNotContains($childWithoutParent->getId(), $ids);
		}
	}
