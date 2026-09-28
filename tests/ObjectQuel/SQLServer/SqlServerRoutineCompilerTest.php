<?php

	namespace Quellabs\ObjectQuel\Tests\SQLServer;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * T-SQL lowering of routines (objectquel-equel-design.md, stage 4).
	 * The expected SQL is not run against SQL Server here.
	 */
	class SqlServerRoutineCompilerTest extends TestCase {

		/**
		 * @param string $source Routine source
		 * @return string Generated CREATE statement
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
					foreach (users as row) {
						if (row.name = "x" and row.flag) {
							total = total + row.id
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
				CREATE FUNCTION [dbo].[count_users](@minId INT)
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
		 * Every cursor is a STATIC READ_ONLY loop; a write inside it is an ordinary
		 * replace/delete declaring its own alias in FROM, referencing the loop's row binding.
		 * @return void
		 */
		public function testProcedureStatements(): void {
			$sql = $this->compile('
				define function purge (string who) void {
					range of u is UserEntity
					range of p is PostEntity
					cursor users = retrieve (u.id, u.username) where u.username = who
					foreach (users as row) {
						delete p where p.userId = 5
						replace u (banned = true) where u.id = row.id
						delete u where u.id = row.id
					}
					transaction {
						replace u (banned = false) where u.username = who
						if (who = "") {
							exit
						}
					}
					retrieve (p.title) where p.userId = 5
					append to u (username = who, password = "x", banned = false)
				}
			');

			self::assertSame(<<<'SQL'
				CREATE PROCEDURE [dbo].[purge] @who VARCHAR(255)
				AS
				BEGIN
					DECLARE @_row_users$id INT;
					DECLARE @_row_users$username VARCHAR(255);
					DECLARE @_equel_owns_1 BIT;
					DECLARE @_equel_savepoint_1 VARCHAR(32);
					DECLARE _cur_users CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id],[u].[username] as [username] FROM [users] as [u] WHERE [u].[username] = @who;
					OPEN _cur_users;
					WHILE 1 = 1
					BEGIN
						FETCH NEXT FROM _cur_users INTO @_row_users$id, @_row_users$username;
						IF @@FETCH_STATUS <> 0 BREAK;
						UPDATE [p] SET [p].[deleted_at] = SYSDATETIME() FROM [posts] as [p] WHERE [p].[user_id] = 5;
						UPDATE [u] SET [u].[banned] = 1 FROM [users] as [u] WHERE [u].[id] = @_row_users$id;
						DELETE [u] FROM [users] as [u] WHERE [u].[id] = @_row_users$id;
					END;
					CLOSE _cur_users;
					DEALLOCATE _cur_users;
					SET @_equel_owns_1 = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
					IF @_equel_owns_1 = 1 BEGIN TRANSACTION;
					IF @_equel_owns_1 = 0 BEGIN
						SET @_equel_savepoint_1 = REPLACE(CONVERT(VARCHAR(36), NEWID()), '-', '');
						SAVE TRANSACTION @_equel_savepoint_1;
					END;
					BEGIN TRY
						UPDATE [u] SET [u].[banned] = 0 FROM [users] as [u] WHERE [u].[username] = @who;
						IF @who = ''
						BEGIN
							IF @_equel_owns_1 = 1 BEGIN ROLLBACK TRANSACTION; END ELSE BEGIN ROLLBACK TRANSACTION @_equel_savepoint_1; END;
						END;
						IF @_equel_owns_1 = 1 AND @@TRANCOUNT > 0 COMMIT TRANSACTION;
					END TRY
					BEGIN CATCH
						IF @_equel_owns_1 = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
						ELSE IF @_equel_owns_1 = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION @_equel_savepoint_1;
						THROW;
					END CATCH;
					DECLARE _discard_1 CURSOR LOCAL FAST_FORWARD FOR SELECT [p].[title] as [title] FROM [posts] as [p] WHERE [p].[user_id] = 5 AND [p].[deleted_at] IS NULL; OPEN _discard_1; FETCH NEXT FROM _discard_1; WHILE @@FETCH_STATUS = 0 FETCH NEXT FROM _discard_1; CLOSE _discard_1; DEALLOCATE _discard_1;
					INSERT INTO [users] ([username], [password], [banned]) VALUES (@who, 'x', 0);
				END;
				SQL, $sql);
		}

		/**
		 * Standalone retrieves run as complete SQL Server queries, including sorting, aggregation and distinctness.
		 * @return void
		 */
		public function testStandaloneRetrievesConsumeRowsWithoutDerivedTables(): void {
			$sql = $this->compile('
				define function inspect () void {
					range of u is UserEntity
					retrieve (result = inspect_value(u.id)) sort by u.id desc
					retrieve (u.username)
					retrieve (total = count(u.id))
					retrieve unique (u.username) sort by u.username
				}
			');

			self::assertStringContainsString('CURSOR LOCAL FAST_FORWARD FOR SELECT [dbo].[inspect_value]([u].[id]) as [result] FROM [users] as [u] ORDER BY [u].[id] desc;', $sql);
			self::assertStringContainsString('CURSOR LOCAL FAST_FORWARD FOR SELECT [u].[username] as [username] FROM [users] as [u];', $sql);
			self::assertStringContainsString('CURSOR LOCAL FAST_FORWARD FOR SELECT COUNT([u].[id]) as [total] FROM [users] as [u];', $sql);
			self::assertStringContainsString('CURSOR LOCAL FAST_FORWARD FOR SELECT DISTINCT [u].[username] as [username] FROM [users] as [u] ORDER BY [u].[username];', $sql);
			self::assertStringNotContainsString('FROM (SELECT', $sql);
			self::assertStringNotContainsString('@_discard', $sql);
		}

		/**
		 * SQL Server windowed routine retrieves require explicit SQL ordering.
		 * @return void
		 */
		public function testWindowRequiresSortByAndUsesOffsetFetch(): void {
			$sql = $this->compile('define function f () void {
				range of u is UserEntity
				cursor c = retrieve (u.id) sort by u.id desc window 1, 4
				foreach (c as row) { }
				retrieve (u.id) sort by u.id window 0
			}');

			self::assertStringContainsString('ORDER BY [u].[id] desc OFFSET 4 ROWS FETCH NEXT 4 ROWS ONLY', $sql);
			self::assertStringContainsString('ORDER BY [u].[id] OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY', $sql);
		}

		/**
		 * @return void
		 */
		public function testWindowWithoutSortByIsRejected(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("SQL Server requires an explicit 'sort by'");
			$this->compile('define function f () void {
				range of u is UserEntity
				cursor c = retrieve (u.id) window 0, 2
				foreach (c as row) { }
			}');
		}

		/**
		 * A scalar function uses a sorted derived query with OFFSET, since SQL Server functions cannot declare cursors.
		 * @return void
		 */
		public function testSortedStandaloneRetrieveInFunctionUsesLegalDerivedTable(): void {
			$sql = $this->compile('
				define function inspect () integer {
					range of u is UserEntity
					retrieve (result = inspect_value(u.id)) sort by u.id desc
					return 1
				}
			');

			self::assertStringContainsString('DECLARE @_discard INT;', $sql);
			self::assertStringContainsString('SELECT @_discard = COUNT(*) FROM (SELECT [dbo].[inspect_value]([u].[id]) as [result] FROM [users] as [u] ORDER BY [u].[id] desc OFFSET 0 ROWS) AS [_discard];', $sql);
			self::assertStringNotContainsString('CURSOR', $sql);
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
					foreach (users as row) {
						return row.id
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
					foreach (readers as row) {
					}
					foreach (writers as row) {
						replace u (banned = false) where u.id = row.id
					}
				}
			');

			self::assertStringContainsString('DECLARE _cur_readers CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id] FROM [users] as [u] ORDER BY [u].[username];', $sql);
			self::assertStringContainsString('DECLARE _cur_writers CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id] FROM [users] as [u] WHERE [u].[banned] = 1 ORDER BY [u].[id] desc;', $sql);
		}

		/**
		 * ++, --, +=, -=, *= and /= compile as the assignments they stand for; the right-hand expression is parenthesized where precedence requires.
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
					total *= n + 1
					total /= n * 2
					return total
				}
			');

			self::assertSame(<<<'SQL'
				CREATE FUNCTION [dbo].[counts](@n INT)
				RETURNS INT
				AS
				BEGIN
					DECLARE @total INT;
					SET @total = 0;
					SET @total = @total + 1;
					SET @total = @total - 1;
					SET @total = @total + @n * 2;
					SET @total = @total - (@n - 1);
					SET @total = @total * (@n + 1);
					SET @total = @total / (@n * 2);
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
					foreach (ids as row) {
						if (row.id = n) {
							continue
						}
						break
					}
					foreach (banned as row) {
						if (row.id = n) {
							continue
						}
						replace u (banned = false) where u.id = row.id
						break
					}
				}
			');

			self::assertSame(<<<'SQL'
				CREATE PROCEDURE [dbo].[skip_some] @n INT
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
					DECLARE _cur_banned CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id] FROM [users] as [u] WHERE [u].[banned] = 1;
					OPEN _cur_banned;
					WHILE 1 = 1
					BEGIN
						FETCH NEXT FROM _cur_banned INTO @_row_banned$id;
						IF @@FETCH_STATUS <> 0 BREAK;
						IF @_row_banned$id = @n
						BEGIN
							CONTINUE;
						END;
						UPDATE [u] SET [u].[banned] = 0 FROM [users] as [u] WHERE [u].[id] = @_row_banned$id;
						BREAK;
					END;
					CLOSE _cur_banned;
					DEALLOCATE _cur_banned;
				END;
				SQL, $sql);
		}

		/**
		 * Two locals of the same name in sibling `if`/`else` branches are block-scoped in EQUEL but
		 * compile to one flat DECLARE section with distinct generated names.
		 * @return void
		 */
		public function testSiblingBranchLocalsGetDistinctDeclarations(): void {
			$sql = $this->compile('
				define function f (integer n) void {
					if (n > 0) {
						integer x = 1
						n = n + x
					} else {
						integer x = 2
						n = n + x
					}
				}
			');

			self::assertStringContainsString('DECLARE @x INT;', $sql);
			self::assertStringContainsString('DECLARE @x_2 INT;', $sql);
			self::assertStringContainsString('SET @x = 1;', $sql);
			self::assertStringContainsString('SET @x_2 = 2;', $sql);
		}

		/**
		 * Two cursors of the same name in sibling `if`/`else` branches are block-scoped in EQUEL but
		 * compile to two distinct `DECLARE ... CURSOR` entries, opened and closed at their own position.
		 * @return void
		 */
		public function testSiblingBranchCursorsGetDistinctDeclarations(): void {
			$sql = $this->compile('
				define function f (integer n) void {
					range of u is UserEntity
					if (n > 0) {
						cursor c = retrieve (u.id) where u.banned = true
						foreach (c as row) {
							n = n + 1
						}
					} else {
						cursor c = retrieve (u.id) where u.banned = false
						foreach (c as row) {
							n = n - 1
						}
					}
				}
			');

			self::assertStringContainsString('DECLARE _cur_c CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id] FROM [users] as [u] WHERE [u].[banned] = 1;', $sql);
			self::assertStringContainsString('DECLARE _cur_c_2 CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id] FROM [users] as [u] WHERE [u].[banned] = 0;', $sql);
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
						foreach (users as row) { transaction { delete u where u.id = row.id } }
					}
				', 'while its cursor is open'],

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
