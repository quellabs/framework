<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for EntityManager::explainQuery(): a retrieve
	 * statement returns real planning notes and SQL without executing it (see
	 * QueryExecutor::explain()); every other statement type — DDL
	 * (create/destroy/index/destroy-index) and the write verbs
	 * (append/replace/delete) — is rejected outright rather than explained,
	 * since there is no optimizer/planner pipeline to report on for them and
	 * compiling their SQL would either misrepresent a generated value (an
	 * append's non-identity primary key, a replace's uuid/guid @Orm\Version
	 * bump) or require running the write to know it (an insert-from-select's
	 * row count).
	 */
	class ExplainQueryTest extends TestCase {

		private static int $counter = 0;

		/** @var string[] Tables created by the current test, dropped in tearDown() */
		private array $createdTables = [];

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function tearDown(): void {
			$connection = self::em()->getConnection();

			foreach ($this->createdTables as $tableName) {
				$connection->execute("DROP TABLE IF EXISTS `{$tableName}`");
			}

			$this->createdTables = [];
		}

		private function nextName(string $prefix): string {
			return $prefix . '_' . getmypid() . '_' . (++self::$counter);
		}

		private function assertExplainRejectsAsNotPlannable(string $query, array $parameters = []): void {
			try {
				self::em()->explainQuery($query, $parameters);
				$this->fail('Expected a QuelException');
			} catch (QuelException $e) {
				$this->assertSame('not_plannable', $e->type);
			}
		}

		public function testExplainRejectsCreateTable(): void {
			$tableName = $this->nextName('explain_ct');
			$this->assertExplainRejectsAsNotPlannable("create {$tableName} (id = integer identity, primary key (id))");
			$this->assertNotContains($tableName, self::em()->getConnection()->getTables());
		}

		public function testExplainRejectsDestroy(): void {
			$tableName = $this->nextName('explain_dt');
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer)");

			$this->assertExplainRejectsAsNotPlannable("destroy {$tableName}");
			$this->assertContains($tableName, self::em()->getConnection()->getTables());
		}

		public function testExplainRejectsCreateIndex(): void {
			$tableName = $this->nextName('explain_ci');
			$indexName = "{$tableName}_email_idx";
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer identity, email = string(100) not null, primary key (id))");

			$this->assertExplainRejectsAsNotPlannable("index on {$tableName} is {$indexName} (email)");
			$this->assertArrayNotHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testExplainRejectsDestroyIndex(): void {
			$tableName = $this->nextName('explain_di');
			$indexName = "{$tableName}_email_idx";
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer identity, email = string(100) not null, primary key (id))");
			self::em()->executeQuery("index on {$tableName} is {$indexName} (email)");

			$this->assertExplainRejectsAsNotPlannable("destroy {$indexName} on {$tableName}");
			$this->assertArrayHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testExplainRejectsAppend(): void {
			$connection = self::em()->getConnection();
			$connection->execute('DELETE FROM `users`');

			$this->assertExplainRejectsAsNotPlannable(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'explain-user', 'password' => 'secret']
			);

			$count = $connection->execute('SELECT COUNT(*) AS c FROM `users`')->fetchAssoc()['c'];
			$this->assertSame(0, (int)$count);
		}

		public function testExplainRejectsReplace(): void {
			$em = self::em();
			$connection = $em->getConnection();
			$connection->execute('DELETE FROM `users`');

			$seeded = $em->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'alice', 'password' => 'original']
			);

			$this->assertExplainRejectsAsNotPlannable(
				'range of u is App\Entities\UserEntity
				replace u (password = :password) where u.id = :id',
				['password' => 'changed', 'id' => $seeded->getGeneratedId()]
			);

			$row = $connection->execute(
				'SELECT password FROM `users` WHERE id = :id',
				['id' => $seeded->getGeneratedId()]
			)->fetchAssoc();

			$this->assertSame('original', $row['password']);
		}

		public function testExplainRejectsDelete(): void {
			$em = self::em();
			$connection = $em->getConnection();
			$connection->execute('DELETE FROM `users`');

			$seeded = $em->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'bob', 'password' => 'secret']
			);

			$this->assertExplainRejectsAsNotPlannable(
				'range of u is App\Entities\UserEntity
				delete u where u.id = :id',
				['id' => $seeded->getGeneratedId()]
			);

			$count = $connection->execute(
				'SELECT COUNT(*) AS c FROM `users` WHERE id = :id',
				['id' => $seeded->getGeneratedId()]
			)->fetchAssoc()['c'];

			$this->assertSame(1, (int)$count);
		}

		public function testExplainRetrieveStillReportsPlanningNotesAndSql(): void {
			$plan = self::em()->explainQuery('
				range of u is App\Entities\UserEntity
				retrieve (u.username) where u.banned = false
			');

			$this->assertNotEmpty($plan->getSql());
			$this->assertStringContainsStringIgnoringCase('select', $plan->getSql()[0]);
		}
	}
