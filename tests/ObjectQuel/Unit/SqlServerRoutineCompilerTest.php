<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * T-SQL lowering of routines (objectquel-equel-design.md, stage 4).
	 * The expected SQL is not run against SQL Server here.
	 */
	class SqlServerRoutineCompilerTest extends TestCase {

		/**
		 * @param string $source Routine source
		 * @return string Generated CREATE OR ALTER statement
		 */
		private function compile(string $source): string {
			$statements = (new ProcedureCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('sqlsrv'), 'dbo'))->compile($source);
			self::assertCount(1, $statements);
			return $statements[0];
		}

		/**
		 * A read-only loop fetches into typed variables from a STATIC cursor; booleans are BIT values.
		 * @return void
		 */
		public function testReadOnlyFunction(): void {
			$sql = $this->compile('
				define function count_users (int minId) integer {
					integer total = 0
					boolean found = false
					range of u is UserEntity
					cursor users = retrieve (u.id, name = u.username, flag = u.banned) where u.id > minId
					foreach users {
						if (users.name = "x" and users.flag) {
							total = total + users.id
							found = total > 3
						} else {
						}
					}
					while (found) {
						found = false
					}
					return total
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR ALTER FUNCTION [dbo].[count_users](@minId INT)
				RETURNS INT
				AS
				BEGIN
					DECLARE @total INT;
					DECLARE @found BIT;
					DECLARE @_row_users$id INT;
					DECLARE @_row_users$name VARCHAR(255);
					DECLARE @_row_users$flag BIT;
					DECLARE @_noop BIT;
					SET @total = 0;
					SET @found = 0;
					DECLARE _cur_users CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id],[u].[username] as [name],[u].[banned] as [flag] FROM [users] as [u] WHERE [u].[id] > @minId;
					OPEN _cur_users;
					WHILE 1 = 1
					BEGIN
						FETCH NEXT FROM _cur_users INTO @_row_users$id, @_row_users$name, @_row_users$flag;
						IF @@FETCH_STATUS <> 0 BREAK;
						IF @_row_users$name = 'x' AND @_row_users$flag = 1
						BEGIN
							SET @total = @total + @_row_users$id;
							SET @found = CASE WHEN @total > 3 THEN 1 WHEN NOT (@total > 3) THEN 0 END;
						END
						ELSE
						BEGIN
							SET @_noop = 0;
						END;
					END;
					CLOSE _cur_users;
					DEALLOCATE _cur_users;
					WHILE @found = 1
					BEGIN
						SET @found = 0;
					END;
					RETURN @total;
				END;
				SQL, $sql);
		}

		/**
		 * Current-row writes use a SCROLL_LOCKS FOR UPDATE cursor and unaliased WHERE CURRENT OF statements;
		 * other writes declare their alias in FROM.
		 * @return void
		 */
		public function testProcedureStatements(): void {
			$sql = $this->compile('
				define function purge (string who) void {
					range of u is UserEntity
					range of p is PostEntity
					cursor users = retrieve (u.username) where u.username = who
					foreach users {
						delete p where p.userId = 5
						replace users (banned = true)
						delete users
					}
					begin transaction {
						replace u (banned = false) where u.username = who
						if (who = "") {
							abort
						}
					}
					retrieve (p.title) where p.userId = 5
					append to u (username = who, password = "x", banned = false)
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR ALTER PROCEDURE [dbo].[purge] @who VARCHAR(255)
				AS
				BEGIN
					DECLARE @_row_users$username VARCHAR(255);
					DECLARE @_discard INT;
					DECLARE _cur_users CURSOR LOCAL FORWARD_ONLY DYNAMIC SCROLL_LOCKS FOR SELECT [u].[username] as [username] FROM [users] as [u] WHERE [u].[username] = @who FOR UPDATE;
					OPEN _cur_users;
					WHILE 1 = 1
					BEGIN
						FETCH NEXT FROM _cur_users INTO @_row_users$username;
						IF @@FETCH_STATUS <> 0 BREAK;
						UPDATE [p] SET [p].[deleted_at] = SYSDATETIME() FROM [posts] as [p] WHERE [p].[user_id] = 5;
						UPDATE [users] SET [banned] = 1 WHERE CURRENT OF _cur_users;
						DELETE FROM [users] WHERE CURRENT OF _cur_users;
					END;
					CLOSE _cur_users;
					DEALLOCATE _cur_users;
					BEGIN TRANSACTION;
					UPDATE [u] SET [u].[banned] = 0 FROM [users] as [u] WHERE [u].[username] = @who;
					IF @who = ''
					BEGIN
						ROLLBACK TRANSACTION;
					END;
					IF @@TRANCOUNT > 0 COMMIT TRANSACTION;
					SELECT @_discard = COUNT(*) FROM (SELECT [p].[title] as [title] FROM [posts] as [p] WHERE [p].[user_id] = 5 AND [p].[deleted_at] IS NULL) AS [_discard];
					INSERT INTO [users] ([username], [password], [banned]) VALUES (@who, 'x', 0);
				END;
				SQL, $sql);
		}

		/**
		 * A function body must end in RETURN even when every path already returns.
		 * @return void
		 */
		public function testFunctionEndsInReturn(): void {
			$sql = $this->compile('
				define function f (int a) integer {
					if (a > 1) {
						return 1
					} else {
						return 2
					}
				}
			');

			self::assertStringEndsWith("\tEND;\n\tRETURN NULL;\nEND;", $sql);
		}

		/**
		 * A return inside a loop releases the loop's cursor first.
		 * @return void
		 */
		public function testReturnInsideLoopReleasesCursor(): void {
			$sql = $this->compile('
				define function first_id () integer {
					range of u is UserEntity
					cursor users = retrieve (u.id)
					foreach users {
						return users.id
					}
					return 0
				}
			');

			self::assertStringContainsString("\t\tCLOSE _cur_users;\n\t\tDEALLOCATE _cur_users;\n\t\tRETURN @_row_users\$id;\n", $sql);
		}

		/**
		 * Sort terms compile to ORDER BY, before FOR UPDATE on a cursor that takes current-row writes.
		 * @return void
		 */
		public function testSortByCompilesToOrderBy(): void {
			$sql = $this->compile('
				define function f () void {
					range of u is UserEntity
					cursor readers = retrieve (u.id) sort by u.username
					cursor writers = retrieve (u.id) where u.banned = true sort by u.id desc
					foreach readers {
					}
					foreach writers {
						replace writers (banned = false)
					}
				}
			');

			self::assertStringContainsString('DECLARE _cur_readers CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id] FROM [users] as [u] ORDER BY [u].[username];', $sql);
			self::assertStringContainsString('DECLARE _cur_writers CURSOR LOCAL FORWARD_ONLY DYNAMIC SCROLL_LOCKS FOR SELECT [u].[id] as [id] FROM [users] as [u] WHERE [u].[banned] = 1 ORDER BY [u].[id] desc FOR UPDATE;', $sql);
		}

		/**
		 * ++, --, += and -= compile as the assignments they stand for; a subtracted expression is parenthesized.
		 * @return void
		 */
		public function testIncrementAndCompoundAssignment(): void {
			$sql = $this->compile('
				define function counts (integer n) integer {
					integer total = 0
					total++
					total--
					total += n * 2
					total -= n - 1
					return total
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR ALTER FUNCTION [dbo].[counts](@n INT)
				RETURNS INT
				AS
				BEGIN
					DECLARE @total INT;
					SET @total = 0;
					SET @total = @total + 1;
					SET @total = @total - 1;
					SET @total = @total + @n * 2;
					SET @total = @total - (@n - 1);
					RETURN @total;
				END;
				SQL, $sql);
		}

		/**
		 * break/continue become BREAK/CONTINUE in a while and in read-only and current-row-writing cursor loops.
		 * @return void
		 */
		public function testBreakAndContinue(): void {
			$sql = $this->compile('
				define function skip_some (integer n) void {
					range of u is UserEntity
					cursor ids = retrieve (u.id) where u.id > 0
					cursor banned = retrieve (u.id) where u.banned = true
					while (n > 0) {
						n = n - 1
						if (n = 5) {
							continue
						}
						if (n = 2) {
							break
						}
					}
					foreach ids {
						if (ids.id = n) {
							continue
						}
						break
					}
					foreach banned {
						if (banned.id = n) {
							continue
						}
						replace banned (banned = false)
						break
					}
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR ALTER PROCEDURE [dbo].[skip_some] @n INT
				AS
				BEGIN
					DECLARE @_row_ids$id INT;
					DECLARE @_row_banned$id INT;
					WHILE @n > 0
					BEGIN
						SET @n = @n - 1;
						IF @n = 5
						BEGIN
							CONTINUE;
						END;
						IF @n = 2
						BEGIN
							BREAK;
						END;
					END;
					DECLARE _cur_ids CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id] FROM [users] as [u] WHERE [u].[id] > 0;
					OPEN _cur_ids;
					WHILE 1 = 1
					BEGIN
						FETCH NEXT FROM _cur_ids INTO @_row_ids$id;
						IF @@FETCH_STATUS <> 0 BREAK;
						IF @_row_ids$id = @n
						BEGIN
							CONTINUE;
						END;
						BREAK;
					END;
					CLOSE _cur_ids;
					DEALLOCATE _cur_ids;
					DECLARE _cur_banned CURSOR LOCAL FORWARD_ONLY DYNAMIC SCROLL_LOCKS FOR SELECT [u].[id] as [id] FROM [users] as [u] WHERE [u].[banned] = 1 FOR UPDATE;
					OPEN _cur_banned;
					WHILE 1 = 1
					BEGIN
						FETCH NEXT FROM _cur_banned INTO @_row_banned$id;
						IF @@FETCH_STATUS <> 0 BREAK;
						IF @_row_banned$id = @n
						BEGIN
							CONTINUE;
						END;
						UPDATE [users] SET [banned] = 0 WHERE CURRENT OF _cur_banned;
						BREAK;
					END;
					CLOSE _cur_banned;
					DEALLOCATE _cur_banned;
				END;
				SQL, $sql);
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function rejectedRoutines(): array {
			return [
				'function that writes' => ['
					define function f () integer {
						range of u is UserEntity
						delete u where u.id = 1
						return 1
					}
				', "SQL Server creates it as a FUNCTION, which can't write tables"],

				'transaction inside a loop' => ['
					define function f () void {
						range of u is UserEntity
						cursor users = retrieve (u.id)
						foreach users {
							begin transaction {
								delete u where u.id = users.id
							}
						}
					}
				', "the loop's cursor may not survive the COMMIT"],

				'field of unknown type' => ['
					define function f () integer {
						range of u is UserEntity
						cursor c = retrieve (x = ifnull(u.username, "a"))
						return 1
					}
				', "The type of 'c.x' can't be determined"],
			];
		}

		/**
		 * @param string $source Routine source
		 * @param string $message Expected error message fragment
		 * @return void
		 */
		#[DataProvider('rejectedRoutines')]
		public function testRejects(string $source, string $message): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage($message);
			$this->compile($source);
		}
	}
