<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildWithOwnDeleteEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftParentEntity;

	/**
	 * Coverage for a query where the primary (FROM) range and a joined range
	 * are both soft-deletable at once, exercising InjectSoftDeleteCondition's
	 * two placement paths (WHERE for the primary range, the range's own ON
	 * clause for the joined range) together in one query.
	 */
	class SoftDeleteMultipleRangesTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_bool_soft_parents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, is_deleted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_bool_soft_children_own_delete (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, is_deleted TINYINT(1) NOT NULL DEFAULT 0, parent_id INT UNSIGNED NULL) ENGINE=InnoDB');

			foreach (['rel_bool_soft_children_own_delete', 'rel_bool_soft_parents'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			self::em()->getUnitOfWork()->clear();
		}

		public function testBothRangesFilterIndependently(): void {
			$em = self::em();

			$activeParent = new RelBoolSoftParentEntity();
			$em->persist($activeParent);

			$deletedParent = new RelBoolSoftParentEntity();
			$deletedParent->setIsDeleted(true);
			$em->persist($deletedParent);

			// Active child with an active parent -> survives, parent visible.
			$activeChildActiveParent = new RelBoolSoftChildWithOwnDeleteEntity();
			$activeChildActiveParent->parent = $activeParent;

			// Active child with a soft-deleted parent -> survives, parent columns NULL.
			$activeChildDeletedParent = new RelBoolSoftChildWithOwnDeleteEntity();
			$activeChildDeletedParent->parent = $deletedParent;

			// Active child with no parent at all -> survives, parent columns NULL.
			$activeChildNoParent = new RelBoolSoftChildWithOwnDeleteEntity();

			// Soft-deleted child with an active parent -> excluded entirely,
			// regardless of the parent's own state.
			$deletedChildActiveParent = new RelBoolSoftChildWithOwnDeleteEntity();
			$deletedChildActiveParent->parent = $activeParent;
			$deletedChildActiveParent->setIsDeleted(true);

			foreach ([$activeChildActiveParent, $activeChildDeletedParent, $activeChildNoParent, $deletedChildActiveParent] as $entity) {
				$em->persist($entity);
			}

			$em->flush();
			$em->getUnitOfWork()->clear();

			$rows = $em->executeQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildWithOwnDeleteEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id, p.id)
			");

			$rowsById = [];
			foreach ($rows as $row) {
				$rowsById[$row['ch.id']] = $row;
			}

			$this->assertCount(3, $rowsById, 'The soft-deleted child must be excluded; the other three must all survive');
			$this->assertArrayNotHasKey($deletedChildActiveParent->getId(), $rowsById);

			$this->assertSame($activeParent->getId(), $rowsById[$activeChildActiveParent->getId()]['p.id']);
			$this->assertNull($rowsById[$activeChildDeletedParent->getId()]['p.id']);
			$this->assertNull($rowsById[$activeChildNoParent->getId()]['p.id']);
		}
	}
