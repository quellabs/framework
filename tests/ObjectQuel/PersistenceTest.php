<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use App\Entities\PostEntity;
	use App\Entities\UserEntity;
	use App\Enums\TestEnum;
	
	/**
	 * Tests entity persistence — insert, update and delete operations
	 * flushed through the EntityManager's UnitOfWork.
	 * Each test starts from a clean slate via ObjectQuelTestCase::setUp().
	 *
	 * Note: PostEntity carries a @PreUpdate hook that overwrites createdAt on
	 * every update. Update tests account for this and do not assert on that field.
	 */
	class PersistenceTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', 0)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (1, 'First Post', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', '{\"id\": 1, \"test\": \"hi\"}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (2, 'Second Post', 'Foo bar', 0, '2024-01-02 00:00:00', 'shipped', '{\"id\": 2, \"test\": \"hi\"}', 1)");
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Fetches a single UserEntity by username, or null if not found.
		 */
		private function findUserByUsername(string $username): ?UserEntity {
			$result = $this->em->executeQuery("
				range of u is UserEntity
				retrieve (u)
				where u.username = :username
			", ['username' => $username]);
			
			return $result[0]['u'] ?? null;
		}
		
		/**
		 * Fetches a single PostEntity by id, or null if not found.
		 */
		private function findPostById(int $id): ?PostEntity {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p)
				where p.id = :id
			", ['id' => $id]);
			
			return $result[0]['p'] ?? null;
		}
		
		// -------------------------------------------------------------------------
		// Insert
		// -------------------------------------------------------------------------
		
		public function testInsertNewUser(): void {
			$user = new UserEntity();
			$user->setUsername('charlie');
			$user->setPassword('hash3');
			$user->setBanned(false);
			
			$this->em->persist($user);
			$this->em->flush();
			
			// The entity should have received an auto-incremented id after flush
			$this->assertNotNull($user->getId());
			
			// Verify the row is retrievable via ObjectQuel
			$found = $this->findUserByUsername('charlie');
			$this->assertNotNull($found);
			$this->assertSame('charlie', $found->getUsername());
			$this->assertFalse($found->isBanned());
		}
		
		public function testInsertNewPost(): void {
			// Fetch an existing user to attach the post to
			$user = $this->findUserByUsername('alice');
			$this->assertNotNull($user);
			
			$post = new PostEntity();
			$post->setTitle('New Post');
			$post->setContent('Brand new content');
			$post->setPublished(false);
			$post->setCreatedAt(new \DateTime('2024-06-01 00:00:00'));
			$post->setTestEnum(TestEnum::PENDING);
			$post->setTestJson(['id' => 0, 'test' => '']);
			$post->user = $user;
			
			$this->em->persist($post);
			$this->em->flush();
			
			$this->assertNotNull($post->getId());
			
			$found = $this->findPostById($post->getId());
			$this->assertNotNull($found);
			$this->assertSame('New Post', $found->getTitle());
			$this->assertSame('Brand new content', $found->getContent());
			$this->assertFalse($found->getPublished());
		}
		
		public function testInsertIncreasesEntityCount(): void {
			$countBefore = count($this->em->executeQuery("
				range of u is UserEntity
				retrieve (u)
			"));
			
			$user = new UserEntity();
			$user->setUsername('dave');
			$user->setPassword('hash4');
			$user->setBanned(false);
			
			$this->em->persist($user);
			$this->em->flush();
			
			$countAfter = count($this->em->executeQuery("
				range of u is UserEntity
				retrieve (u)
			"));
			
			$this->assertSame($countBefore + 1, $countAfter);
		}
		
		// -------------------------------------------------------------------------
		// Update
		// -------------------------------------------------------------------------
		
		public function testUpdateUserUsername(): void {
			$user = $this->findUserByUsername('alice');
			$this->assertNotNull($user);
			
			$user->setUsername('alice-updated');
			$this->em->flush();
			
			// alice should no longer be findable under the old name
			$this->assertNull($this->findUserByUsername('alice'));
			
			// The updated name should be persisted
			$updated = $this->findUserByUsername('alice-updated');
			$this->assertNotNull($updated);
			$this->assertSame($user->getId(), $updated->getId());
		}
		
		public function testUpdateUserBannedStatus(): void {
			$user = $this->findUserByUsername('bob');
			$this->assertNotNull($user);
			$this->assertFalse($user->isBanned());
			
			$user->setBanned(true);
			$this->em->flush();
			
			$updated = $this->findUserByUsername('bob');
			$this->assertNotNull($updated);
			$this->assertTrue($updated->isBanned());
		}
		
		public function testUpdatePostTitle(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			$this->assertSame('First Post', $post->getTitle());
			
			$post->setTitle('Updated Title');
			$this->em->flush();
			
			// PostEntity's @PreUpdate hook fires here and overwrites createdAt —
			// we only assert on the field we changed
			$updated = $this->findPostById(1);
			$this->assertNotNull($updated);
			$this->assertSame('Updated Title', $updated->getTitle());
		}
		
		public function testUpdatePostPublishedFlag(): void {
			$post = $this->findPostById(2);
			$this->assertNotNull($post);
			$this->assertFalse($post->getPublished());
			
			$post->setPublished(true);
			$this->em->flush();
			
			$updated = $this->findPostById(2);
			$this->assertNotNull($updated);
			$this->assertTrue($updated->getPublished());
		}
		
		// -------------------------------------------------------------------------
		// Delete
		// -------------------------------------------------------------------------
		
		public function testDeletePost(): void {
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			
			$this->em->remove($post);
			$this->em->flush();
			
			$this->assertNull($this->findPostById(1));
		}
		
		public function testDeleteDecreasesEntityCount(): void {
			$countBefore = count($this->em->executeQuery("
				range of p is PostEntity
				retrieve (p)
			"));
			
			$post = $this->findPostById(1);
			$this->assertNotNull($post);
			
			$this->em->remove($post);
			$this->em->flush();
			
			$countAfter = count($this->em->executeQuery("
				range of p is PostEntity
				retrieve (p)
			"));
			
			$this->assertSame($countBefore - 1, $countAfter);
		}
		
		public function testDeleteUser(): void {
			// Delete posts belonging to alice first to satisfy the FK constraint
			$post1 = $this->findPostById(1);
			$post2 = $this->findPostById(2);
			
			if ($post1 !== null) {
				$this->em->remove($post1);
			}
			
			if ($post2 !== null) {
				$this->em->remove($post2);
			}
			
			$this->em->flush();
			
			// Now delete alice
			$user = $this->findUserByUsername('alice');
			$this->assertNotNull($user);
			
			$this->em->remove($user);
			$this->em->flush();
			
			$this->assertNull($this->findUserByUsername('alice'));
		}
	}