<?php

	namespace Quellabs\ObjectQuel\Tests;

	/** Regression test for AstRetrieve::deepClone() silently dropping the inferred GROUP BY clause. */
	class AggregateGroupByDeepCloneTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', 0)");

			$posts = [
				[1, 'p1', 1],
				[2, 'p2', 1],
				[3, 'p3', 1],
				[4, 'p4', 2],
				[5, 'p5', 2],
			];

			foreach ($posts as [$id, $title, $userId]) {
				$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
					VALUES ({$id}, '{$title}', 'content', 1, '2024-01-0{$id} 00:00:00', 'pending', '{}', {$userId})");
			}
		}

		public function testGroupByInferredFromMixedColumnSurvivesExecution(): void {
			// GROUP BY is inferred from u.username; must return one row per user, not one for the whole table.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				range of u is UserEntity via o.user
				retrieve (u.username, total = sum(o.id))
				sort by u.username
			"));

			$this->assertSame(
				[['alice', 6], ['bob', 9]],
				array_map(fn($row) => [$row['u.username'], (int) $row['total']], $result)
			);
		}
	}
