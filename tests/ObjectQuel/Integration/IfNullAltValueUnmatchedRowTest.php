<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildEntity;

	/**
	 * Row-level regression test for the bug IfNullNonNullablePromotionTest's
	 * first (too-narrow) version of the DetectNonNullableField fix caused:
	 * a reference used as ifnull()'s alt-value argument was still treated as
	 * proof the referenced range's row must exist, which isn't sound — the
	 * alt value is only ever consulted when the checked argument is NULL, so
	 * whenever the checked argument is non-null, the alt value (and thus its
	 * range) is irrelevant to the result.
	 *
	 * Here `:val` is always bound to a non-null value, so
	 * `ifnull(:val, p.isDeleted)` always evaluates to `:val` regardless of
	 * `p.isDeleted` or whether `p` exists at all — the WHERE clause never
	 * actually depends on range `p`. Before the fix, referencing
	 * `p.isDeleted` as the alt value still promoted `p` from LEFT to INNER
	 * JOIN, which silently dropped the child with no parent at all — a row
	 * that has nothing to do with `p` in this query.
	 */
	class IfNullAltValueUnmatchedRowTest extends TestCase {

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

		public function testChildWithNoParentSurvivesWhenAltValueIsNeverActuallyConsulted(): void {
			$em = self::em();

			$childWithoutParent = new RelBoolSoftChildEntity();
			$em->persist($childWithoutParent);
			$em->flush();
			$em->getUnitOfWork()->clear();

			$plan = $em->explainQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id)
				where ifnull(:val, p.isDeleted) = :val
			", ['val' => false]);

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);

			$rows = $em->executeQuery("
				range of ch is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftChildEntity
				range of p is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelBoolSoftParentEntity via ch.parent
				retrieve (ch.id)
				where ifnull(:val, p.isDeleted) = :val
			", ['val' => false]);

			$ids = array_map(fn($row) => $row['ch.id'], iterator_to_array($rows));

			$this->assertContains($childWithoutParent->getId(), $ids);
		}
	}
