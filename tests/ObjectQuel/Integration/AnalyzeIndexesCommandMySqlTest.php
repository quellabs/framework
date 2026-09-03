<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Sculpt\Commands\AnalyzeIndexesCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * AnalyzeIndexesCommand against a real MySQL server, covering the command
	 * end-to-end: redundancy detection plus the usage-stats column it now gets
	 * from DatabaseAdapter::getIndexUsageStatistics() rather than its own
	 * per-engine query methods (see the AnalyzeIndexesCommand/DatabaseAdapter
	 * usage-stats refactor). This command previously had no test coverage at
	 * all.
	 *
	 * Uses the same TEST_DB_* environment variables as the framework root's
	 * existing MySQL-backed suite. Only creates/drops its own uniquely
	 * prefixed table. Skips entirely (does not fail) when no MySQL server is
	 * reachable.
	 */
	class AnalyzeIndexesCommandMySqlTest extends TestCase {

		private ?ServiceProvider $provider = null;

		protected function setUp(): void {
			$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
			$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
			$name = getenv('TEST_DB_NAME') ?: 'canvas_blog';
			$user = getenv('TEST_DB_USER') ?: 'root';
			$pass = getenv('TEST_DB_PASS') ?: '';

			$provider = new ServiceProvider();
			$provider->setConfig([
				'driver'           => 'mysql',
				'host'             => $host,
				'port'             => $port,
				'database'         => $name,
				'username'         => $user,
				'password'         => $pass,
				'encoding'         => 'utf8mb4',
				'entity_namespace' => 'App\\Entities',
				'entity_path'      => sys_get_temp_dir(),
			]);

			try {
				$provider->getDatabaseAdapter()->getTables();
			} catch (\Throwable $e) {
				self::markTestSkipped('No live MySQL server reachable for this test: ' . $e->getMessage());
			}

			$this->provider = $provider;
			$this->dropFixtureTable();
		}

		protected function tearDown(): void {
			if ($this->provider !== null) {
				$this->dropFixtureTable();
			}
		}

		private function dropFixtureTable(): void {
			$this->provider->getDatabaseAdapter()->execute('DROP TABLE IF EXISTS oq_analyze_idx_test');
		}

		private function runCommand(): string {
			$output = new ConsoleOutput($stream = fopen('php://memory', 'w+'));
			$input = new ConsoleInput($output, fopen('php://memory', 'r+'));
			$command = new AnalyzeIndexesCommand($input, $output, $this->provider);

			$exitCode = $command->execute(new ConfigurationManager());
			self::assertSame(0, $exitCode);

			rewind($stream);
			return stream_get_contents($stream);
		}

		public function testExactDuplicateIndexIsFlaggedAsRedundant(): void {
			$this->provider->getDatabaseAdapter()->execute(
				'CREATE TABLE oq_analyze_idx_test (' .
				'id INT PRIMARY KEY, ' .
				'email VARCHAR(100), ' .
				'INDEX idx_email (email), ' .
				'INDEX idx_email_dup (email)' .
				') ENGINE=InnoDB'
			);

			$out = $this->runCommand();

			self::assertStringContainsString('idx_email_dup', $out);
			self::assertStringContainsString('Duplicate of idx_email', $out);
		}

		public function testPrefixRedundantIndexIsFlaggedWithTheCoveringIndex(): void {
			$this->provider->getDatabaseAdapter()->execute(
				'CREATE TABLE oq_analyze_idx_test (' .
				'id INT PRIMARY KEY, ' .
				'a INT, b INT, ' .
				'INDEX idx_a (a), ' .
				'INDEX idx_ab (a, b)' .
				') ENGINE=InnoDB'
			);

			$out = $this->runCommand();

			self::assertStringContainsString('Prefix of idx_ab', $out);
		}

		public function testNonRedundantIndexIsReportedOk(): void {
			$this->provider->getDatabaseAdapter()->execute(
				'CREATE TABLE oq_analyze_idx_test (id INT PRIMARY KEY, name VARCHAR(50), INDEX idx_name (name)) ENGINE=InnoDB'
			);

			$out = $this->runCommand();

			self::assertMatchesRegularExpression('/idx_name\s*\|\s*index\s*\|\s*name\s*\|\s*ok/', $out);
		}

		/**
		 * The usage-stats columns come from DatabaseAdapter::getIndexUsageStatistics()
		 * now, not a command-local performance_schema query — this proves the wiring
		 * survived the move by asserting the Reads column is actually rendered.
		 */
		public function testUsageStatisticsColumnsAreRenderedOnMysql(): void {
			$this->provider->getDatabaseAdapter()->execute(
				'CREATE TABLE oq_analyze_idx_test (id INT PRIMARY KEY, name VARCHAR(50), INDEX idx_name (name)) ENGINE=InnoDB'
			);

			$out = $this->runCommand();

			self::assertStringContainsString('Reads', $out);
			self::assertStringContainsString('Writes', $out);
			self::assertStringNotContainsString('performance_schema is enabled', $out);
		}

		public function testPrimaryKeyIndexIsNeverFlaggedAsRedundant(): void {
			$this->provider->getDatabaseAdapter()->execute(
				'CREATE TABLE oq_analyze_idx_test (id INT PRIMARY KEY, name VARCHAR(50), UNIQUE KEY idx_id_dup (id)) ENGINE=InnoDB'
			);

			$out = $this->runCommand();

			self::assertDoesNotMatchRegularExpression('/PRIMARY\s*\|\s*primary\s*\|\s*id\s*\|\s*Duplicate/', $out);
		}
	}
