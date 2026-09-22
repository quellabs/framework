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
		 * Contrast case: once the related parent is actually soft-deleted,
		 * the filter must still exclude the child that points at it — the
		 * fix must not turn the boolean filter into a no-op.
		 */
		public function testChildOfSoftDeletedParentIsStillExcluded(): void {
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
				retrieve (ch.id)
			");

			$ids = array_map(fn($row) => $row['ch.id'], iterator_to_array($rows));

			$this->assertCount(2, $ids);
			$this->assertNotContains($childOfDeletedParent->getId(), $ids);
			$this->assertContains($childOfActiveParent->getId(), $ids);
			$this->assertContains($childWithoutParent->getId(), $ids);
		}
	}
