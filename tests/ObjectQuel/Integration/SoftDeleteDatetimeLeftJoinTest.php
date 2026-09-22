<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPlainChildOfSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftParentEntity;

	/**
	 * Companion to SoftDeleteBooleanLeftJoinTest: confirms the same ON-clause
	 * fix (InjectSoftDeleteCondition attaching the filter to a joined range's
	 * own JOIN condition rather than the query's WHERE clause) applies
	 * equally to the `datetime` soft-delete branch, not just `boolean`. The
	 * bug report only described the boolean variant, but the
	 * `range.property IS NULL` WHERE-clause placement had the identical
	 * "soft-deleted related row drops the whole parent row" problem before
	 * this fix.
	 */
	class SoftDeleteDatetimeLeftJoinTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_soft_parents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, deleted_at DATETIME NULL) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_plain_child_of_soft_parent (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id INT) ENGINE=InnoDB');

			foreach (['rel_plain_child_of_soft_parent', 'rel_soft_parents'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			self::em()->getUnitOfWork()->clear();
		}

		public function testChildOfSoftDeletedDatetimeParentSurvivesWithParentColumnsNull(): void {
			$em = self::em();

			$deletedParent = new RelSoftParentEntity();
			$em->persist($deletedParent);

			$activeParent = new RelSoftParentEntity();
			$em->persist($activeParent);

			$childOfDeletedParent = new RelPlainChildOfSoftParentEntity();
			$childOfDeletedParent->parent = $deletedParent;

			$childOfActiveParent = new RelPlainChildOfSoftParentEntity();
			$childOfActiveParent->parent = $activeParent;

			$em->persist($childOfDeletedParent);
			$em->persist($childOfActiveParent);
			$em->flush();

			// Soft-delete after the children point at it, so the FK is valid at insert time.
			$deletedParent->setDeletedAt(new \DateTime());
			$em->flush();
			$em->getUnitOfWork()->clear();

			$rows = $em->executeQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelPlainChildOfSoftParentEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelSoftParentEntity via ch.parent
				retrieve (ch.id, p.id)
				sort by ch.id asc
			");

			$rowsById = [];
			foreach ($rows as $row) {
				$rowsById[$row['ch.id']] = $row;
			}

			$this->assertCount(2, $rowsById, 'Both children must survive, including the one pointing at a soft-deleted parent');
			$this->assertNull($rowsById[$childOfDeletedParent->getId()]['p.id'], 'The soft-deleted parent must not be visible through the join');
			$this->assertSame($activeParent->getId(), $rowsById[$childOfActiveParent->getId()]['p.id']);
		}
	}
