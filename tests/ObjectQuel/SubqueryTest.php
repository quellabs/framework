<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	
	/**
	 * Tests subquery range behaviour — the derived-table feature added in the
	 * recent refactor. Covers SQL generation correctness, hydration of scalar
	 * values from subquery ranges, and semantic validation rules.
	 */
	class SubqueryTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, user_id)
            VALUES (1, 'First Post', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, user_id)
            VALUES (2, 'Second Post', 'Foo bar', 0, '2024-01-02 00:00:00', 'shipped', 1)");
		}
		
		// -------------------------------------------------------------------------
		// Scalar property retrieval from subquery range
		// -------------------------------------------------------------------------
		
		public function testSubqueryScalarPropertyRetrieval(): void {
			$result = $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x.id)
		");
			
			$this->assertCount(2, $result);
			$ids = array_column($result->fetchAll(), 'x.id');
			$this->assertCount(2, $ids);
			$this->assertContainsOnly('int', $ids);
		}
		
		public function testSubqueryRetrievesCorrectValues(): void {
			$result = $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x.id, x.title)
		");
			
			$this->assertCount(2, $result);
			
			foreach ($result as $row) {
				$this->assertArrayHasKey('x.id', $row);
				$this->assertArrayHasKey('x.title', $row);
				$this->assertIsInt($row['x.id']);
				$this->assertIsString($row['x.title']);
			}
		}
		
		public function testSubqueryWithConditionOnOuterRange(): void {
			$result = $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x.id, x.title)
			where x.published = true
		");
			
			$this->assertCount(1, $result);
			$this->assertSame(1, $result[0]['x.id']);
			$this->assertSame('First Post', $result[0]['x.title']);
		}
		
		// -------------------------------------------------------------------------
		// Column alias correctness — inner aliases use outer range name
		// -------------------------------------------------------------------------
		
		public function testSubqueryInnerColumnsAliasedWithOuterRangeName(): void {
			// This test validates that the generated SQL uses "x.id" not "y.id"
			// as the column alias inside the derived table. If aliasing is wrong,
			// MySQL will throw "Unknown column" and the query will fail entirely.
			$result = $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x.id, x.title, x.content)
		");
			
			// If we get here without an exception, aliasing is correct
			$this->assertCount(2, $result);
		}
		
		// -------------------------------------------------------------------------
		// Semantic validation
		// -------------------------------------------------------------------------
		
		public function testRetrievingEntireSubqueryRangeThrows(): void {
			try {
				$this->em->executeQuery("
				range of x is (
					range of y is PostEntity
					retrieve(y)
				)
				retrieve (x)
			");
				$this->fail('Expected QuelException to be thrown');
			} catch (QuelException $e) {
				$this->assertInstanceOf(
					SemanticException::class,
					$e->getPrevious(),
					'Expected QuelException to wrap a SemanticException'
				);
			}
		}
		
		public function testInnerQueryCanRetrieveEntireEntity(): void {
			// retrieve(y) inside the subquery must be allowed —
			// it's only retrieve(x) on the outer range that should throw.
			$result = $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x.id)
		");
			
			$this->assertCount(2, $result);
		}
		
		// -------------------------------------------------------------------------
		// Row count and ordering
		// -------------------------------------------------------------------------
		
		public function testSubqueryRowCountMatchesInnerQuery(): void {
			$direct = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.id)
		");
			
			$subquery = $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x.id)
		");
			
			$this->assertCount(count($direct), $subquery);
		}
	}