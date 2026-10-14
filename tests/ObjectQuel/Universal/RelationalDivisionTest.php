<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;
	use Quellabs\ObjectQuel\Tests\Support\RetrieveSqlCompiler;

	/**
	 * Relational division with nested any(...): customers who bought every product in a required set.
	 *
	 * Fixtures:
	 *   products 1, 2 in category 'bundle'; product 3 in category 'other'
	 *   customer 1: order 1 (p1, p2), order 2 (p1 again)   — full coverage, duplicate match
	 *   customer 2: order 3 (p1)                            — partial coverage
	 *   customer 3: no orders                               — absent intermediate row
	 *   customer 4: order 4 (p3)                            — no coverage
	 *   customer 5: order 5 (p1), order 6 (p2)              — coverage across orders
	 *   order item 9 on missing order 99 (p2)               — dangling item, belongs to no one
	 */
	class RelationalDivisionTest extends ObjectQuelTestCase {

		private const string NS = 'Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\';

		protected array $truncateTables = [
			'rel_div_order_items',
			'rel_div_orders',
			'rel_div_customers',
			'rel_div_products',
			'rel_div_bundle_items',
		];

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO rel_div_products (id, category) VALUES (1, 'bundle'), (2, 'bundle'), (3, 'other')");
			$this->exec("INSERT INTO rel_div_customers (id, name) VALUES (1, 'full'), (2, 'partial'), (3, 'none'), (4, 'other'), (5, 'split')");
			$this->exec("INSERT INTO rel_div_orders (id, customer_id) VALUES (1, 1), (2, 1), (3, 2), (4, 4), (5, 5), (6, 5)");
			$this->exec("INSERT INTO rel_div_order_items (id, order_id, product_id) VALUES (1, 1, 1), (2, 1, 2), (3, 2, 1), (4, 3, 1), (5, 4, 3), (6, 5, 1), (7, 6, 2), (9, 99, 2)");
			$this->exec("INSERT INTO rel_div_bundle_items (id, bundle, product_id) VALUES (1, 'dup', 1), (2, 'dup', 1), (3, 'dup', 2), (4, 'p1-twice', 1), (5, 'p1-twice', 1)");
		}

		/**
		 * Returns the range declarations for customer, product, order and order item.
		 * @return string
		 */
		private function ranges(): string {
			return "
				range of c  is " . self::NS . "RelDivCustomerEntity
				range of rp is " . self::NS . "RelDivProductEntity
				range of o  is " . self::NS . "RelDivOrderEntity via c.orders
				range of oi is " . self::NS . "RelDivOrderItemEntity via o.items
			";
		}

		/**
		 * Returns the division query for the given product category.
		 * @param string $category
		 * @return string
		 */
		private function divisionQuery(string $category): string {
			return $this->ranges() . "
				retrieve (c.id)
				where not any(rp.id where
					rp.category = '{$category}' and
					not any(oi.id where oi.productId = rp.id)
				)
				sort by c.id asc
			";
		}

		/**
		 * Runs a query and returns the customer ids it retrieves.
		 * @param string $query
		 * @return int[]
		 */
		private function customerIds(string $query): array {
			return array_map(fn(array $row) => (int)$row['c.id'], iterator_to_array($this->em->executeQuery($query)));
		}

		/**
		 * Asserts the query is rejected at compile time with a message containing $message.
		 * @param string $query
		 * @param string $message
		 * @return void
		 */
		private function assertRejected(string $query, string $message): void {
			try {
				$this->em->executeQuery($query);
				self::fail('Expected the query to be rejected.');
			} catch (QuelException $exception) {
				self::assertStringContainsString($message, $exception->getMessage());
			}
		}

		// -------------------------------------------------------------------------
		// Division results
		// -------------------------------------------------------------------------

		public function testReturnsOwnersCoveringEveryRequiredRow(): void {
			// Customer 2 is partial, 3 has no orders, 4 bought only p3; the dangling item covers no one
			self::assertSame([1, 5], $this->customerIds($this->divisionQuery('bundle')));
		}

		public function testEmptyRequiredSetReturnsEveryOwner(): void {
			self::assertSame([1, 2, 3, 4, 5], $this->customerIds($this->divisionQuery('missing')));
		}

		public function testSingleRequiredRowMatchesOwnersWithThatRow(): void {
			self::assertSame([4], $this->customerIds($this->divisionQuery('other')));
		}

		public function testDuplicateRequiredRowsDoNotChangeTheResult(): void {
			$query = "
				range of c  is " . self::NS . "RelDivCustomerEntity
				range of bi is " . self::NS . "RelDivBundleItemEntity
				range of o  is " . self::NS . "RelDivOrderEntity via c.orders
				range of oi is " . self::NS . "RelDivOrderItemEntity via o.items
				retrieve (c.id)
				where not any(bi.id where
					bi.bundle = :bundle and
					not any(oi.id where oi.productId = bi.productId)
				)
				sort by c.id asc
			";

			self::assertSame([1, 5], array_map(
				fn(array $row) => (int)$row['c.id'],
				iterator_to_array($this->em->executeQuery($query, ['bundle' => 'dup']))
			));

			self::assertSame([1, 2, 5], array_map(
				fn(array $row) => (int)$row['c.id'],
				iterator_to_array($this->em->executeQuery($query, ['bundle' => 'p1-twice']))
			));
		}

		// -------------------------------------------------------------------------
		// Single-level any(...) over a via chain
		// -------------------------------------------------------------------------

		public function testAnyOverViaChainIsCorrelatedToTheOwner(): void {
			$query = $this->ranges() . "
				retrieve (c.id)
				where any(oi.id where oi.productId = 2)
				sort by c.id asc
			";

			self::assertSame([1, 5], $this->customerIds($query));
		}

		public function testNotAnyOverViaChainKeepsOwnersWithoutIntermediateRows(): void {
			$query = $this->ranges() . "
				retrieve (c.id)
				where not any(oi.id where oi.productId = 3)
				sort by c.id asc
			";

			self::assertSame([1, 2, 3, 5], $this->customerIds($query));
		}

		public function testAnyOverIntermediateRangeWithoutConditions(): void {
			$query = $this->ranges() . "
				retrieve (c.id)
				where any(o.id)
				sort by c.id asc
			";

			self::assertSame([1, 2, 4, 5], $this->customerIds($query));
		}

		// -------------------------------------------------------------------------
		// Generated SQL
		// -------------------------------------------------------------------------

		public function testGeneratesDoubleNotExistsWithQuantifierRangesOutsideRootQuery(): void {
			$sql = (new RetrieveSqlCompiler($this->em, new FakePlatformCapabilities('mysql')))->compile($this->divisionQuery('bundle'));

			self::assertSame(
				"SELECT `c`.`id` as `c.id` FROM `rel_div_customers` as `c` " .
				"WHERE NOT(EXISTS (SELECT 1 FROM `rel_div_products` `rp` " .
				"WHERE `rp`.`category` = 'bundle' AND NOT(EXISTS (SELECT 1 FROM `rel_div_orders` `o` " .
				"INNER JOIN `rel_div_order_items` `oi` ON `oi`.`order_id` = `o`.`id` " .
				"WHERE `oi`.`product_id` = `rp`.`id` AND `o`.`customer_id` = `c`.`id`)))) " .
				"ORDER BY `c`.`id` asc",
				$sql
			);
		}

		// -------------------------------------------------------------------------
		// SELECT-context any(...)
		// -------------------------------------------------------------------------

		/**
		 * Runs a query projecting any(...) as `h` and returns the distinct flag values.
		 * @param string $query
		 * @param array<string, mixed> $parameters
		 * @return int[]
		 */
		private function flags(string $query, array $parameters = []): array {
			$flags = array_map(fn(array $row) => (int)$row['h'], iterator_to_array($this->em->executeQuery($query, $parameters)));
			return array_values(array_unique($flags));
		}

		public function testSelectAnySubquerySelectsFromEveryUnjoinedRange(): void {
			$query = "
				range of c  is " . self::NS . "RelDivCustomerEntity
				range of p  is " . self::NS . "RelDivProductEntity
				range of bi is " . self::NS . "RelDivBundleItemEntity
				retrieve (c.id, h = any(p.id where p.id = bi.productId and bi.bundle = :bundle))
			";

			self::assertSame([1], $this->flags($query, ['bundle' => 'dup']));
			self::assertSame([0], $this->flags($query, ['bundle' => 'missing']));
		}

		public function testSelectAnyKeepsLeftJoinedRangeOptional(): void {
			// Product 3 has no bundle items; it only matches through the LEFT join's NULL-extended row
			self::assertSame([1], $this->flags("
				range of c  is " . self::NS . "RelDivCustomerEntity
				range of p  is " . self::NS . "RelDivProductEntity
				range of bi is " . self::NS . "RelDivBundleItemEntity via bi.productId = p.id
				retrieve (c.id, h = any(p.id where bi.bundle = 'missing' or p.category = 'other'))
			"));
		}

		// -------------------------------------------------------------------------
		// Rejected range scoping
		// -------------------------------------------------------------------------

		public function testRejectsAnyOverRangeBoundByTheRetrieve(): void {
			$this->assertRejected("
				range of o is " . self::NS . "RelDivOrderEntity
				retrieve (o.id)
				where any(o.id where o.customerId = 1)
			", "quantifies over range 'o'");
		}

		public function testRejectsRangeUsedBySiblingQuantifiers(): void {
			$this->assertRejected($this->ranges() . "
				retrieve (c.id)
				where any(oi.id where oi.productId = 1) and any(oi.id where oi.productId = 2)
			", "Range 'oi' is used by both");
		}

		public function testRejectsIntermediateRangeSharedBySiblingQuantifiers(): void {
			$this->assertRejected($this->ranges() . "
				range of oi2 is " . self::NS . "RelDivOrderItemEntity via o.items
				retrieve (c.id)
				where any(oi.id where oi.productId = 1) and any(oi2.id where oi2.productId = 2)
			", "Range 'o' is used by both");
		}

		public function testRejectsQuantifierRangesNotConnectedByVia(): void {
			$this->assertRejected("
				range of c  is " . self::NS . "RelDivCustomerEntity
				range of rp is " . self::NS . "RelDivProductEntity
				range of bi is " . self::NS . "RelDivBundleItemEntity
				retrieve (c.id)
				where any(rp.id where rp.id = bi.productId)
			", "must be connected to each other by via");
		}

		public function testRejectsNestedAnyInProjection(): void {
			$this->assertRejected($this->ranges() . "
				retrieve (c.id, covered = any(rp.id where any(oi.id where oi.productId = rp.id)))
			", "Nested any(...) is not supported in a retrieve statement's projection");
		}

		public function testRejectsNestedAnyInDeleteWhere(): void {
			$this->assertRejected("
				range of c is " . self::NS . "RelDivCustomerEntity
				delete c where any(c.id where any(c.id where c.id = 1))
			", "Nested any(...) is not supported in a delete statement's where clause");
		}

		public function testRejectsNestedAnyInReplaceWhere(): void {
			$this->assertRejected("
				range of c is " . self::NS . "RelDivCustomerEntity
				replace c (name = 'x') where any(c.id where any(c.id where c.id = 1))
			", "Nested any(...) is not supported in a replace statement's where clause");
		}
	}
