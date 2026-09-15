<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * End-to-end coverage for `append`'s @Orm\Column(default=...) injection —
	 * see App\Entities\DefaultColumnEntity and
	 * QuelToSQLAppend::collectDefaultColumnsToInit(). The `default_column_test`
	 * table's own DDL default for `priority` (99, set in tests/bootstrap.php)
	 * is deliberately different from the annotation's (0), so a persisted
	 * value of 0 can only have come from the annotation, never from the
	 * column falling through to the table's own DEFAULT.
	 */
	class AppendDefaultColumnTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['default_column_test'];

		/** @return array{name: string, priority: int}|false */
		private function readRow(string $name): array|false {
			return $this->em->getConnection()
				->execute('SELECT name, priority FROM default_column_test WHERE name = :name', ['name' => $name])
				->fetchAssoc();
		}

		public function testOmittedColumnIsPersistedWithItsDeclaredAnnotationDefault(): void {
			$result = $this->em->executeQuery(
				'range of d is App\Entities\DefaultColumnEntity
				append to d (name = :name)',
				['name' => 'widget']
			);

			$this->assertSame(1, $result->getAffectedRows());

			$row = $this->readRow('widget');

			$this->assertNotFalse($row);
			$this->assertSame(0, (int)$row['priority']);
		}

		public function testExplicitlySuppliedValueIsPersistedInstead(): void {
			$result = $this->em->executeQuery(
				'range of d is App\Entities\DefaultColumnEntity
				append to d (name = :name, priority = :priority)',
				['name' => 'gadget', 'priority' => 5]
			);

			$this->assertSame(1, $result->getAffectedRows());

			$row = $this->readRow('gadget');

			$this->assertNotFalse($row);
			$this->assertSame(5, (int)$row['priority']);
		}
	}
