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
	 * How Cascade(remove) interacts with soft-delete: a dependent's outcome follows
	 * the parent's actual result (soft/hard), not a fixed default — see
	 * UnitOfWork::scheduleForDelete()/restore().
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

		/** An unflushed (New) child must stay scheduled for insert, found via cascadeDeleteUnpersistedDependents(). */
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

		// -------------------------------------------------------------------------
		// restore() cascades to currently soft-deleted Cascade(remove) dependents
		// -------------------------------------------------------------------------

		public function testRestoringParentAlsoRestoresCascadeSoftDeletedChild(): void {
			$em = self::em();

			$parent = new RelSoftParentEntity();
			$em->persist($parent);
			$em->flush();
			$parentId = $parent->getId();

			$child = new RelSoftChildOfSoftParentEntity();
			$child->parent = $parent;
			$em->persist($child);
			$em->flush();
			$childId = $child->getId();

			$em->remove($parent);
			$em->flush();
			$em->getUnitOfWork()->clear();

			$managedParent = $em->find(RelSoftParentEntity::class, $parentId);
			self::assertNotNull($managedParent);
			self::assertNotNull($managedParent->getDeletedAt());

			$em->restore($managedParent);
			$em->flush();

			$parentRows = $em->getConnection()->execute('SELECT deleted_at FROM rel_soft_parents WHERE id = ' . $parentId)->fetchAll('assoc');
			self::assertNull($parentRows[0]['deleted_at']);

			$childRows = $em->getConnection()->execute('SELECT deleted_at FROM rel_soft_child_of_soft_parent WHERE id = ' . $childId)->fetchAll('assoc');
			self::assertNull($childRows[0]['deleted_at']);
		}

		public function testRestoringParentLeavesAlreadyActiveChildUntouched(): void {
			$em = self::em();

			$parent = new RelSoftParentEntity();
			$em->persist($parent);
			$em->flush();
			$parentId = $parent->getId();

			$child = new RelSoftChildOfSoftParentEntity();
			$child->parent = $parent;
			$em->persist($child);
			$em->flush();
			$childId = $child->getId();

			// Soft-delete the parent directly, bypassing remove()'s cascade,
			// so the child stays active.
			$parent->setDeletedAt(new \DateTime());
			$em->flush();
			$em->getUnitOfWork()->clear();

			$managedParent = $em->find(RelSoftParentEntity::class, $parentId);
			self::assertNotNull($managedParent);

			$em->restore($managedParent);
			$em->flush();

			$childRows = $em->getConnection()->execute('SELECT deleted_at FROM rel_soft_child_of_soft_parent WHERE id = ' . $childId)->fetchAll('assoc');
			self::assertNull($childRows[0]['deleted_at']);
		}

		/** restore() also restores a dependent that was soft-deleted independently of the parent. */
		public function testRestoringParentAlsoRestoresIndependentlySoftDeletedChild(): void {
			$em = self::em();

			$parent = new RelSoftParentEntity();
			$em->persist($parent);
			$em->flush();
			$parentId = $parent->getId();

			$child = new RelSoftChildOfSoftParentEntity();
			$child->parent = $parent;
			$em->persist($child);
			$em->flush();
			$childId = $child->getId();

			// Soft-delete the child on its own — the parent stays active.
			$child->setDeletedAt(new \DateTime());
			$em->flush();

			// Now soft-delete the parent too, directly (not via remove()'s
			// cascade — the child is already deleted, so cascade wouldn't
			// touch it anyway).
			$parent->setDeletedAt(new \DateTime());
			$em->flush();
			$em->getUnitOfWork()->clear();

			$managedParent = $em->find(RelSoftParentEntity::class, $parentId);
			self::assertNotNull($managedParent);

			$em->restore($managedParent);
			$em->flush();

			$childRows = $em->getConnection()->execute('SELECT deleted_at FROM rel_soft_child_of_soft_parent WHERE id = ' . $childId)->fetchAll('assoc');
			self::assertNull($childRows[0]['deleted_at']);
		}
	}
