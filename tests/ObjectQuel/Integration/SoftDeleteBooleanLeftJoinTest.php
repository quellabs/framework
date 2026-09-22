<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftParentEntity;

	/**
	 * Regression coverage: InjectSoftDeleteCondition's boolean branch must not compile
	 * to a plain WHERE `= false` on a LEFT JOIN range, since NULL = false silently
	 * drops rows with no related parent.
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

		/** A child with no related parent must always survive the parent's boolean soft-delete filter. */
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

		/** A child of a soft-deleted parent must survive with the parent's columns NULL. */
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

		/** The ON-clause soft-delete condition must still apply once the range is promoted to INNER JOIN. */
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

			// "p.id > 0" promotes range 'p' from LEFT to INNER.
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

			// childWithoutParent is excluded by "p.id > 0" itself, not by this fix.
			$this->assertCount(1, $ids);
			$this->assertContains($childOfActiveParent->getId(), $ids);
			$this->assertNotContains($childOfDeletedParent->getId(), $ids);
			$this->assertNotContains($childWithoutParent->getId(), $ids);
		}
	}
