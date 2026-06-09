<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use App\Entities\PostEntity;
	use App\Entities\UserEntity;
	use App\Enums\TestEnum;
	
	/**
	 * Tests soft-delete behaviour end-to-end.
	 *
	 * PostEntity gains a nullable deletedAt datetime column annotated with
	 * @Orm\SoftDelete. The column must be added to the posts table:
	 *
	 *   ALTER TABLE posts ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
	 *
	 * Soft-delete lifecycle:
	 *   - Mark:    $post->setDeletedAt(new \DateTime()); $em->flush();
	 *   - Restore: $post->setDeletedAt(null);            $em->flush();
	 *   - Hard:    $em->remove($post);                   $em->flush();
	 *
	 * Filter behaviour:
	 *   - Normal queries exclude rows where deleted_at IS NOT NULL.
	 *   - find() by primary key always returns the entity regardless of state.
	 *   - @ignoreSoftDelete true directive bypasses the filter entirely.
	 */
	class SoftDeleteTest extends ObjectQuelTestCase {
		
		// -------------------------------------------------------------------------
		// Fixtures
		// -------------------------------------------------------------------------
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob',   'hash2', 0)");
			
			// Posts 1 and 2 belong to alice; post 3 to bob.
			// deleted_at is NULL for all — none are soft-deleted at fixture time.
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id, deleted_at)
				VALUES (1, 'Active Post',  'Hello world', 1, '2024-01-01 00:00:00', 'pending',   '{\"id\":1,\"test\":\"hi\"}', 1, NULL)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id, deleted_at)
				VALUES (2, 'Another Post', 'Foo bar',     0, '2024-01-02 00:00:00', 'shipped',   '{\"id\":2,\"test\":\"hi\"}', 1, NULL)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id, deleted_at)
				VALUES (3, 'Bob Post',     'Baz qux',     1, '2024-01-03 00:00:00', 'delivered', '{\"id\":3,\"test\":\"hi\"}', 2, NULL)");
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------
		
		private function findPostById(int $id): ?PostEntity {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p)
				where p.id = :id
			", ['id' => $id]);
			
			return $result[0]['p'] ?? null;
		}
		
		private function softDeletePost(PostEntity $post): void {
			$post->setDeletedAt(new \DateTime());
			$this->em->flush();
			// Clear the identity map so subsequent queries hit the database
			// rather than returning the cached, already-modified instance.
			$this->em->getUnitOfWork()->clear();
		}
		
		// -------------------------------------------------------------------------
		// Filter: normal queries exclude soft-deleted rows
		// -------------------------------------------------------------------------
		
		public function testSoftDeletedPostIsExcludedFromNormalQuery(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			
			$this->softDeletePost($post);
			
			// The filter should hide it from a normal retrieve
			$found = $this->findPostById(1);
			$this->assertNull($found);
		}
		
		public function testOnlyActivPostsAreReturnedByDefault(): void {
			// Soft-delete post 1; posts 2 and 3 remain active
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			$this->softDeletePost($post);
			
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p)
			");
			
			$this->assertCount(2, $result);
			
			$ids = array_map(fn($row) => $row['p']->getId(), iterator_to_array($result));
			$this->assertNotContains(1, $ids);
			$this->assertContains(2, $ids);
			$this->assertContains(3, $ids);
		}
		
		public function testMultipleSoftDeletedPostsAreAllExcluded(): void {
			$post1 = $this->findPostById(1);
			$post2 = $this->findPostById(2);
			$this->assertNotNull($post1);
			$this->assertNotNull($post2);
			
			$post1->setDeletedAt(new \DateTime());
			$post2->setDeletedAt(new \DateTime());
			$this->em->flush();
			$this->em->getUnitOfWork()->clear();
			
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p)
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(3, $result[0]['p']->getId());
		}
		
		// -------------------------------------------------------------------------
		// find() bypasses the filter
		// -------------------------------------------------------------------------
		
		public function testFindByPrimaryKeyReturnsSoftDeletedEntity(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			$this->softDeletePost($post);
			
			// find() must return the entity regardless of soft-delete state
			$found = $this->em->find(PostEntity::class, 1);
			$this->assertNotNull($found);
			$this->assertSame(1, $found->getId());
			$this->assertNotNull($found->getDeletedAt());
		}
		
		// -------------------------------------------------------------------------
		// @ignoreSoftDelete directive
		// -------------------------------------------------------------------------
		
		public function testIgnoreSoftDeleteDirectiveReturnsAllRows(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			$this->softDeletePost($post);
			
			$result = $this->em->executeQuery("
				@ignoreSoftDelete true
				range of p is PostEntity
				retrieve (p)
			");
			
			// All three posts must be present
			$this->assertCount(3, $result);
		}
		
		public function testIgnoreSoftDeleteDirectiveReturnsSoftDeletedRow(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			$this->softDeletePost($post);
			
			$result = $this->em->executeQuery("
				@ignoreSoftDelete true
				range of p is PostEntity
				retrieve (p)
				where p.id = :id
			", ['id' => 1]);
			
			$this->assertCount(1, $result);
			$this->assertNotNull($result[0]['p']->getDeletedAt());
		}
		
		// -------------------------------------------------------------------------
		// Restore
		// -------------------------------------------------------------------------
		
		public function testRestoredPostIsVisibleAgain(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			$this->softDeletePost($post);
			
			// Confirm it's hidden
			$this->assertNull($this->findPostById(1));
			
			// Restore by setting deletedAt back to null
			$deleted = $this->em->find(PostEntity::class, 1);
			$this->assertNotNull($deleted);
			$deleted->setDeletedAt(null);
			$this->em->flush();
			$this->em->getUnitOfWork()->clear();
			
			// Now it should be visible again
			$restored = $this->findPostById(1);
			$this->assertNotNull($restored);
			$this->assertNull($restored->getDeletedAt());
		}
		
		// -------------------------------------------------------------------------
		// Hard delete still works on soft-deletable entities
		// -------------------------------------------------------------------------
		
		public function testHardDeleteRemovesRowPermanently(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			
			$this->em->remove($post);
			$this->em->flush();
			$this->em->getUnitOfWork()->clear();
			
			// Must be gone even with the directive that bypasses the soft-delete filter
			$result = $this->em->executeQuery("
				@ignoreSoftDelete true
				range of p is PostEntity
				retrieve (p)
				where p.id = :id
			", ['id' => 1]);
			
			$this->assertCount(0, $result);
		}
		
		// -------------------------------------------------------------------------
		// Filter applies correctly in joins
		// -------------------------------------------------------------------------
		
		public function testSoftDeletedPostIsExcludedFromJoinQuery(): void {
			// Soft-delete alice's first post
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			$this->softDeletePost($post);
			
			// Join query should only return alice's remaining active post (id=2)
			$result = $this->em->executeQuery("
				range of p is PostEntity
				range of u is UserEntity via p.user
				retrieve (p.id, u.username)
				where u.username = 'alice'
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(2, $result[0]['p.id']);
		}
		
		public function testActivePostCountIsCorrectAfterSoftDelete(): void {
			// Soft-delete two of the three posts
			$post1 = $this->findPostById(1);
			$post3 = $this->findPostById(3);
			$this->assertNotNull($post1);
			$this->assertNotNull($post3);
			
			$post1->setDeletedAt(new \DateTime());
			$post3->setDeletedAt(new \DateTime());
			$this->em->flush();
			$this->em->getUnitOfWork()->clear();
			
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p)
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(2, $result[0]['p']->getId());
		}
	}