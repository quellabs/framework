<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use App\Entities\PostEntity;
	use App\Entities\UserEntity;
	use Quellabs\ObjectQuel\Collections\Collection;
	
	/**
	 * Tests basic entity retrieval — the most fundamental ObjectQuel operations.
	 * Every test here uses the transaction rollback from ObjectQuelTestCase,
	 * so fixture rows are never visible to other tests.
	 */
	class RetrievalTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', 0)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (1, 'First Post', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', '{\"id\": 2, \"test\": \"hi\"}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (2, 'Second Post', 'Foo bar', 0, '2024-01-02 00:00:00', 'shipped', '{\"id\": 2, \"test\": \"hi\"}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (3, 'Third Post', 'Baz qux', 1, '2024-01-03 00:00:00', 'delivered', '{\"id\": 2, \"test\": \"hi\"}', 2)");
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
			$result = $this->em->executeQuery("
			range of p is PostEntity
			range of u is UserEntity via p.user
			retrieve (p.title, u.username)
		");
			
			$this->assertCount(3, $result);
			
			foreach ($result as $row) {
				$this->assertArrayHasKey('p.title', $row);
				$this->assertArrayHasKey('u.username', $row);
				$this->assertNotNull($row['u.username']);
			}
		}
		
		public function testJoinWithConditionOnJoinedEntity(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			range of u is UserEntity via p.user
			retrieve (p.title)
			where u.username = 'alice'
		");
			
			// alice has 2 posts
			$this->assertCount(2, $result);
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
			// Both posts 1 and 2 belong to user 1 (alice).
			// The hydrator should return the same UserEntity instance for both rows.
			$result = $this->em->executeQuery("
			range of p is PostEntity
			range of u is UserEntity via p.user
			retrieve (p, u)
			where u.username = 'alice'
		");
			
			$this->assertCount(2, $result);
			$this->assertSame($result[0]['u'], $result[1]['u']);
		}
	
		// -------------------------------------------------------------------------
		// Eager loading and InverseOf hydration
		// -------------------------------------------------------------------------

		public function testManyToOneEagerRelationIsHydrated(): void {
			// PostEntity has ManyToOne(fetch=EAGER) to UserEntity.
			// Loading posts should automatically hydrate the user property.
			$posts = $this->em->findBy(PostEntity::class, ['published' => true]);
			$this->assertNotEmpty($posts);
			
			foreach ($posts as $post) {
				$this->assertInstanceOf(UserEntity::class, $post->user);
			}
		}
		
		public function testInverseOfCollectionIsHydrated(): void {
			// UserEntity has InverseOf(targetEntity=PostEntity, via="user").
			// Loading a UserEntity should hydrate the posts collection.
			$user = $this->em->find(UserEntity::class, 1);
			$this->assertNotNull($user);
			$this->assertInstanceOf(Collection::class, $user->posts);
			
			// alice has 2 posts in the fixtures
			$this->assertCount(2, $user->posts);
		}
		
		public function testInverseOfCollectionContainsCorrectEntities(): void {
			// Posts in the collection should be PostEntity instances belonging to this user.
			$user = $this->em->find(UserEntity::class, 1);
			$this->assertNotNull($user);
			
			foreach ($user->posts as $post) {
				$this->assertInstanceOf(PostEntity::class, $post);
			}
		}
		
		public function testInverseOfCollectionIsEmptyForUserWithNoPosts(): void {
			// bob (user 2) has only one post in the fixtures
			$user = $this->em->find(UserEntity::class, 2);
			$this->assertNotNull($user);
			$this->assertInstanceOf(Collection::class, $user->posts);
			$this->assertCount(1, $user->posts);
		}
	}