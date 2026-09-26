<?php

	namespace Quellabs\ObjectQuel\Tests;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDelete;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * is_empty() on boolean columns and predicates, which take comparisons and AND/OR without extra parentheses.
	 */
	class IsEmptyBooleanTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");

			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (1, 'First Post', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', '{\"id\": 2}', 1)");

			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (2, 'Second Post', 'Foo bar', 0, '2024-01-02 00:00:00', 'shipped', '{\"id\": 2}', 1)");
		}

		/**
		 * @param string $condition QUEL condition over range `p`
		 * @return int Number of posts matching it
		 */
		private function countPosts(string $condition): int {
			return count($this->em->executeQuery("range of p is PostEntity retrieve (p.id) where {$condition}"));
		}

		public function testBooleanColumn(): void {
			$this->assertSame(1, $this->countPosts('is_empty(p.published)'));
			$this->assertSame(1, $this->countPosts('not is_empty(p.published)'));
		}

		public function testComparison(): void {
			$this->assertSame(0, $this->countPosts('is_empty(p.id > 0)'));
			$this->assertSame(1, $this->countPosts('is_empty(p.id > 1)'));
			$this->assertSame(1, $this->countPosts('is_empty(p.createdAt > "2024-01-01 12:00:00")'));
		}

		public function testLogicalExpression(): void {
			$this->assertSame(1, $this->countPosts('is_empty(p.id > 1 or p.published = false)'));
			$this->assertSame(1, $this->countPosts('not is_empty(p.id > 0 and p.published = false)'));
		}

		/**
		 * PostgreSQL rejects a boolean compared with '' or 0, and a chained comparison.
		 * @return void
		 */
		public function testPostgresComparesWithFalse(): void {
			$ast = (new Parser(new Lexer('range of p is PostEntity delete p where is_empty(p.id > 3) and is_empty(p.published)'), $this->em->getEntityStore()))->parse();
			$this->assertInstanceOf(AstDelete::class, $ast);

			$parameters = [];
			$sql = (new QuelToSQLDelete($this->em->getEntityStore(), new FakePlatformCapabilities('pgsql')))->convertToSQL($ast, $parameters);

			$this->assertStringContainsString('(("p"."id" > 3) IS NULL OR ("p"."id" > 3) = false)', $sql);
			$this->assertStringContainsString('("p"."published" IS NULL OR "p"."published" = false)', $sql);
		}
	}
