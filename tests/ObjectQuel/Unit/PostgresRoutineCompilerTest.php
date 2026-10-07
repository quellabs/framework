<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * PL/pgSQL lowering of routines (objectquel-equel-design.md, stage 3).
	 * The expected SQL is not run against PostgreSQL here.
	 */
	class PostgresRoutineCompilerTest extends TestCase {

		/**
		 * @param string $source Routine source
		 * @return string Generated PostgreSQL CREATE statement
		 */
		private function compile(string $source): string {
			$statements = (new ProcedureCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('pgsql'), null))->compile($source);
			self::assertCount(1, $statements);
			return $statements[0];
		}

		/**
		 * A read-only loop becomes FOR ... IN; variables are label-qualified and parameters copied.
		 * @return void
		 */
		public function testReadOnlyFunction(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function count_users (int minId) integer {
					integer total = 0
					cursor users = retrieve (u.id, name = u.username) where u.id > minId
					foreach (users as row) {
						if (row.name = "x") {
							total = total + row.id
						} else {
							total = total + 1
						}
					}
					return total
				}
			');

			self::assertSame(<<<'SQL'
				CREATE FUNCTION "count_users"("minId" INTEGER)
				RETURNS INTEGER
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"minId" INTEGER := $1;
					"total" INTEGER;
					"_row_users" RECORD;
				BEGIN
					"total" := 0;
					FOR "_row_users" IN SELECT "u"."id" as "id","u"."username" as "name" FROM "users" as "u" WHERE "u"."id" > "_routine"."minId" LOOP
						IF "_row_users"."name" = 'x' THEN
							"total" := "_routine"."total" + "_row_users"."id";
						ELSE
							"total" := "_routine"."total" + 1;
						END IF;
					END LOOP;
					RETURN "_routine"."total";
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * A bare `return` (void routines only) compiles to a plain `RETURN;`, usable as an early exit;
		 * void routines compile to a PROCEDURE, not a FUNCTION.
		 * @return void
		 */
		public function testBareReturnCompilesToPlainReturn(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function maybe_ban (integer targetId) void {
					if (targetId <= 0) {
						return
					}
					replace u (banned = true) where u.id = targetId
				}
			');

			self::assertSame(<<<'SQL'
				CREATE PROCEDURE "maybe_ban"("targetId" INTEGER)
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"targetId" INTEGER := $1;
				BEGIN
					IF "_routine"."targetId" <= 0 THEN
						RETURN;
					END IF;
					UPDATE "users" as "u" SET "banned" = true WHERE "u"."id" = "_routine"."targetId";
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * A write inside a loop referencing the row's own fetched field compiles to an ordinary
		 * UPDATE against that fetched value; PL/pgSQL's implicit FOR ... IN loop needs no CLOSE.
		 * @return void
		 */
		public function testWriteInsideLoopReferencesRowField(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function unban_first () integer {
					cursor users = retrieve (u.id) where u.banned = true
					foreach (users as row) {
						replace u (banned = false) where u.id = row.id
						return row.id
					}
					return 0
				}
			');

			self::assertSame(<<<'SQL'
				CREATE FUNCTION "unban_first"()
				RETURNS INTEGER
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"_row_users" RECORD;
				BEGIN
					FOR "_row_users" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = true LOOP
						UPDATE "users" as "u" SET "banned" = false WHERE "u"."id" = "_row_users"."id";
						RETURN "_row_users"."id";
					END LOOP;
					RETURN 0;
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * A void routine becomes a procedure; statements, deletes (soft or not) referencing the loop's
		 * row binding, and atomic blocks lower in place.
		 * @return void
		 */
		public function testProcedureStatements(): void {
			$sql = $this->compile('
				range of u is UserEntity
				range of p is PostEntity
				define function purge (string who) void {
					cursor users = retrieve (u.id) where u.username = who
					foreach (users as row) {
						delete p where p.userId = row.id
						delete u where u.id = row.id
					}
					atomic {
						replace u (banned = false) where u.username = who
						if (who = "") {
							rollback
						}
					}
					retrieve (p.title) where p.userId = 5
					append to u (username = who, password = "x", banned = false)
				}
			');

			self::assertSame(<<<'SQL'
				CREATE PROCEDURE "purge"("who" VARCHAR(255))
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"who" VARCHAR(255) := $1;
					"_row_users" RECORD;
				BEGIN
					FOR "_row_users" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."username" = "_routine"."who" LOOP
						UPDATE "posts" as "p" SET "deleted_at" = NOW() WHERE "p"."user_id" = "_row_users"."id";
						DELETE FROM "users" as "u" WHERE "u"."id" = "_row_users"."id";
					END LOOP;
					BEGIN
						UPDATE "users" as "u" SET "banned" = false WHERE "u"."username" = "_routine"."who";
						IF "_routine"."who" = '' THEN
							RAISE SQLSTATE 'PZ001';
						END IF;
					EXCEPTION WHEN SQLSTATE 'PZ001' THEN
						NULL;
					END;
					PERFORM "p"."title" as "title" FROM "posts" as "p" WHERE "p"."user_id" = 5 AND "p"."deleted_at" IS NULL;
					INSERT INTO "users" ("username", "password", "banned") VALUES ("_routine"."who", 'x', false);
				END;
				$body$;
				SQL, $sql);
		}

		public function testWindowedRetrieveUsesLimitOffsetWithoutSort(): void {
			$sql = $this->compile('range of u is UserEntity define function f () void {
				cursor c = retrieve (u.id) window 3, 2
				foreach (c as row) { }
				retrieve (u.id) window 0
			}');

			self::assertStringContainsString('LIMIT 2 OFFSET 6', $sql);
			self::assertStringContainsString('LIMIT 1 OFFSET 0', $sql);
		}

		/**
		 * An embedded retrieve keeps only the ranges it reads, plus the ranges their `via` conditions need.
		 * @return void
		 */
		public function testEmbeddedRetrieveDropsUnusedRanges(): void {
			$sql = $this->compile('
				range of u is UserEntity
				range of p is PostEntity via p.userId = u.id
				range of other is UserEntity
				define function f () void {
					retrieve (p.title) where u.id = 1
					retrieve (other.username) where other.id = 2
				}
			');

			self::assertStringContainsString('FROM "users" as "u" LEFT JOIN "posts" as "p" ON "p"."user_id" = "u"."id"', $sql);
			self::assertStringContainsString('PERFORM "other"."username" as "username" FROM "users" as "other" WHERE "other"."id" = 2;', $sql);
			self::assertStringNotContainsString("DECLARE", $sql, 'A routine without variables has no DECLARE section');
		}

		/**
		 * Sort terms compile to ORDER BY, before FOR UPDATE on a cursor that takes current-row writes.
		 * @return void
		 */
		public function testSortByCompilesToOrderBy(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f (int minId) void {
					cursor readers = retrieve (u.id, name = u.username) where u.id > minId sort by name desc, abs(u.id - minId)
					cursor writers = retrieve (u.id) where u.banned = true sort by u.id desc
					foreach (readers as row) {
					}
					foreach (writers as row) {
						replace u (banned = false) where u.id = row.id
					}
				}
			');

			self::assertStringContainsString('FOR "_row_readers" IN SELECT "u"."id" as "id","u"."username" as "name" FROM "users" as "u" WHERE "u"."id" > "_routine"."minId" ORDER BY "u"."username" desc,"abs"("u"."id" - "_routine"."minId") LOOP', $sql);
			self::assertStringContainsString('FOR "_row_writers" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = true ORDER BY "u"."id" desc LOOP', $sql);
		}

		/**
		 * A nullable datetime sorts with a date default, which PostgreSQL accepts for TIMESTAMP.
		 * @return void
		 */
		public function testNullableDatetimeSortsWithDateDefault(): void {
			$sql = $this->compile('
				range of p is PostEntity
				define function f () void {
					cursor posts = retrieve (p.id) sort by p.deletedAt
					foreach (posts as row) {
					}
				}
			');

			self::assertStringContainsString('ORDER BY COALESCE("p"."deleted_at", \'0001-01-01\') LOOP', $sql);
		}

		/**
		 * The dollar-quote tag never occurs inside the body.
		 * @return void
		 */
		public function testDollarQuoteTagAvoidsBodyText(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f () void {
					retrieve (n = "$body$") where u.id = 1
				}
			');

			self::assertStringContainsString("AS \$body1\$\n", $sql);
			self::assertStringEndsWith("\n\$body1\$;", $sql);
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
				CREATE FUNCTION "counts"("n" INTEGER)
				RETURNS INTEGER
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"n" INTEGER := $1;
					"total" INTEGER;
				BEGIN
					"total" := 0;
					"total" := "_routine"."total" + 1;
					"total" := "_routine"."total" - 1;
					"total" := "_routine"."total" + "_routine"."n" * 2;
					"total" := "_routine"."total" - ("_routine"."n" - 1);
					"total" := "_routine"."total" * ("_routine"."n" + 1);
					"total" := "_routine"."total" / ("_routine"."n" * 2);
					RETURN "_routine"."total";
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * break/continue become EXIT/CONTINUE in a while, a FOR ... IN loop and an explicit-cursor loop.
		 * @return void
		 */
		public function testBreakAndContinue(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function skip_some (integer n) void {
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
				CREATE PROCEDURE "skip_some"("n" INTEGER)
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"n" INTEGER := $1;
					"_row_ids" RECORD;
					"_row_banned" RECORD;
				BEGIN
					WHILE "_routine"."n" > 0 LOOP
						"n" := "_routine"."n" - 1;
						IF "_routine"."n" = 5 THEN
							CONTINUE;
						END IF;
						IF "_routine"."n" = 2 THEN
							EXIT;
						END IF;
					END LOOP;
					FOR "_row_ids" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."id" > 0 LOOP
						IF "_row_ids"."id" = "_routine"."n" THEN
							CONTINUE;
						END IF;
						EXIT;
					END LOOP;
					FOR "_row_banned" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = true LOOP
						IF "_row_banned"."id" = "_routine"."n" THEN
							CONTINUE;
						END IF;
						UPDATE "users" as "u" SET "banned" = false WHERE "u"."id" = "_row_banned"."id";
						EXIT;
					END LOOP;
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * An `atomic` block inside `foreach` is fine on PostgreSQL: PL/pgSQL's implicit `FOR ... IN`
		 * loop holds no explicit cursor for a subtransaction rollback to invalidate.
		 * @return void
		 */
		public function testAtomicInsideForeachIsSupported(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f () void {
					cursor users = retrieve (u.id)
					foreach (users as row) {
						atomic {
							delete u where u.id = row.id
						}
					}
				}
			');

			self::assertStringContainsString('FOR "_row_users" IN SELECT "u"."id" as "id" FROM "users" as "u" LOOP', $sql);
			self::assertStringContainsString('DELETE FROM "users" as "u" WHERE "u"."id" = "_row_users"."id";', $sql);
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

			self::assertStringContainsString('"x" INTEGER;', $sql);
			self::assertStringContainsString('"x_2" INTEGER;', $sql);
			self::assertStringContainsString('"x" := 1;', $sql);
			self::assertStringContainsString('"x_2" := 2;', $sql);
		}

		/**
		 * Two cursors of the same name in sibling `if`/`else` branches are block-scoped in EQUEL but
		 * each gets its own `FOR ... IN` loop over its own query.
		 * @return void
		 */
		public function testSiblingBranchCursorsGetDistinctQueries(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f (integer n) void {
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

			self::assertStringContainsString('FOR "_row_c" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = true LOOP', $sql);
			self::assertStringContainsString('FOR "_row_c_2" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = false LOOP', $sql);
		}

		/**
		 * `c = retrieve (...)` rebinds a cursor to a fresh query; the rebind statement itself
		 * compiles to no SQL of its own, and each `foreach` reads whichever query was current
		 * at that point in the source.
		 * @return void
		 */
		public function testCursorRebindCompilesToNoStatementOfItsOwn(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f () void {
					cursor c = retrieve (u.id) where u.banned = false
					foreach (c as row) { }
					c = retrieve (u.id) where u.banned = true
					foreach (c as row) { }
				}
			');

			self::assertStringContainsString('FOR "_row_c" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = false LOOP', $sql);
			self::assertStringContainsString('FOR "_row_c_2" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = true LOOP', $sql);
			self::assertSame(1, substr_count($sql, 'banned" = true'), 'the rebind must not emit a statement of its own');
		}

		/**
		 * Nested shadows keep distinct local variables and cursor loop queries.
		 * @return void
		 */
		public function testNestedShadowingCompilesWithDistinctNames(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f (integer n) void {
					integer x = 1
					cursor c = retrieve (u.id) where u.id > 0
					if (n > 0) {
						integer x = 2
						x = x + 1
						cursor c = retrieve (u.id) where u.id > 1
						foreach (c as innerRow) { n = n + x }
					}
					x = x + 1
					foreach (c as outerRow) { n = n + x }
				}
			');

			self::assertStringContainsString('"x" INTEGER;', $sql);
			self::assertStringContainsString('"x_2" INTEGER;', $sql);
			self::assertStringContainsString('"x" := 1;', $sql);
			self::assertStringContainsString('"x_2" := 2;', $sql);
			self::assertStringContainsString('"x_2" := "_routine"."x_2" + 1;', $sql);
			self::assertStringContainsString('"x" := "_routine"."x" + 1;', $sql);
			self::assertStringContainsString('FOR "_row_c_2" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."id" > 1 LOOP', $sql);
			self::assertStringContainsString('FOR "_row_c" IN SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."id" > 0 LOOP', $sql);
		}

		/**
		 * Reused nested row names resolve to each loop's own record variable.
		 * @return void
		 */
		public function testNestedRowBindingShadowsAndRestoresOuterRow(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f () void {
					integer x = 0
					cursor a = retrieve (u.id)
					cursor b = retrieve (u.id) where u.id > 1
					foreach (a as row) {
						x = row.id
						foreach (b as row) { x = row.id }
						x = row.id
					}
				}
			');

			self::assertSame(2, substr_count($sql, '"x" := "_row_a"."id";'));
			self::assertSame(1, substr_count($sql, '"x" := "_row_b"."id";'));
			self::assertStringContainsString('FOR "_row_a" IN SELECT', $sql);
			self::assertStringContainsString('FOR "_row_b" IN SELECT', $sql);
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function rejectedRoutines(): array {
			return [
				'atomic in a function' => ['
					range of u is UserEntity
					define function f () integer {
						atomic {
							delete u where u.id = 1
						}
						return 1
					}
				', 'only supported in void or trigger functions'],
				'retrieve without a range' => ['
					define function f () void {
						retrieve (x = 1)
					}
				', "needs PHP-side processing"],
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

		/**
		 * SQLite has no stored routines.
		 * @return void
		 */
		public function testSqliteIsNotSupported(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Routines can't be compiled for 'sqlite'.");

			(new ProcedureCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('sqlite'), null))->compile('
				range of u is UserEntity
				define function f () void {
					delete u where u.id = 1
				}
			');
		}
	}
