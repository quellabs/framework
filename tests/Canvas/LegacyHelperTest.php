<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../packages/canvas/src/Legacy/Helpers/LegacyHelper.php';

	/**
	 * CanvasPDO/CanvasPDOStatement/CanvasMysqliStatement are real PDO/PDOStatement/
	 * mysqli_stmt subclasses (not composition wrappers), so legacy code that does
	 * `instanceof` checks or type-hints against the native classes keeps working
	 * once canvas_create_pdo()/canvas_mysqli_prepare() replace the originals.
	 */
	class LegacyHelperTest extends TestCase {

		/**
		 * canvas_create_pdo() returns a real PDO, and statements from both
		 * prepare() and query() are real PDOStatement instances that execute correctly.
		 * @return void
		 */
		public function testCanvasPdoIsRealPdoAndStatementsAreRealPdoStatements(): void {
			$pdo = canvas_create_pdo('sqlite::memory:');
			$this->assertInstanceOf(\PDO::class, $pdo);

			$pdo->exec('CREATE TABLE t (id INTEGER, label TEXT)');
			$pdo->exec("INSERT INTO t VALUES (1, 'a')");

			$prepared = $pdo->prepare('SELECT label FROM t WHERE id = ?');
			$this->assertInstanceOf(\PDOStatement::class, $prepared);
			$prepared->execute([1]);
			$this->assertSame('a', $prepared->fetchColumn());

			$queried = $pdo->query('SELECT label FROM t WHERE id = 1');
			$this->assertInstanceOf(\PDOStatement::class, $queried);
			$this->assertSame('a', $queried->fetchColumn());
		}

		/**
		 * A legacy function type-hinted against the native PDO/PDOStatement classes
		 * must accept the monitored instances without a TypeError.
		 * @return void
		 */
		public function testMonitoredInstancesSatisfyNativeTypeHints(): void {
			$acceptsPdo = function (\PDO $pdo): string {
				return get_class($pdo);
			};

			$acceptsStatement = function (\PDOStatement $statement): string {
				return get_class($statement);
			};

			$pdo = canvas_create_pdo('sqlite::memory:');
			$pdo->exec('CREATE TABLE t (id INTEGER)');

			$this->assertSame('CanvasPDO', $acceptsPdo($pdo));
			$this->assertSame('CanvasPDOStatement', $acceptsStatement($pdo->prepare('SELECT * FROM t')));
		}

		/**
		 * PDO::ATTR_STATEMENT_CLASS rejects persistent connections outright;
		 * canvas_create_pdo() must still succeed, just without statement monitoring.
		 * @return void
		 */
		public function testPersistentConnectionDoesNotThrow(): void {
			$pdo = canvas_create_pdo('sqlite::memory:', null, null, [\PDO::ATTR_PERSISTENT => true]);
			$this->assertInstanceOf(\PDO::class, $pdo);

			$pdo->exec('CREATE TABLE t (id INTEGER)');
			$pdo->exec('INSERT INTO t VALUES (1)');
			$this->assertSame('1', (string)$pdo->query('SELECT id FROM t')->fetchColumn());
		}

		/**
		 * canvas_mysqli_prepare() returns a real mysqli_stmt and keeps
		 * mysqli_prepare()'s false-on-failure contract for a bad query.
		 * @return void
		 */
		public function testCanvasMysqliPrepareIsRealMysqliStmtAndPreservesFalseOnFailure(): void {
			try {
				$connection = new \mysqli(
					getenv('TEST_DB_HOST') ?: '127.0.0.1',
					getenv('TEST_DB_USER') ?: 'root',
					getenv('TEST_DB_PASS') ?: '',
					getenv('TEST_DB_NAME') ?: 'canvas_blog',
					(int)(getenv('TEST_DB_PORT') ?: 3306)
				);
			} catch (\Throwable $e) {
				self::markTestSkipped('No live MySQL server reachable for this test: ' . $e->getMessage());
			}

			mysqli_report(MYSQLI_REPORT_OFF);

			$stmt = canvas_mysqli_prepare($connection, 'SELECT 1');
			$this->assertInstanceOf(\mysqli_stmt::class, $stmt);
			$this->assertTrue($stmt->execute());

			$this->assertFalse(canvas_mysqli_prepare($connection, 'SELEKT GARBAGE FROM nowhere'));

			$connection->close();
		}
	}
