<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPlainChildOfSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftParentEntity;

	/**
	 * Confirms soft-deleted datetime parents do not remove their child rows.
	 */
	class SoftDeleteDatetimeLeftJoinTest extends TestCase {

		/**
		 * Returns the test suite's entity manager.
		 * @return EntityManager
		 */
		private static function em(): EntityManager {
			$entityManager = $GLOBALS['test_em'];
			self::assertInstanceOf(EntityManager::class, $entityManager);
			return $entityManager;
		}

		/**
		 * Clears the relationship fixtures before each test.
		 * @return void
		 */
		protected function setUp(): void {
			$adapter = self::em()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_soft_parents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, deleted_at DATETIME NULL) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_plain_child_of_soft_parent (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id INT) ENGINE=InnoDB');

			foreach (['rel_plain_child_of_soft_parent', 'rel_soft_parents'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			self::em()->getUnitOfWork()->clear();
		}

		/**
		 * Keeps children visible when their datetime parent is soft-deleted.
		 * @return void
		 */
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
			self::assertNotNull($rows);

			$rowsById = [];
			foreach ($rows as $row) {
				self::assertArrayHasKey('ch.id', $row);
				self::assertIsInt($row['ch.id']);
				$rowsById[$row['ch.id']] = $row;
			}

			$this->assertCount(2, $rowsById, 'Both children must survive, including the one pointing at a soft-deleted parent');
			$deletedChildId = $childOfDeletedParent->getId();
			$activeChildId = $childOfActiveParent->getId();
			self::assertIsInt($deletedChildId);
			self::assertIsInt($activeChildId);
			self::assertArrayHasKey($deletedChildId, $rowsById);
			self::assertArrayHasKey($activeChildId, $rowsById);
			$this->assertNull($rowsById[$deletedChildId]['p.id'], 'The soft-deleted parent must not be visible through the join');
			$this->assertSame($activeParent->getId(), $rowsById[$activeChildId]['p.id']);
		}
	}
