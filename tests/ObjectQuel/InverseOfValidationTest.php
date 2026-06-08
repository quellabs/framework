<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use App\Entities\PostEntity;
	use App\Entities\UserEntity;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	
	/**
	 * Tests validation of InverseOf annotations and via clause usage.
	 *
	 * Two categories are covered:
	 *  - Semantic errors: rejected at query analysis time before hitting the database.
	 *    Thrown as QuelException wrapping SemanticException.
	 *  - Regression guards: valid configurations that must not throw.
	 */
	class InverseOfValidationTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (1, 'First Post', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', '{\"id\": 1}', 1)");
		}
		
		/**
		 * Asserts that a query throws a QuelException caused by a SemanticException.
		 */
		private function assertSemanticError(callable $fn): void {
			try {
				$fn();
				$this->fail('Expected QuelException to be thrown');
			} catch (QuelException $e) {
				$this->assertInstanceOf(
					SemanticException::class,
					$e->getPrevious(),
					'Expected QuelException to wrap a SemanticException'
				);
			}
		}
		
		// -------------------------------------------------------------------------
		// via clause — semantic validation
		// -------------------------------------------------------------------------
		
		public function testViaWithColumnInsteadOfRelationThrows(): void {
			// userId is a column property, not a relation — via must reference a relation property
			$this->assertSemanticError(fn() => $this->em->executeQuery("
			range of p is PostEntity
			range of u is UserEntity via p.userId
			retrieve (p.title, u.username)
		"));
		}
		
		public function testViaWithNonExistentPropertyThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
			range of p is PostEntity
			range of u is UserEntity via p.nonexistent
			retrieve (p.title, u.username)
		"));
		}
		
		// -------------------------------------------------------------------------
		// Relation properties in expressions — semantic validation
		// -------------------------------------------------------------------------
		
		public function testRelationUsedInWhereClauseThrows(): void {
			// user is a ManyToOne relation — it cannot be used as a scalar in a where clause
			$this->assertSemanticError(fn() => $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.title)
			where p.user = 1
		"));
		}
		
		public function testRelationUsedInProjectionThrows(): void {
			// user is a ManyToOne relation — it cannot appear as a scalar in the retrieve list
			$this->assertSemanticError(fn() => $this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.user)
		"));
		}
		
		// -------------------------------------------------------------------------
		// Regression guards — valid configurations that must not throw
		// -------------------------------------------------------------------------
		
		public function testValidViaClauseDoesNotThrow(): void {
			$result = $this->em->executeQuery("
			range of p is PostEntity
			range of u is UserEntity via p.user
			retrieve (p.title, u.username)
		");
			
			$this->assertNotNull($result);
		}
		
		public function testValidInverseOfHydratesCollection(): void {
			$user = $this->em->find(UserEntity::class, 1);
			$this->assertNotNull($user);
			$this->assertInstanceOf(CollectionInterface::class, $user->posts);
		}
		
		public function testInverseOfDoesNotHydrateFromUnrelatedEntity(): void {
			// UserEntity::$posts is InverseOf(relation="user"). Loading two users
			// should assign each user only their own posts.
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', 0)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (2, 'Bob Post', 'Bob content', 1, '2024-01-02 00:00:00', 'pending', '{\"id\": 2}', 2)");
			
			$alice = $this->em->find(UserEntity::class, 1);
			$bob   = $this->em->find(UserEntity::class, 2);
			
			$this->assertNotNull($alice);
			$this->assertNotNull($bob);
			
			$aliceTitles = [];
			foreach ($alice->posts as $post) {
				$aliceTitles[] = $post->getTitle();
			}
			
			$bobTitles = [];
			foreach ($bob->posts as $post) {
				$bobTitles[] = $post->getTitle();
			}
			
			$this->assertCount(1, $aliceTitles, 'Alice should have exactly 1 post');
			$this->assertCount(1, $bobTitles, 'Bob should have exactly 1 post');
			$this->assertContains('First Post', $aliceTitles);
			$this->assertContains('Bob Post', $bobTitles);
			$this->assertNotContains('Bob Post', $aliceTitles);
			$this->assertNotContains('First Post', $bobTitles);
		}
	}