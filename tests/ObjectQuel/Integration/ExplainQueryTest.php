<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for EntityManager::explainQuery() across every
	 * statement type it supports. The retrieve path already goes through
	 * QueryOptimizer/ExecutionPlanBuilder with an active PlanLog (see
	 * QueryExecutor::explain()); this suite is about the seven statement
	 * types that bypass that pipeline entirely — DDL
	 * (create/destroy/index/destroy-index) and the write verbs
	 * (append/replace/delete) — which explainQuery() now compiles to SQL
	 * directly via each Executor's compileSql() instead of running them.
	 *
	 * The property under test throughout: explainQuery() must never touch
	 * the database for these statement types. Each test asserts that
	 * directly (the target table/index/row is unaffected), not just that no
	 * exception was thrown — a dry run that quietly performs the real write
	 * would otherwise pass a naive "it returned a QueryPlan" test.
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

		public function testExplainCreateTableReturnsSqlWithoutCreatingIt(): void {
			$tableName = $this->nextName('explain_ct');

			$plan = self::em()->explainQuery("create {$tableName} (id = integer identity primary key)");

			$this->assertSame([], $plan->getNotes());
			$this->assertCount(1, $plan->getSql());
			$this->assertStringContainsStringIgnoringCase('create table', $plan->getSql()[0]);
			$this->assertStringContainsString($tableName, $plan->getSql()[0]);

			$this->assertNotContains($tableName, self::em()->getConnection()->getTables());
		}

		public function testExplainDestroyReturnsSqlWithoutDroppingTheTable(): void {
			$tableName = $this->nextName('explain_dt');
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer)");

			$plan = self::em()->explainQuery("destroy {$tableName}");

			$this->assertSame([], $plan->getNotes());
			$this->assertNotEmpty($plan->getSql());
			$this->assertStringContainsStringIgnoringCase('drop table', $plan->getSql()[0]);

			$this->assertContains($tableName, self::em()->getConnection()->getTables());
		}

		public function testExplainCreateIndexReturnsSqlWithoutCreatingIt(): void {
			$tableName = $this->nextName('explain_ci');
			$indexName = "{$tableName}_email_idx";
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer identity primary key, email = string(100) not null)");

			$plan = self::em()->explainQuery("index on {$tableName} is {$indexName} (email)");

			$this->assertSame([], $plan->getNotes());
			$this->assertNotEmpty($plan->getSql());
			$this->assertStringContainsStringIgnoringCase('create index', $plan->getSql()[0]);

			$this->assertArrayNotHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testExplainDestroyIndexReturnsSqlWithoutDroppingIt(): void {
			$tableName = $this->nextName('explain_di');
			$indexName = "{$tableName}_email_idx";
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer identity primary key, email = string(100) not null)");
			self::em()->executeQuery("index on {$tableName} is {$indexName} (email)");

			$plan = self::em()->explainQuery("destroy {$indexName} on {$tableName}");

			$this->assertSame([], $plan->getNotes());
			$this->assertNotEmpty($plan->getSql());
			$this->assertStringContainsStringIgnoringCase('drop index', $plan->getSql()[0]);

			$this->assertArrayHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testExplainAppendReturnsSqlWithoutInsertingARow(): void {
			$connection = self::em()->getConnection();
			$connection->execute('DELETE FROM `users`');

			$plan = self::em()->explainQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'explain-user', 'password' => 'secret']
			);

			$this->assertSame([], $plan->getNotes());
			$this->assertCount(1, $plan->getSql());
			$this->assertStringContainsStringIgnoringCase('insert into', $plan->getSql()[0]);

			$count = $connection->execute('SELECT COUNT(*) AS c FROM `users`')->fetchAssoc()['c'];
			$this->assertSame(0, (int)$count);
		}

		public function testExplainReplaceReturnsSqlWithoutModifyingTheRow(): void {
			$em = self::em();
			$connection = $em->getConnection();
			$connection->execute('DELETE FROM `users`');

			$seeded = $em->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'alice', 'password' => 'original']
			);

			$plan = $em->explainQuery(
				'range of u is App\Entities\UserEntity
				replace u (password = :password) where u.id = :id',
				['password' => 'changed', 'id' => $seeded->getGeneratedId()]
			);

			$this->assertSame([], $plan->getNotes());
			$this->assertCount(1, $plan->getSql());
			$this->assertStringContainsStringIgnoringCase('update', $plan->getSql()[0]);

			$row = $connection->execute(
				'SELECT password FROM `users` WHERE id = :id',
				['id' => $seeded->getGeneratedId()]
			)->fetchAssoc();

			$this->assertSame('original', $row['password']);
		}

		public function testExplainDeleteReturnsSqlWithoutRemovingTheRow(): void {
			$em = self::em();
			$connection = $em->getConnection();
			$connection->execute('DELETE FROM `users`');

			$seeded = $em->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'bob', 'password' => 'secret']
			);

			$plan = $em->explainQuery(
				'range of u is App\Entities\UserEntity
				delete u where u.id = :id',
				['id' => $seeded->getGeneratedId()]
			);

			$this->assertSame([], $plan->getNotes());
			$this->assertCount(1, $plan->getSql());
			$this->assertStringContainsStringIgnoringCase('delete', $plan->getSql()[0]);

			$count = $connection->execute(
				'SELECT COUNT(*) AS c FROM `users` WHERE id = :id',
				['id' => $seeded->getGeneratedId()]
			)->fetchAssoc()['c'];

			$this->assertSame(1, (int)$count);
		}

		/**
		 * A JSON-source-range append never produces SQL at all — it writes
		 * straight to the source file (see JsonAppendExecutor) — so there is
		 * nothing for explainQuery() to show. This is the one case where
		 * explainQuery() still rejects the statement outright, rather than
		 * returning an (empty) QueryPlan.
		 */
		public function testExplainRejectsAppendToAJsonSourceRange(): void {
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->nextName('explain_json') . '.json';
			file_put_contents($path, '[]');

			try {
				$this->expectException(QuelException::class);

				self::em()->explainQuery(
					"range of j is json_source('" . addslashes($path) . "')
					append to j (name = :name)",
					['name' => 'Alice']
				);
			} finally {
				@unlink($path);
			}
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
