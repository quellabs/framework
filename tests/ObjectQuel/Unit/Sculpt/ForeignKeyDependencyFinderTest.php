<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyDependencyFinder;

	class ForeignKeyDependencyFinderTest extends TestCase {

		/**
		 * @param array<string, list<string>> $tableForeignKeyNames Table => constraint names it owns
		 * @param array<string, ForeignKeyDefinition> $foreignKeysByName Constraint name => live definition
		 */
		private function makeAdapter(array $tableForeignKeyNames, array $foreignKeysByName): DatabaseAdapter {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getTables')->willReturn(array_keys($tableForeignKeyNames));

			$adapter->method('getForeignKeys')->willReturnCallback(
				function (string $tableName) use ($tableForeignKeyNames, $foreignKeysByName) {
					$result = [];

					foreach ($tableForeignKeyNames[$tableName] ?? [] as $name) {
						$result[$name] = $foreignKeysByName[$name];
					}

					return $result;
				}
			);

			return $adapter;
		}

		public function testFindsAConstraintOnAnotherTableReferencingTheGivenTable(): void {
			$fk = new ForeignKeyDefinition(['customer_id'], 'customers', ['id'], 'RESTRICT', 'NO ACTION');
			$adapter = $this->makeAdapter(
				['orders' => ['fk_orders_customer_id'], 'customers' => []],
				['fk_orders_customer_id' => $fk]
			);

			$result = (new ForeignKeyDependencyFinder($adapter))->findReferencing(['customers']);

			self::assertSame(['orders' => ['fk_orders_customer_id' => $fk]], $result);
		}

		public function testIgnoresAConstraintReferencingAnUnrelatedTable(): void {
			$fk = new ForeignKeyDefinition(['product_id'], 'products', ['id'], 'RESTRICT', 'NO ACTION');
			$adapter = $this->makeAdapter(
				['orders' => ['fk_orders_product_id']],
				['fk_orders_product_id' => $fk]
			);

			self::assertSame([], (new ForeignKeyDependencyFinder($adapter))->findReferencing(['customers']));
		}

		/**
		 * A table can hold a foreign key pointing at its own primary key —
		 * the owner table and the referenced table are the same name.
		 */
		public function testFindsASelfReferencingConstraint(): void {
			$fk = new ForeignKeyDefinition(['parent_id'], 'categories', ['id'], 'RESTRICT', 'NO ACTION');
			$adapter = $this->makeAdapter(
				['categories' => ['fk_categories_parent_id']],
				['fk_categories_parent_id' => $fk]
			);

			$result = (new ForeignKeyDependencyFinder($adapter))->findReferencing(['categories']);

			self::assertSame(['categories' => ['fk_categories_parent_id' => $fk]], $result);
		}

		public function testReturnsEmptyArrayWhenNoTableReferencesAnyGivenTable(): void {
			$adapter = $this->makeAdapter(['orders' => []], []);

			self::assertSame([], (new ForeignKeyDependencyFinder($adapter))->findReferencing(['customers']));
		}
	}
