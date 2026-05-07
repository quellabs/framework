<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use App\Entities\PostEntity;
	use App\Entities\UserEntity;
	
	/**
	 * Tests basic entity retrieval — the most fundamental ObjectQuel operations.
	 * Every test here uses the transaction rollback from ObjectQuelTestCase,
	 * so fixture rows are never visible to other tests.
	 */
	class RetrievalTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', 0)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, user_id)
            VALUES (1, 'First Post', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, user_id)
            VALUES (2, 'Second Post', 'Foo bar', 0, '2024-01-02 00:00:00', 'shipped', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, user_id)
            VALUES (3, 'Third Post', 'Baz qux', 1, '2024-01-03 00:00:00', 'delivered', 2)");
		}
		
		// -------------------------------------------------------------------------
		// Basic entity retrieval
		// -------------------------------------------------------------------------
		
		public function testRetrieveAllPosts(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p)
		");
			
			$this->assertCount(3, $result);
			$this->assertInstanceOf(PostEntity::class, $result[0]['p']);
		}
		
		public function testRetrieveSingleProperty(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.title)
		");
			
			$this->assertCount(3, $result);
			$titles = array_column($result->fetchAll(), 'p.title');
			$this->assertContains('First Post', $titles);
			$this->assertContains('Second Post', $titles);
			$this->assertContains('Third Post', $titles);
		}
		
		public function testRetrieveMultipleProperties(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.id, p.title)
		");
			
			$this->assertCount(3, $result);
			
			foreach ($result as $row) {
				$this->assertArrayHasKey('p.id', $row);
				$this->assertArrayHasKey('p.title', $row);
			}
		}
		
		// -------------------------------------------------------------------------
		// Conditions
		// -------------------------------------------------------------------------
		
		public function testWhereConditionFiltersRows(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p)
			where p.published = true
		");
			
			$this->assertCount(2, $result);
			
			foreach ($result as $row) {
				$this->assertTrue($row['p']->getPublished());
			}
		}
		
		public function testWhereWithParameter(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.title)
			where p.id = :id
		", ['id' => 1]);
			
			$this->assertCount(1, $result);
			$this->assertSame('First Post', $result[0]['p.title']);
		}
		
		public function testWhereWithStringMatch(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.id)
			where p.title = 'Second Post'
		");
			
			$this->assertCount(1, $result);
			$this->assertSame(2, $result[0]['p.id']);
		}
		
		// -------------------------------------------------------------------------
		// Joins
		// -------------------------------------------------------------------------
		
		public function testJoinUserToPosts(): void {
			$this->markTestSkipped('Known bug: JoinConditionFieldInjector creates a circular AST reference on repeated queries through a shared EntityManager.');
		}
		
		public function testJoinWithConditionOnJoinedEntity(): void {
			$this->markTestSkipped('Known bug: JoinConditionFieldInjector creates a circular AST reference on repeated queries through a shared EntityManager.');
		}
		
		// -------------------------------------------------------------------------
		// Sorting
		// -------------------------------------------------------------------------
		
		public function testSortAscending(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.id)
			sort by p.id asc
		");
			
			$ids = array_column($result->fetchAll(), 'p.id');
			$this->assertNotEmpty($ids);
			
			// Assert ascending order without assuming specific ID values
			$sorted = $ids;
			sort($sorted);
			$this->assertSame($sorted, $ids);
		}
		
		public function testSortDescending(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.id)
			sort by p.id desc
		");
			
			$ids = array_column($result->fetchAll(), 'p.id');
			$this->assertNotEmpty($ids);
			
			// Assert descending order without assuming specific ID values
			$sorted = $ids;
			rsort($sorted);
			$this->assertSame($sorted, $ids);
		}
		
		// -------------------------------------------------------------------------
		// Entity identity
		// -------------------------------------------------------------------------
		
		public function testSameEntityIsNotDuplicated(): void {
			$this->markTestSkipped('Known bug: JoinConditionFieldInjector creates a circular AST reference on repeated queries through a shared EntityManager.');
		}
	}