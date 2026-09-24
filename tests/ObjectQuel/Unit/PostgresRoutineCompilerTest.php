<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureCompiler;
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
				define function count_users (int minId) integer {
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id, name = u.username) where u.id > minId
					foreach users {
						if users.name = "x" {
							total = total + users.id
						} else {
							total = total + 1
						}
					}
					return total
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR REPLACE FUNCTION "count_users"("minId" INTEGER)
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
		 * Current-row writes use an explicit FOR UPDATE cursor; a return inside the loop closes it first.
		 * @return void
		 */
		public function testCurrentRowWritesUseExplicitCursor(): void {
			$sql = $this->compile('
				define function unban_first () integer {
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.banned = true
					foreach users {
						replace users (banned = false)
						return users.id
					}
					return 0
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR REPLACE FUNCTION "unban_first"()
				RETURNS INTEGER
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"_row_users" RECORD;
					"users" CURSOR FOR SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = true FOR UPDATE;
				BEGIN
					"users" := NULL;
					OPEN "users";
					LOOP
						FETCH "users" INTO "_row_users";
						EXIT WHEN NOT FOUND;
						UPDATE "users" as "u" SET "banned" = false WHERE CURRENT OF "users";
						CLOSE "users";
						RETURN "_row_users"."id";
					END LOOP;
					CLOSE "users";
					RETURN 0;
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * A void routine becomes a procedure; statements, current-row deletes (soft or not) and transactions lower in place.
		 * @return void
		 */
		public function testProcedureStatements(): void {
			$sql = $this->compile('
				define function purge (string who) void {
					range of u is UserEntity
					range of p is PostEntity
					cursor users = retrieve (u.id) where u.username = who
					foreach users {
						delete p where p.userId = users.id
						delete users
					}
					begin transaction {
						replace u (banned = false) where u.username = who
						if who = "" {
							abort
						}
					}
					retrieve (p.title) where p.userId = 5
					append to u (username = who, password = "x", banned = false)
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR REPLACE PROCEDURE "purge"("who" VARCHAR(255))
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"who" VARCHAR(255) := $1;
					"_row_users" RECORD;
					"users" CURSOR FOR SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."username" = "_routine"."who" FOR UPDATE;
				BEGIN
					"users" := NULL;
					OPEN "users";
					LOOP
						FETCH "users" INTO "_row_users";
						EXIT WHEN NOT FOUND;
						UPDATE "posts" as "p" SET "deleted_at" = NOW() WHERE "p"."user_id" = "_row_users"."id";
						DELETE FROM "users" as "u" WHERE CURRENT OF "users";
					END LOOP;
					CLOSE "users";
					COMMIT;
					UPDATE "users" as "u" SET "banned" = false WHERE "u"."username" = "_routine"."who";
					IF "_routine"."who" = '' THEN
						ROLLBACK;
					END IF;
					COMMIT;
					PERFORM "p"."title" as "title" FROM "posts" as "p" WHERE "p"."user_id" = 5 AND "p"."deleted_at" IS NULL;
					INSERT INTO "users" ("username", "password", "banned") VALUES ("_routine"."who", 'x', false);
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * An embedded retrieve keeps only the ranges it reads, plus the ranges their `via` conditions need.
		 * @return void
		 */
		public function testEmbeddedRetrieveDropsUnusedRanges(): void {
			$sql = $this->compile('
				define function f () void {
					range of u is UserEntity
					range of p is PostEntity via p.userId = u.id
					range of other is UserEntity
					retrieve (p.title) where u.id = 1
					retrieve (other.username) where other.id = 2
				}
			');

			self::assertStringContainsString('FROM "users" as "u" LEFT JOIN "posts" as "p" ON "p"."user_id" = "u"."id"', $sql);
			self::assertStringContainsString('PERFORM "other"."username" as "username" FROM "users" as "other" WHERE "other"."id" = 2;', $sql);
			self::assertStringNotContainsString("DECLARE", $sql, 'A routine without variables has no DECLARE section');
		}

		/**
		 * The dollar-quote tag never occurs inside the body.
		 * @return void
		 */
		public function testDollarQuoteTagAvoidsBodyText(): void {
			$sql = $this->compile('
				define function f () void {
					range of u is UserEntity
					retrieve (n = "$body$") where u.id = 1
				}
			');

			self::assertStringContainsString("AS \$body1\$\n", $sql);
			self::assertStringEndsWith("\n\$body1\$;", $sql);
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
					--total
					total += n * 2
					total -= n - 1
					return total
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR REPLACE FUNCTION "counts"("n" INTEGER)
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
				define function skip_some (integer n) void {
					range of u is UserEntity
					cursor ids = retrieve (u.id) where u.id > 0
					cursor banned = retrieve (u.id) where u.banned = true
					while n > 0 {
						n = n - 1
						if n = 5 {
							continue
						}
						if n = 2 {
							break
						}
					}
					foreach ids {
						if ids.id = n {
							continue
						}
						break
					}
					foreach banned {
						if banned.id = n {
							continue
						}
						replace banned (banned = false)
						break
					}
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR REPLACE PROCEDURE "skip_some"("n" INTEGER)
				LANGUAGE plpgsql
				AS $body$
				<<_routine>>
				DECLARE
					"n" INTEGER := $1;
					"_row_ids" RECORD;
					"_row_banned" RECORD;
					"banned" CURSOR FOR SELECT "u"."id" as "id" FROM "users" as "u" WHERE "u"."banned" = true FOR UPDATE;
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
					"banned" := NULL;
					OPEN "banned";
					LOOP
						FETCH "banned" INTO "_row_banned";
						EXIT WHEN NOT FOUND;
						IF "_row_banned"."id" = "_routine"."n" THEN
							CONTINUE;
						END IF;
						UPDATE "users" as "u" SET "banned" = false WHERE CURRENT OF "banned";
						EXIT;
					END LOOP;
					CLOSE "banned";
				END;
				$body$;
				SQL, $sql);
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function rejectedRoutines(): array {
			return [
				'transaction in a function' => ['
					define function f () integer {
						range of u is UserEntity
						begin transaction {
							delete u where u.id = 1
						}
						return 1
					}
				', "PostgreSQL creates it as a FUNCTION, which can't commit or roll back"],

				'transaction inside a writing loop' => ['
					define function f () void {
						range of u is UserEntity
						cursor users = retrieve (u.id)
						foreach users {
							begin transaction {
								delete users
							}
						}
					}
				', "uses a cursor that COMMIT would close"],

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
				define function f () void {
					range of u is UserEntity
					delete u where u.id = 1
				}
			');
		}
	}
