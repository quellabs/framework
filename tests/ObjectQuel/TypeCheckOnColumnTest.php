<?php

	namespace Quellabs\ObjectQuel\Tests;

	/**
	 * is_integer()/is_float()/is_numeric() on `range.column`, which the type resolver now types from the column.
	 */
	class TypeCheckOnColumnTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");

			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (1, '123', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', '{\"id\": 2}', 1)");

			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (2, 'Second Post', 'Foo bar', 0, '2024-01-02 00:00:00', 'shipped', '{\"id\": 2}', 1)");
		}

		public function testIsIntegerOnIntegerColumnIsTrue(): void {
			$this->assertCount(2, $this->em->executeQuery('range of p is PostEntity retrieve (p.id) where is_integer(p.id)'));
		}

		public function testIsFloatOnIntegerColumnIsFalse(): void {
			$this->assertCount(0, $this->em->executeQuery('range of p is PostEntity retrieve (p.id) where is_float(p.id)'));
		}

		public function testIsNumericOnStringColumnChecksEachValue(): void {
			$this->assertCount(1, $this->em->executeQuery('range of p is PostEntity retrieve (p.id) where is_numeric(p.title)'));
		}
	}
