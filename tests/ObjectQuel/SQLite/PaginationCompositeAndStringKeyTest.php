<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use App\Entities\StringPkEntity;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderEntity;

	/**
	 * Regression coverage for PaginationTransformer's window-pagination page
	 * filter: a string primary key must never be guessed into a numeric
	 * AstNumber literal, and a composite primary key must page by the full
	 * key tuple rather than silently collapsing to its first component.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class PaginationCompositeAndStringKeyTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM string_pk_entities');
			$adapter->execute('DELETE FROM cki_orders');
			$adapter->execute('DELETE FROM cki_order_lines');

			self::em()->getUnitOfWork()->clear();
		}

		public function testStringPrimaryKeyPagesCorrectlyWithoutBecomingANumericLiteral(): void {
			$em = self::em();
			$adapter = $em->getConnection();

			// Codes are intentionally not zero-padded numeric-looking strings in
			// places, and 'item-09' sorts before 'item-10' lexically — if the page
			// filter mishandled the key as numeric, binding would fail outright or
			// silently coerce these to integers.
			$codes = ['item-01', 'item-02', 'item-03', 'item-04', 'item-05'];

			foreach ($codes as $index => $code) {
				$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('{$code}', {$index})");
			}

			$page0 = $em->getAll('range of e is App\\Entities\\StringPkEntity retrieve (e) sort by e.position window 0, 2');
			$page1 = $em->getAll('range of e is App\\Entities\\StringPkEntity retrieve (e) sort by e.position window 1, 2');
			$page2 = $em->getAll('range of e is App\\Entities\\StringPkEntity retrieve (e) sort by e.position window 2, 2');

			self::assertSame(['item-01', 'item-02'], $this->codesOf($page0));
			self::assertSame(['item-03', 'item-04'], $this->codesOf($page1));
			self::assertSame(['item-05'], $this->codesOf($page2));
		}

		public function testCompositePrimaryKeyPagesByTheFullTupleNotJustTheFirstComponent(): void {
			$em = self::em();
			$adapter = $em->getConnection();

			// Two tenants, each with several orders, sorted by a non-key column
			// (amount) — exercises both composite paging and the "no SELECT
			// DISTINCT while sorting on another column" fix, since the fetch pass
			// must still return rows in amount order without projecting amount.
			$orders = [
				[1, 'A-1', 10],
				[1, 'A-2', 20],
				[1, 'A-3', 30],
				[2, 'A-1', 40],
				[2, 'A-2', 50],
			];

			foreach ($orders as [$tenantId, $number, $amount]) {
				$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES ({$tenantId}, '{$number}', {$amount})");
			}

			$page0 = $em->getAll('range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity retrieve (o) sort by o.amount window 0, 2');
			$page1 = $em->getAll('range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity retrieve (o) sort by o.amount window 1, 2');
			$page2 = $em->getAll('range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity retrieve (o) sort by o.amount window 2, 2');

			self::assertSame([[1, 'A-1'], [1, 'A-2']], $this->keysOf($page0));
			self::assertSame([[1, 'A-3'], [2, 'A-1']], $this->keysOf($page1));
			self::assertSame([[2, 'A-2']], $this->keysOf($page2));
		}

		/**
		 * @param array<int, array<string, mixed>> $rows
		 * @return list<string>
		 */
		private function codesOf(array $rows): array {
			return array_map(static fn($row) => $row['e']->getCode(), $rows);
		}

		/**
		 * @param array<int, array<string, mixed>> $rows
		 * @return list<array{0: int, 1: string}>
		 */
		private function keysOf(array $rows): array {
			return array_map(
				static fn($row) => [$row['o']->getTenantId(), $row['o']->getNumber()],
				$rows
			);
		}
	}
