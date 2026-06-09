<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use App\Entities\PostEntity;
	use App\Entities\UserEntity;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * Tests via-clause traversal through an InverseOf property.
	 *
	 * The query form under test is:
	 *
	 *   range of c is UserEntity
	 *   range of a is PostEntity via c.posts
	 *   retrieve (c, a)
	 *
	 * UserEntity::$posts carries @InverseOf(targetEntity=PostEntity::class, relation="user").
	 * The via clause must resolve that to the owning-side ManyToOne on PostEntity::$user
	 * and synthesise the correct JOIN condition (posts.user_id = users.id).
	 *
	 * This is the mirror image of the forward direction (range of u via p.user), which
	 * traverses a ManyToOne directly. Here the traversal starts on the inverse side.
	 */
	class InverseOfViaTraversalTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob',   'hash2', 0)");
			
			// alice owns posts 1 and 2; bob owns post 3
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (1, 'Alice Post 1', 'Content 1', 1, '2024-01-01 00:00:00', 'pending',   '{\"id\": 1}', 1)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (2, 'Alice Post 2', 'Content 2', 0, '2024-01-02 00:00:00', 'shipped',   '{\"id\": 1}', 1)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (3, 'Bob Post 1',   'Content 3', 1, '2024-01-03 00:00:00', 'delivered', '{\"id\": 2}', 2)");
		}
		
		// -------------------------------------------------------------------------
		// Basic traversal
		// -------------------------------------------------------------------------
		
		public function testInverseOfViaReturnsAllPairs(): void {
			// 2 users × their respective posts = 3 rows total
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c, a)
			");
			
			$this->assertCount(3, $result);
		}
		
		public function testInverseOfViaReturnsCorrectEntityTypes(): void {
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c, a)
			");
			
			foreach ($result as $row) {
				$this->assertInstanceOf(UserEntity::class, $row['c']);
				$this->assertInstanceOf(PostEntity::class, $row['a']);
			}
		}
		
		public function testInverseOfViaJoinsOnlyOwnedPosts(): void {
			// Every row must pair each post with the user that owns it —
			// never a post with a user it does not belong to.
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c.id, a.id)
			");
			
			// Build a map of post-id => owner-id from the fixtures
			$ownership = [1 => 1, 2 => 1, 3 => 2];
			
			foreach ($result as $row) {
				$expectedOwner = $ownership[$row['a.id']];
				$this->assertSame(
					$expectedOwner,
					$row['c.id'],
					"Post {$row['a.id']} should belong to user $expectedOwner, got {$row['c.id']}"
				);
			}
		}
		
		// -------------------------------------------------------------------------
		// Row counts per user
		// -------------------------------------------------------------------------
		
		public function testInverseOfViaRowCountForAlice(): void {
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c.id, a.id)
			where c.username = 'alice'
			");
			
			// alice owns 2 posts
			$this->assertCount(2, $result);
		}
		
		public function testInverseOfViaRowCountForBob(): void {
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c.id, a.id)
			where c.username = 'bob'
			");
			
			// bob owns 1 post
			$this->assertCount(1, $result);
		}
		
		// -------------------------------------------------------------------------
		// Entity identity — same instance across rows
		// -------------------------------------------------------------------------
		
		public function testSameUserInstanceReturnedForBothAlicePosts(): void {
			// alice appears in two rows; the hydrator must return the same object instance.
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c, a)
			where c.username = 'alice'
			sort by a.id asc
			");
			
			$this->assertCount(2, $result);
			$this->assertSame($result[0]['c'], $result[1]['c']);
		}
		
		// -------------------------------------------------------------------------
		// Conditions on the joined (InverseOf) side
		// -------------------------------------------------------------------------
		
		public function testConditionOnJoinedPostFilters(): void {
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c.username, a.title)
			where a.published = true
			");
			
			// Posts 1 (alice) and 3 (bob) are published; post 2 (alice, unpublished) must be absent
			$this->assertCount(2, $result);
			
			foreach ($result as $row) {
				$this->assertArrayHasKey('a.title', $row);
				$this->assertNotSame('Alice Post 2', $row['a.title']);
			}
		}
		
		public function testConditionOnBothSidesFilters(): void {
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c.username, a.title)
			where c.username = 'alice' and a.published = true
			");
			
			// Only alice's published post (post 1)
			$this->assertCount(1, $result);
			$this->assertSame('alice',       $result[0]['c.username']);
			$this->assertSame('Alice Post 1', $result[0]['a.title']);
		}
		
		// -------------------------------------------------------------------------
		// Projection
		// -------------------------------------------------------------------------
		
		public function testProjectScalarPropertiesFromBothRanges(): void {
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c.username, a.title)
			sort by a.id asc
			");
			
			$this->assertCount(3, $result);
			
			foreach ($result as $row) {
				$this->assertArrayHasKey('c.username', $row);
				$this->assertArrayHasKey('a.title',    $row);
				$this->assertIsString($row['c.username']);
				$this->assertIsString($row['a.title']);
			}
		}
		
		// -------------------------------------------------------------------------
		// No posts — user with no owned rows must produce no result rows
		// -------------------------------------------------------------------------
		
		public function testUserWithNoPostsProducesOneRowWithNullPost(): void {
			// via-clause joins are LEFT JOINs by default. A user with no posts still appears
			// in the result set paired with a null post entity.
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (3, 'charlie', 'hash3', 0)");
			
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c, a)
			where c.username = 'charlie'
			");
			
			$this->assertCount(1, $result);
			$this->assertInstanceOf(UserEntity::class, $result[0]['c']);
			$this->assertNull($result[0]['a']);
		}
		
		public function testUnfilteredQueryIncludesNullPostRowForUserWithNoPosts(): void {
			// With no WHERE clause the LEFT JOIN must surface a null-post row for charlie
			// alongside the real rows for alice and bob.
			// Fixtures: alice=2 posts, bob=1 post, charlie=0 posts -> 4 rows total.
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (3, 'charlie', 'hash3', 0)");
			
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c, a)
			");
			
			$this->assertCount(4, $result);
			
			$nullPostRows = array_filter(
				$result->fetchAll(),
				fn(array $row) => $row['a'] === null
			);
			
			$this->assertCount(1, $nullPostRows, 'Exactly one null-post row expected (charlie)');
			
			$nullRow = array_values($nullPostRows)[0];
			$this->assertInstanceOf(UserEntity::class, $nullRow['c']);
			$this->assertSame('charlie', $nullRow['c']->getUsername());
		}
		
		// -------------------------------------------------------------------------
		// Explicit localColumn — exercises the getLocalColumn() !== null branch
		// -------------------------------------------------------------------------
		
		public function testInverseOfViaWithExplicitLocalColumnJoinsCorrectly(): void {
			// PostEntity::$user declares localColumn="userId" explicitly rather than
			// relying on the default convention (relation name + "Id").
			// The result must be identical to the default-convention case: 3 matched rows,
			// each post paired with its owning user, no cross-contamination.
			$result = $this->em->executeQuery("
			range of c is UserEntity
			range of a is PostEntity via c.posts
			retrieve (c.id, a.id)
			");
			
			$this->assertCount(3, $result);
			
			$ownership = [1 => 1, 2 => 1, 3 => 2];
			
			foreach ($result as $row) {
				$expectedOwner = $ownership[$row['a.id']];
				$this->assertSame(
					$expectedOwner,
					$row['c.id'],
					"Post {$row['a.id']} should belong to user $expectedOwner, got {$row['c.id']}"
				);
			}
		}
		
		public function testDirectViaWithExplicitLocalColumnJoinsCorrectly(): void {
			// Forward direction: range of u via p.user, where $user now carries an explicit
			// localColumn="userId". Verifies the direct-ManyToOne code path reads
			// getLocalColumn() correctly instead of falling back to the default.
			$result = $this->em->executeQuery("
			range of p is PostEntity
			range of u is UserEntity via p.user
			retrieve (p.id, u.id)
			sort by p.id asc
			");
			
			$this->assertCount(3, $result);
			
			$ownership = [1 => 1, 2 => 1, 3 => 2];
			
			foreach ($result as $row) {
				$expectedOwner = $ownership[$row['p.id']];
				$this->assertSame(
					$expectedOwner,
					$row['u.id'],
					"Post {$row['p.id']} should belong to user $expectedOwner, got {$row['u.id']}"
				);
			}
		}
	}