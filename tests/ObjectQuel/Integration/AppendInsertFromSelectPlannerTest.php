<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Integration coverage for `append to <range> (cols) retrieve (...)` whose
	 * source retrieve needs JSON-source or temp-table materialization.
	 *
	 * Previously this was silently wrong: QuelToSQLRetrieve silently drops any
	 * range it doesn't understand (JSON-source ranges, temp-table-promoted
	 * subquery ranges), so the source retrieve's SQL was compiled as if those
	 * ranges weren't there at all. Now the source runs through
	 * ExecutionPlanBuilder/PlanExecutor — the same pipeline a top-level
	 * `retrieve` uses — and the fetched rows are re-inserted as a literal-values
	 * append (see AppendExecutor::executeInsertFromSelectViaPlanner()).
	 *
	 * Fixture: App\Entities\UserEntity ("users": id identity PK, username,
	 * password, banned not-null-no-default), same as AppendTest. JSON fixture
	 * files follow JsonSourceRangeAppendTest's self-contained pattern.
	 */
	class AppendInsertFromSelectPlannerTest extends ObjectQuelTestCase {

		private static int $fileCounter = 0;

		/** @var string[] Fixture files created by the current test, removed in tearDown() */
		private array $createdFiles = [];

		protected function tearDown(): void {
			foreach ($this->createdFiles as $path) {
				@unlink($path);
				@unlink($path . '.lock');
			}

			$this->createdFiles = [];
		}

		private function nextJsonFile(): string {
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aifsp_test_' . getmypid() . '_' . (++self::$fileCounter) . '.json';
			$this->createdFiles[] = $path;
			return $path;
		}

		/**
		 * @param list<array<string, mixed>> $rows
		 */
		private function writeFixture(string $path, array $rows): void {
			file_put_contents($path, json_encode($rows));
		}

		public function testInsertFromSelectFromAJsonSourceRange(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, [
				['username' => 'json-alice', 'password' => 'pw1', 'banned' => false],
				['username' => 'json-bob', 'password' => 'pw2', 'banned' => true],
			]);

			$result = $this->em->executeQuery(
				"range of dst is App\\Entities\\UserEntity
				range of j is json_source('" . addslashes($path) . "')
				append to dst (username, password, banned) retrieve (j.username, j.password, j.banned)"
			);

			$this->assertSame(2, $result->getAffectedRows());
			$this->assertNull($result->getGeneratedId());

			$usernames = $this->em->getCol(
				'range of u is App\Entities\UserEntity retrieve (u.username) sort by u.username asc'
			);
			$this->assertSame(['json-alice', 'json-bob'], $usernames);
		}

		public function testInsertFromSelectFromATempTablePromotedSubqueryRange(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, [
				['username' => 'nested-carol', 'password' => 'pw3', 'banned' => false],
			]);

			$result = $this->em->executeQuery(
				"range of dst is App\\Entities\\UserEntity
				range of t is (
					range of j is json_source('" . addslashes($path) . "')
					retrieve(j.username, j.password, j.banned)
				)
				append to dst (username, password, banned) retrieve (t.username, t.password, t.banned)"
			);

			$this->assertSame(1, $result->getAffectedRows());

			$usernames = $this->em->getCol(
				'range of u is App\Entities\UserEntity retrieve (u.username) where u.username = "nested-carol"'
			);
			$this->assertSame(['nested-carol'], $usernames);
		}

		public function testInsertFromSelectFromAnEmptyJsonSourceIsANoOp(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, []);

			$result = $this->em->executeQuery(
				"range of dst is App\\Entities\\UserEntity
				range of j is json_source('" . addslashes($path) . "')
				append to dst (username, password, banned) retrieve (j.username, j.password, j.banned)"
			);

			$this->assertSame(0, $result->getAffectedRows());
			$this->assertNull($result->getGeneratedId());
		}

		public function testExplainQueryRejectsAPlannerNeedingInsertFromSelect(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, []);

			$this->expectException(QuelException::class);

			$this->em->explainQuery(
				"range of dst is App\\Entities\\UserEntity
				range of j is json_source('" . addslashes($path) . "')
				append to dst (username, password, banned) retrieve (j.username, j.password, j.banned)"
			);
		}

		/**
		 * Regression test mirroring AppendTest::testAppendSucceedsWithDevelopmentModeDebugSignalEnabled():
		 * EntityManager::executeQuery() unconditionally calls explainQuery() in
		 * development mode to build the debug signal's query plan, and already
		 * catches a 'not_plannable' QuelException there by falling back to a
		 * plan with no notes (see EntityManager::executeQuery()). A
		 * planner-needing insert-from-select now throws that same error code
		 * from AppendExecutor::compileSql() — this confirms the real write
		 * still succeeds under development mode instead of that debug-signal
		 * fallback bubbling the exception up and failing the append outright.
		 */
		public function testSucceedsWithDevelopmentModeDebugSignalEnabled(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, [['username' => 'dev-mode-dana', 'password' => 'pw', 'banned' => false]]);

			$configProperty = new \ReflectionProperty($this->em, 'configuration');
			$configProperty->setAccessible(true);
			$configuration = $configProperty->getValue($this->em);
			$configuration->setDevelopmentMode(true);

			try {
				$result = $this->em->executeQuery(
					"range of dst is App\\Entities\\UserEntity
					range of j is json_source('" . addslashes($path) . "')
					append to dst (username, password, banned) retrieve (j.username, j.password, j.banned)"
				);

				$this->assertSame(1, $result->getAffectedRows());

				$usernames = $this->em->getCol(
					'range of u is App\Entities\UserEntity retrieve (u.username) where u.username = "dev-mode-dana"'
				);
				$this->assertSame(['dev-mode-dana'], $usernames);
			} finally {
				$configuration->setDevelopmentMode(false);
			}
		}
	}
