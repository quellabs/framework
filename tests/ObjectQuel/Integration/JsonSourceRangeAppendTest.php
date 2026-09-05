<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for `append to <range> (...)` targeting a
	 * JSON-source range (see objectquel-json-append-plan.md). Scoped to the
	 * literal-values form only — no insert-from-select, no `or replace`
	 * on-conflict, no JSONPath-narrowed range — each covered here by a
	 * rejection test.
	 *
	 * Self-contained: writes its own fixture file under sys_get_temp_dir()
	 * per test and removes it (and any leftover `.lock`) in tearDown(),
	 * unlike SemanticValidationTest's JSON coverage, which depends on an
	 * ambient, non-git-tracked, machine-local fixture.
	 */
	class JsonSourceRangeAppendTest extends TestCase {

		private static int $fileCounter = 0;

		/** @var string[] Fixture files created by the current test, removed in tearDown() */
		private array $createdFiles = [];

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function tearDown(): void {
			foreach ($this->createdFiles as $path) {
				@unlink($path);
				@unlink($path . '.lock');
			}

			$this->createdFiles = [];
		}

		private function nextJsonFile(): string {
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jsar_test_' . getmypid() . '_' . (++self::$fileCounter) . '.json';
			$this->createdFiles[] = $path;
			return $path;
		}

		/**
		 * @param list<array<string, mixed>> $rows
		 */
		private function writeFixture(string $path, array $rows): void {
			file_put_contents($path, json_encode($rows));
		}

		/**
		 * @return list<array<string, mixed>>
		 */
		private function readFixture(string $path): array {
			return json_decode(file_get_contents($path), true);
		}

		public function testAppendsASingleRowToAJsonFile(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, [['name' => 'Alice', 'email' => 'alice@example.com']]);

			$result = self::em()->executeStatement(
				"range of j is json_source('" . addslashes($path) . "')
				append to j (name = :name, email = :email)",
				['name' => 'Bob', 'email' => 'bob@example.com']
			);

			$this->assertSame(1, $result->getAffectedRows());
			$this->assertNull($result->getGeneratedId());

			$rows = $this->readFixture($path);
			$this->assertCount(2, $rows);
			$this->assertSame('Alice', $rows[0]['name']);
			$this->assertSame('Bob', $rows[1]['name']);
			$this->assertSame('bob@example.com', $rows[1]['email']);
		}

		public function testAppendsMultipleRowsToAJsonFile(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, []);

			$result = self::em()->executeStatement(
				"range of j is json_source('" . addslashes($path) . "')
				append to j (name = \"Alice\"), (name = \"Bob\")"
			);

			$this->assertSame(2, $result->getAffectedRows());

			$rows = $this->readFixture($path);
			$this->assertCount(2, $rows);
			$this->assertSame(['Alice', 'Bob'], array_column($rows, 'name'));
		}

		public function testAppendEvaluatesExpressionValues(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, []);

			self::em()->executeStatement(
				"range of j is json_source('" . addslashes($path) . "')
				append to j (name = concat(\"Hello, \", :who), score = 1 + 2)",
				['who' => 'World']
			);

			$rows = $this->readFixture($path);
			$this->assertCount(1, $rows);
			$this->assertSame('Hello, World', $rows[0]['name']);
			$this->assertSame(3, $rows[0]['score']);
		}

		public function testRejectsInsertFromSelectTargetingJsonRange(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, []);

			$this->expectException(QuelException::class);

			self::em()->executeStatement(
				'range of u is App\Entities\UserEntity
				range of j is json_source(\'' . addslashes($path) . '\')
				append to j (name) retrieve (u.username) where u.id = :id',
				['id' => 1]
			);
		}

		public function testRejectsOnConflictTargetingJsonRange(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, []);

			$this->expectException(QuelException::class);

			self::em()->executeStatement(
				"range of j is json_source('" . addslashes($path) . "')
				append to j (name = :name) or replace where j.name = :name",
				['name' => 'Alice']
			);
		}

		public function testRejectsJsonPathNarrowedRangeTarget(): void {
			// The rejection happens purely from the range declaration's
			// JSONPath expression, before the file is ever read — a path
			// that doesn't exist is fine here.
			$this->expectException(QuelException::class);

			self::em()->executeStatement(
				"range of j is json_source('does-not-exist.json', '\$.rows')
				append to j (name = :name)",
				['name' => 'Alice']
			);
		}

		public function testRejectsAppendToMissingJsonFile(): void {
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jsar_test_missing_' . getmypid() . '_' . (++self::$fileCounter) . '.json';

			$this->expectException(QuelException::class);

			self::em()->executeStatement(
				"range of j is json_source('" . addslashes($path) . "')
				append to j (name = :name)",
				['name' => 'Alice']
			);
		}

		/**
		 * Pins down that rename()'s atomic-replace step actually overwrites
		 * an existing target on this platform (Windows), rather than trusting
		 * that by inspection — if rename() ever returned false here instead
		 * of replacing the file, JsonAppendExecutor would throw and this test
		 * would fail loudly instead of silently leaving stale content behind.
		 */
		public function testRenameActuallyReplacesExistingFileContent(): void {
			$path = $this->nextJsonFile();
			$this->writeFixture($path, [['name' => 'Original']]);

			self::em()->executeStatement(
				"range of j is json_source('" . addslashes($path) . "')
				append to j (name = :name)",
				['name' => 'Replacement']
			);

			$rows = $this->readFixture($path);
			$this->assertCount(2, $rows);
			$this->assertSame('Original', $rows[0]['name']);
			$this->assertSame('Replacement', $rows[1]['name']);
		}
	}
