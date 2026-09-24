<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * MySQL/MariaDB lowering of routines (objectquel-equel-design.md, stage 4).
	 * The expected SQL is not run against MySQL or MariaDB here.
	 */
	class MysqlRoutineCompilerTest extends TestCase {

		/**
		 * @param string $source Routine source
		 * @param string $databaseType 'mysql' or 'mariadb'
		 * @return string[] Generated statements
		 */
		private function compile(string $source, string $databaseType = 'mysql'): array {
			return (new ProcedureCompiler($GLOBALS['test_em'], new FakePlatformCapabilities($databaseType), $databaseType === 'sqlsrv' ? 'dbo' : null))->compile($source);
		}

		/**
		 * @param string $source Routine source
		 * @param string $collation Configured collation
		 * @return string[] Generated statements
		 */
		private function compileWithCollation(string $source, string $collation): array {
			$configuration = $GLOBALS['test_em']->getConfiguration();
			$previous = $configuration->getCollation();
			$configuration->setCollation($collation);

			try {
				return $this->compile($source);
			} finally {
				$configuration->setCollation($previous);
			}
		}

		/**
		 * A configured collation goes on every character-typed parameter, local, fetched field and return type.
		 * @return void
		 */
		public function testConfiguredCollationOnCharacterTypes(): void {
			$statements = $this->compileWithCollation('
				define function find_user (string who, int minId) string {
					string found = ""
					range of u is UserEntity
					cursor users = retrieve (u.id, u.username) where u.username = who and u.id > minId
					foreach users {
						found = users.username
					}
					return found
				}
			', 'utf8mb4_unicode_ci');

			$collated = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			self::assertStringContainsString("CREATE FUNCTION `find_user`(_v_who VARCHAR(255) {$collated}, _v_minId INT)", $statements[1]);
			self::assertStringContainsString("RETURNS VARCHAR(255) {$collated}", $statements[1]);
			self::assertStringContainsString("DECLARE _v_found VARCHAR(255) {$collated};", $statements[1]);
			self::assertStringContainsString("DECLARE _row_users\$username VARCHAR(255) {$collated};", $statements[1]);
			self::assertStringContainsString('DECLARE _row_users$id INT UNSIGNED;', $statements[1]);
		}

		/**
		 * @return void
		 */
		public function testRejectsAnUnsafeCollationName(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("isn't a valid collation name");
			$this->compileWithCollation('define function f () integer { return 1 }', 'utf8mb4_unicode_ci; DROP TABLE users');
		}

		/**
		 * Loops fetch into typed variables until the NOT FOUND handler sets _done; locals are prefixed.
		 * @return void
		 */
		public function testReadOnlyFunction(): void {
			$statements = $this->compile('
				define function count_users (int minId) integer {
					integer total = 0
					boolean found = false
					range of u is UserEntity
					cursor users = retrieve (u.id, name = u.username, flag = u.banned) where u.id > minId
					foreach users {
						if users.name = "x" and users.flag {
							total = total + users.id
							found = total > 3
						} else {
						}
					}
					while found {
						found = false
					}
					return total
				}
			');

			self::assertSame('DROP FUNCTION IF EXISTS `count_users`', $statements[0]);
			self::assertSame(<<<'SQL'
				CREATE FUNCTION `count_users`(_v_minId INT)
				RETURNS INT
				READS SQL DATA
				BEGIN
					DECLARE _v_total INT;
					DECLARE _v_found TINYINT(1);
					DECLARE _row_users$id INT UNSIGNED;
					DECLARE _row_users$name VARCHAR(255);
					DECLARE _row_users$flag TINYINT(1);
					DECLARE _done BOOLEAN;
					DECLARE _cur_users CURSOR FOR SELECT `u`.`id` as `id`,`u`.`username` as `name`,`u`.`banned` as `flag` FROM `users` as `u` WHERE `u`.`id` > _v_minId;
					DECLARE CONTINUE HANDLER FOR NOT FOUND SET _done = TRUE;
					SET _v_total = 0;
					SET _v_found = false;
					OPEN _cur_users;
					_loop1: LOOP
						SET _done = FALSE;
						FETCH _cur_users INTO _row_users$id, _row_users$name, _row_users$flag;
						IF _done THEN LEAVE _loop1; END IF;
						IF _row_users$name = 'x' AND _row_users$flag THEN
							SET _v_total = _v_total + _row_users$id;
							SET _v_found = _v_total > 3;
						ELSE
							BEGIN END;
						END IF;
					END LOOP _loop1;
					CLOSE _cur_users;
					_loop2: WHILE _v_found DO
						SET _v_found = false;
					END WHILE _loop2;
					RETURN _v_total;
				END
				SQL, $statements[1]);
		}

		/**
		 * Current-row writes match the primary key, which the cursor fetches under a generated alias.
		 * @return void
		 */
		public function testProcedureStatements(): void {
			$statements = $this->compile('
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
						if who = "" {
							abort
						}
					}
					retrieve (p.title) where p.userId = 5
					append to u (username = who, password = "x", banned = false)
				}
			');

			self::assertSame('DROP PROCEDURE IF EXISTS `purge`', $statements[0]);
			self::assertSame(<<<'SQL'
				CREATE PROCEDURE `purge`(_v_who VARCHAR(255))
				MODIFIES SQL DATA
				BEGIN
					DECLARE _row_users$username VARCHAR(255);
					DECLARE _row_users$_pk_id INT UNSIGNED;
					DECLARE _done BOOLEAN;
					DECLARE _discard INT;
					DECLARE _cur_users CURSOR FOR SELECT `u`.`username` as `username`,`u`.`id` as `_pk_id` FROM `users` as `u` WHERE `u`.`username` = _v_who;
					DECLARE CONTINUE HANDLER FOR NOT FOUND SET _done = TRUE;
					OPEN _cur_users;
					_loop1: LOOP
						SET _done = FALSE;
						FETCH _cur_users INTO _row_users$username, _row_users$_pk_id;
						IF _done THEN LEAVE _loop1; END IF;
						UPDATE `posts` as `p` SET `p`.`deleted_at` = NOW() WHERE `p`.`user_id` = 5;
						UPDATE `users` as `u` SET `u`.`banned` = true WHERE `u`.`id` = _row_users$_pk_id;
						DELETE FROM `users` as `u` WHERE `u`.`id` = _row_users$_pk_id;
					END LOOP _loop1;
					CLOSE _cur_users;
					START TRANSACTION;
					UPDATE `users` as `u` SET `u`.`banned` = false WHERE `u`.`username` = _v_who;
					IF _v_who = '' THEN
						ROLLBACK;
					END IF;
					COMMIT;
					SELECT COUNT(*) INTO _discard FROM (SELECT `p`.`title` as `title` FROM `posts` as `p` WHERE `p`.`user_id` = 5 AND `p`.`deleted_at` IS NULL) AS `_discard`;
					INSERT INTO `users` (`username`, `password`, `banned`) VALUES (_v_who, 'x', false);
				END
				SQL, $statements[1]);
		}

		/**
		 * A primary key the cursor already selects is reused instead of added.
		 * @return void
		 */
		public function testSelectedPrimaryKeyIsReused(): void {
			$statements = $this->compile('
				define function unban () void {
					range of u is UserEntity
					cursor users = retrieve (u.id, u.username) where u.banned
					foreach users {
						replace users (banned = false)
					}
				}
			');

			self::assertStringContainsString('CURSOR FOR SELECT `u`.`id` as `id`,`u`.`username` as `username` FROM', $statements[1]);
			self::assertStringContainsString('UPDATE `users` as `u` SET `u`.`banned` = false WHERE `u`.`id` = _row_users$id;', $statements[1]);
		}

		/**
		 * MariaDB replaces the routine in one statement.
		 * @return void
		 */
		public function testMariaDbUsesCreateOrReplace(): void {
			$statements = $this->compile('
				define function f () integer {
					return 1
				}
			', 'mariadb');

			self::assertSame(["CREATE OR REPLACE FUNCTION `f`()\nRETURNS INT\nREADS SQL DATA\nBEGIN\n\tRETURN 1;\nEND"], $statements);
		}

		/**
		 * Nested loops get distinct numbered labels.
		 * @return void
		 */
		public function testNestedLoopsGetDistinctLabels(): void {
			$statements = $this->compile('
				define function f () void {
					range of u is UserEntity
					range of p is PostEntity
					cursor users = retrieve (u.id)
					cursor posts = retrieve (p.id)
					foreach users {
						foreach posts {
							delete p where p.id = posts.id
						}
					}
				}
			');

			self::assertStringContainsString("\t_loop1: LOOP\n", $statements[1]);
			self::assertStringContainsString("\t\t_loop2: LOOP\n", $statements[1]);
			self::assertStringContainsString("IF _done THEN LEAVE _loop2; END IF;", $statements[1]);
		}

		/**
		 * break/continue become LEAVE/ITERATE naming the loop's label; while loops are labelled too.
		 * @return void
		 */
		public function testBreakAndContinue(): void {
			$statements = $this->compile('
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
				CREATE PROCEDURE `skip_some`(_v_n INT)
				MODIFIES SQL DATA
				BEGIN
					DECLARE _row_ids$id INT UNSIGNED;
					DECLARE _row_banned$id INT UNSIGNED;
					DECLARE _done BOOLEAN;
					DECLARE _cur_ids CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`id` > 0;
					DECLARE _cur_banned CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` = true;
					DECLARE CONTINUE HANDLER FOR NOT FOUND SET _done = TRUE;
					_loop1: WHILE _v_n > 0 DO
						SET _v_n = _v_n - 1;
						IF _v_n = 5 THEN
							ITERATE _loop1;
						END IF;
						IF _v_n = 2 THEN
							LEAVE _loop1;
						END IF;
					END WHILE _loop1;
					OPEN _cur_ids;
					_loop2: LOOP
						SET _done = FALSE;
						FETCH _cur_ids INTO _row_ids$id;
						IF _done THEN LEAVE _loop2; END IF;
						IF _row_ids$id = _v_n THEN
							ITERATE _loop2;
						END IF;
						LEAVE _loop2;
					END LOOP _loop2;
					CLOSE _cur_ids;
					OPEN _cur_banned;
					_loop3: LOOP
						SET _done = FALSE;
						FETCH _cur_banned INTO _row_banned$id;
						IF _done THEN LEAVE _loop3; END IF;
						IF _row_banned$id = _v_n THEN
							ITERATE _loop3;
						END IF;
						UPDATE `users` as `u` SET `u`.`banned` = false WHERE `u`.`id` = _row_banned$id;
						LEAVE _loop3;
					END LOOP _loop3;
					CLOSE _cur_banned;
				END
				SQL, $statements[1]);
		}

		/**
		 * continue in a nested loop names the inner loop's label, break the outer one's.
		 * @return void
		 */
		public function testLoopExitsNameTheirInnermostLoop(): void {
			$statements = $this->compile('
				define function nested (integer n) integer {
					integer total = 0
					range of u is UserEntity
					cursor ids = retrieve (u.id) where u.id > 0
					while n > 0 {
						foreach ids {
							if ids.id = n {
								continue
							}
							total = total + 1
						}
						if total > 10 {
							break
						}
						n = n - 1
					}
					return total
				}
			');

			self::assertSame(<<<'SQL'
				CREATE FUNCTION `nested`(_v_n INT)
				RETURNS INT
				READS SQL DATA
				BEGIN
					DECLARE _v_total INT;
					DECLARE _row_ids$id INT UNSIGNED;
					DECLARE _done BOOLEAN;
					DECLARE _cur_ids CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`id` > 0;
					DECLARE CONTINUE HANDLER FOR NOT FOUND SET _done = TRUE;
					SET _v_total = 0;
					_loop1: WHILE _v_n > 0 DO
						OPEN _cur_ids;
						_loop2: LOOP
							SET _done = FALSE;
							FETCH _cur_ids INTO _row_ids$id;
							IF _done THEN LEAVE _loop2; END IF;
							IF _row_ids$id = _v_n THEN
								ITERATE _loop2;
							END IF;
							SET _v_total = _v_total + 1;
						END LOOP _loop2;
						CLOSE _cur_ids;
						IF _v_total > 10 THEN
							LEAVE _loop1;
						END IF;
						SET _v_n = _v_n - 1;
					END WHILE _loop1;
					RETURN _v_total;
				END
				SQL, $statements[1]);
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
				', "MySQL creates it as a FUNCTION, which can't commit or roll back"],

				'transaction inside a loop' => ['
					define function f () void {
						range of u is UserEntity
						cursor users = retrieve (u.id)
						foreach users {
							begin transaction {
								delete users
							}
						}
					}
				', "the loop's cursor may not survive the COMMIT"],
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
