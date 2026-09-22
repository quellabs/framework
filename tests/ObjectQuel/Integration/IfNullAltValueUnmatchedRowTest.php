<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildEntity;

	/**
	 * Regression test: a reference used as ifnull()'s alt-value argument must not
	 * promote its range's LEFT JOIN to INNER, since the alt value is only ever
	 * consulted when the checked argument is NULL.
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
