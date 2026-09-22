<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPlainChildOfSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPlainParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftChildOfPlainParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftChildOfSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftParentEntity;

	/**
	 * How Cascade(remove) interacts with @SoftDelete: a dependent's outcome
	 * follows the parent's *actual* result, not a fixed default (see
	 * UnitOfWork::scheduleForDelete()/cascadeDeleteDependentObjects()).
	 *
	 *   - Parent soft-deleted, dependent has no @SoftDelete: left untouched.
	 *     Really deleting it would make the parent's soft-delete
	 *     irreversible for data reachable through this relation.
	 *   - Parent soft-deleted, dependent has @SoftDelete: soft-deleted too.
	 *   - Parent really deleted (forced, or because it has no @SoftDelete):
	 *     every dependent is really deleted too, regardless of its own
	 *     @SoftDelete — otherwise it's left pointing at a parent row that
	 *     no longer exists.
	 *
	 * See RelationshipCascadeForeignKeyTest for the base (non-soft-delete)
	 * cascade-remove/persist behavior this builds on, and its docblock for
	 * why these fixtures live in their own isolated directory and share
	 * $GLOBALS['test_em'].
	 */
	class SoftDeleteCascadeTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_soft_parents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, deleted_at DATETIME NULL) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_plain_child_of_soft_parent (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_soft_child_of_soft_parent (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id INT, deleted_at DATETIME NULL) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_plain_parents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_soft_child_of_plain_parent (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id INT, deleted_at DATETIME NULL) ENGINE=InnoDB');

			foreach ([
				'rel_plain_child_of_soft_parent', 'rel_soft_child_of_soft_parent', 'rel_soft_parents',
				'rel_soft_child_of_plain_parent', 'rel_plain_parents',
			] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			self::em()->getUnitOfWork()->clear();
		}

		// -------------------------------------------------------------------------
		// Soft-deletable parent, non-soft-deletable Cascade(remove) child
		// -------------------------------------------------------------------------

		public function testSoftDeletingParentLeavesNonSoftDeletableChildUntouched(): void {
			$em = self::em();

			$parent = new RelSoftParentEntity();
			$em->persist($parent);
			$em->flush();

			$child = new RelPlainChildOfSoftParentEntity();
			$child->parent = $parent;
			$em->persist($child);
			$em->flush();

			$em->remove($parent);
			$em->flush();

			// The child row must still be there, untouched.
			$rows = $em->getConnection()->execute('SELECT id FROM rel_plain_child_of_soft_parent')->fetchAll('assoc');
			self::assertCount(1, $rows);

			// The parent row must still be there too, marked deleted.
			$parentRows = $em->getConnection()->execute('SELECT deleted_at FROM rel_soft_parents WHERE id = ' . $parent->getId())->fetchAll('assoc');
			self::assertCount(1, $parentRows);
			self::assertNotNull($parentRows[0]['deleted_at']);
		}

		/**
		 * Mirrors testCascadeRemoveCatchesAnUnflushedNewOrderInTheSameUnitOfWork:
		 * a New (persisted but not yet flushed) child has no row in the
		 * database yet, so it's found by cascadeDeleteUnpersistedDependents()
		 * rather than the DB-driven lookup. It must be left scheduled for a
		 * normal insert, not skipped or scheduled for deletion.
		 */
		public function testSoftDeletingParentLeavesUnflushedNonSoftDeletableChildUnscheduled(): void {
			$em = self::em();

			$parent = new RelSoftParentEntity();
			$em->persist($parent);
			$em->flush();

			$child = new RelPlainChildOfSoftParentEntity();
			$child->parent = $parent;
			$em->persist($child);

			$em->remove($parent);
			$em->flush();

			self::assertNotNull($child->getId());

			$rows = $em->getConnection()->execute('SELECT parent_id FROM rel_plain_child_of_soft_parent')->fetchAll('assoc');
			self::assertCount(1, $rows);
			self::assertSame($parent->getId(), (int) $rows[0]['parent_id']);
		}

		public function testForceHardDeletingSoftDeletableParentHardDeletesNonSoftDeletableChildToo(): void {
			$em = self::em();

			$parent = new RelSoftParentEntity();
			$em->persist($parent);
			$em->flush();

			$child = new RelPlainChildOfSoftParentEntity();
			$child->parent = $parent;
			$em->persist($child);
			$em->flush();

			$em->remove($parent, hardDelete: true);
			$em->flush();

			$rows = $em->getConnection()->execute('SELECT id FROM rel_plain_child_of_soft_parent')->fetchAll('assoc');
			self::assertSame([], $rows);
		}

		// -------------------------------------------------------------------------
		// Soft-deletable parent, soft-deletable Cascade(remove) child
		// -------------------------------------------------------------------------

		public function testSoftDeletingParentAlsoSoftDeletesSoftDeletableChild(): void {
			$em = self::em();

			$parent = new RelSoftParentEntity();
			$em->persist($parent);
			$em->flush();

			$child = new RelSoftChildOfSoftParentEntity();
			$child->parent = $parent;
			$em->persist($child);
			$em->flush();

			$em->remove($parent);
			$em->flush();

			// Still there, but marked deleted — not really removed.
			$rows = $em->getConnection()->execute('SELECT deleted_at FROM rel_soft_child_of_soft_parent WHERE id = ' . $child->getId())->fetchAll('assoc');
			self::assertCount(1, $rows);
			self::assertNotNull($rows[0]['deleted_at']);
		}

		// -------------------------------------------------------------------------
		// Non-soft-deletable parent, soft-deletable Cascade(remove) child
		// -------------------------------------------------------------------------

		public function testHardDeletingPlainParentForceHardDeletesSoftDeletableChild(): void {
			$em = self::em();

			$parent = new RelPlainParentEntity();
			$em->persist($parent);
			$em->flush();

			$child = new RelSoftChildOfPlainParentEntity();
			$child->parent = $parent;
			$em->persist($child);
			$em->flush();

			// RelPlainParentEntity has no @SoftDelete, so this is always a real delete.
			$em->remove($parent);
			$em->flush();

			$rows = $em->getConnection()->execute('SELECT id FROM rel_soft_child_of_plain_parent')->fetchAll('assoc');
			self::assertSame([], $rows);
		}
	}
