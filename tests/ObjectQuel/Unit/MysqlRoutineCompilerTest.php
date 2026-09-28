<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;
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
					foreach (users as row) {
						found = row.username
					}
					return found
				}
			', 'utf8mb4_unicode_ci');

			$collated = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			self::assertStringContainsString("CREATE FUNCTION `find_user`(_v_who VARCHAR(255) {$collated}, _v_minId INT)", $statements[0]);
			self::assertStringContainsString("RETURNS VARCHAR(255) {$collated}", $statements[0]);
			self::assertStringContainsString("DECLARE _v_found VARCHAR(255) {$collated};", $statements[0]);
			self::assertStringContainsString("DECLARE _row_users\$username VARCHAR(255) {$collated};", $statements[0]);
			self::assertStringContainsString('DECLARE _row_users$id INT UNSIGNED;', $statements[0]);
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
				SQL, $statements[0]);
		}

		/**
		 * A write inside a loop is an ordinary replace/delete with its own explicit where,
		 * referencing the loop's row binding just like any other routine variable.
		 * @return void
		 */
		public function testProcedureStatements(): void {
			$statements = $this->compile('
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
				CREATE PROCEDURE `purge`(_v_who VARCHAR(255))
				MODIFIES SQL DATA
				COMMENT 'ObjectQuel:atomic-block'
				BEGIN
					DECLARE _row_users$id INT UNSIGNED;
					DECLARE _row_users$username VARCHAR(255);
					DECLARE _done BOOLEAN;
					DECLARE _discard INT;
					DECLARE _cur_users CURSOR FOR SELECT `u`.`id` as `id`,`u`.`username` as `username` FROM `users` as `u` WHERE `u`.`username` = _v_who;
					DECLARE CONTINUE HANDLER FOR NOT FOUND SET _done = TRUE;
					OPEN _cur_users;
					_loop1: LOOP
						SET _done = FALSE;
						FETCH _cur_users INTO _row_users$id, _row_users$username;
						IF _done THEN LEAVE _loop1; END IF;
						UPDATE `posts` as `p` SET `p`.`deleted_at` = NOW() WHERE `p`.`user_id` = 5;
						UPDATE `users` as `u` SET `u`.`banned` = true WHERE `u`.`id` = _row_users$id;
						DELETE FROM `users` as `u` WHERE `u`.`id` = _row_users$id;
					END LOOP _loop1;
					CLOSE _cur_users;
					IF COALESCE(@_equel_guard_c1a4373e1006096b, 0) <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recursive atomic block is not supported'; END IF;
					SAVEPOINT equel_c1a4373e1006096b;
					RELEASE SAVEPOINT equel_c1a4373e1006096b;
					SAVEPOINT equel_c1a4373e1006096b;
					SET @_equel_guard_c1a4373e1006096b = 1;
					_equel_atomic: BEGIN
						DECLARE EXIT HANDLER FOR SQLEXCEPTION
						BEGIN
							SET @_equel_guard_c1a4373e1006096b = 0;
							ROLLBACK TO SAVEPOINT equel_c1a4373e1006096b;
							RELEASE SAVEPOINT equel_c1a4373e1006096b;
							RESIGNAL;
						END;
						UPDATE `users` as `u` SET `u`.`banned` = false WHERE `u`.`username` = _v_who;
						IF _v_who = '' THEN
							ROLLBACK TO SAVEPOINT equel_c1a4373e1006096b; RELEASE SAVEPOINT equel_c1a4373e1006096b; SET @_equel_guard_c1a4373e1006096b = 0; LEAVE _equel_atomic;
						END IF;
						RELEASE SAVEPOINT equel_c1a4373e1006096b;
						SET @_equel_guard_c1a4373e1006096b = 0;
					END _equel_atomic;
					SELECT COUNT(*) INTO _discard FROM (SELECT `p`.`title` as `title` FROM `posts` as `p` WHERE `p`.`user_id` = 5 AND `p`.`deleted_at` IS NULL) AS `_discard`;
					INSERT INTO `users` (`username`, `password`, `banned`) VALUES (_v_who, 'x', false);
				END
				SQL, $statements[0]);
		}

		/**
		 * A write inside a loop referencing the row's own fetched field compiles to the
		 * variable that field was fetched into.
		 * @return void
		 */
		public function testWriteInsideLoopReferencesRowField(): void {
			$statements = $this->compile('
				define function unban () void {
					range of u is UserEntity
					cursor users = retrieve (u.id, u.username) where u.banned
					foreach (users as row) {
						replace u (banned = false) where u.id = row.id
					}
				}
			');

			self::assertStringContainsString('CURSOR FOR SELECT `u`.`id` as `id`,`u`.`username` as `username` FROM', $statements[0]);
			self::assertStringContainsString('UPDATE `users` as `u` SET `u`.`banned` = false WHERE `u`.`id` = _row_users$id;', $statements[0]);
		}

		/**
		 * Sort terms compile to ORDER BY; a target-list name is sorted by its expression.
		 * @return void
		 */
		public function testSortByCompilesToOrderBy(): void {
			$statements = $this->compile('
				define function unban () void {
					range of u is UserEntity
					cursor users = retrieve (name = u.username, u.id) where u.banned sort by name desc, u.id
					foreach (users as row) {
						replace u (banned = false) where u.id = row.id
					}
				}
			');

			self::assertStringContainsString('DECLARE _cur_users CURSOR FOR SELECT `u`.`username` as `name`,`u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` ORDER BY `u`.`username` desc,`u`.`id`;', $statements[0]);
		}

		/**
		 * Routine windows paginate in SQL for cursor and discarded retrieve statements.
		 * @return void
		 */
		public function testWindowedRetrievesCompileLimitAndOffset(): void {
			$statements = $this->compile('define function f () void {
				range of u is UserEntity
			cursor c = retrieve (u.id) sort by u.id window 0, 5
			foreach (c as row) { }
			retrieve (u.id) window 1 using window_size 3
			retrieve (u.id) window 1
		}');

			self::assertStringContainsString('ORDER BY `u`.`id` LIMIT 5 OFFSET 0', $statements[0]);
			self::assertStringContainsString('LIMIT 3 OFFSET 3', $statements[0]);
			self::assertStringContainsString('LIMIT 1 OFFSET 1', $statements[0]);
		}

		/**
		 * MariaDB creates the routine in one statement without replacing an existing one.
		 * @return void
		 */
		public function testMariaDbUsesCreateOnly(): void {
			$statements = $this->compile('
				define function f () integer {
					return 1
				}
			', 'mariadb');

			self::assertSame(["CREATE FUNCTION `f`()\nRETURNS INT\nREADS SQL DATA\nBEGIN\n\tRETURN 1;\nEND"], $statements);
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
					foreach (users as ru) {
						foreach (posts as rp) {
							delete p where p.id = rp.id
						}
					}
				}
			');

			self::assertStringContainsString("\t_loop1: LOOP\n", $statements[0]);
			self::assertStringContainsString("\t\t_loop2: LOOP\n", $statements[0]);
			self::assertStringContainsString("IF _done THEN LEAVE _loop2; END IF;", $statements[0]);
		}

		/**
		 * ++, --, +=, -=, *= and /= compile as the assignments they stand for; the right-hand expression is parenthesized where precedence requires.
		 * @return void
		 */
		public function testIncrementAndCompoundAssignment(): void {
			$statements = $this->compile('
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
				CREATE FUNCTION `counts`(_v_n INT)
				RETURNS INT
				READS SQL DATA
				BEGIN
					DECLARE _v_total INT;
					SET _v_total = 0;
					SET _v_total = _v_total + 1;
					SET _v_total = _v_total - 1;
					SET _v_total = _v_total + _v_n * 2;
					SET _v_total = _v_total - (_v_n - 1);
					SET _v_total = _v_total * (_v_n + 1);
					SET _v_total = _v_total / (_v_n * 2);
					RETURN _v_total;
				END
				SQL, $statements[0]);
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
					while (n > 0) {
						n = n - 1
						if (n = 5) {
							continue
						}
						if (n = 2) {
							break
						}
					}
					foreach (ids as ri) {
						if (ri.id = n) {
							continue
						}
						break
					}
					foreach (banned as rb) {
						if (rb.id = n) {
							continue
						}
						replace u (banned = false) where u.id = rb.id
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
				SQL, $statements[0]);
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
					while (n > 0) {
						foreach (ids as row) {
							if (row.id = n) {
								continue
							}
							total = total + 1
						}
						if (total > 10) {
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
				SQL, $statements[0]);
		}

		/**
		 * Two locals of the same name in sibling `if`/`else` branches are block-scoped in EQUEL but
		 * compile to one flat DECLARE section with distinct generated names.
		 * @return void
		 */
		public function testSiblingBranchLocalsGetDistinctDeclarations(): void {
			$statements = $this->compile('
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

			self::assertStringContainsString('DECLARE _v_x INT;', $statements[0]);
			self::assertStringContainsString('DECLARE _v_x_2 INT;', $statements[0]);
			self::assertStringContainsString("SET _v_x = 1;\n\t\tSET _v_n = _v_n + _v_x;", $statements[0]);
			self::assertStringContainsString("SET _v_x_2 = 2;\n\t\tSET _v_n = _v_n + _v_x_2;", $statements[0]);
		}

		/**
		 * Two cursors of the same name in sibling `if`/`else` branches are block-scoped in EQUEL but
		 * compile to two distinct `DECLARE ... CURSOR FOR` entries in the one preamble.
		 * @return void
		 */
		public function testSiblingBranchCursorsGetDistinctDeclarations(): void {
			$statements = $this->compile('
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

			self::assertStringContainsString("DECLARE _cur_c CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` = true;", $statements[0]);
			self::assertStringContainsString("DECLARE _cur_c_2 CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` = false;", $statements[0]);
		}

		/**
		 * Nested shadowing gives locals and cursors separate declarations and preserves outer references.
		 * @return void
		 */
		public function testNestedShadowingCompilesWithDistinctNames(): void {
			$sql = $this->compile('
				define function f (integer n) void {
					integer x = 1
					range of u is UserEntity
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
			')[0];

			self::assertStringContainsString('DECLARE _v_x INT;', $sql);
			self::assertStringContainsString('DECLARE _v_x_2 INT;', $sql);
			self::assertStringContainsString('DECLARE _cur_c CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`id` > 0;', $sql);
			self::assertStringContainsString('DECLARE _cur_c_2 CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`id` > 1;', $sql);
			self::assertStringContainsString("SET _v_x = 1;\n\tIF _v_n > 0 THEN\n\t\tSET _v_x_2 = 2;", $sql);
			self::assertStringContainsString('SET _v_x_2 = _v_x_2 + 1;', $sql);
			self::assertStringContainsString('OPEN _cur_c_2;', $sql);
			self::assertStringContainsString('SET _v_x = _v_x + 1;', $sql);
			self::assertStringContainsString('OPEN _cur_c;', $sql);
		}

		/**
		 * Reused nested row names read the inner cursor only inside its loop.
		 * @return void
		 */
		public function testNestedRowBindingShadowsAndRestoresOuterRow(): void {
			$sql = $this->compile('
				define function f () void {
					range of u is UserEntity
					integer x = 0
					cursor a = retrieve (u.id)
					cursor b = retrieve (u.id) where u.id > 1
					foreach (a as row) {
						x = row.id
						foreach (b as row) { x = row.id }
						x = row.id
					}
				}
			')[0];

			self::assertSame(2, substr_count($sql, 'SET _v_x = _row_a$id;'));
			self::assertSame(1, substr_count($sql, 'SET _v_x = _row_b$id;'));
			self::assertStringContainsString('FETCH _cur_a INTO _row_a$id;', $sql);
			self::assertStringContainsString('FETCH _cur_b INTO _row_b$id;', $sql);
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function rejectedRoutines(): array {
			return [
				'transaction in a function' => ['
					define function f () integer {
						range of u is UserEntity
						transaction {
							delete u where u.id = 1
						}
						return 1
					}
				', 'only supported in void functions'],
				'transaction inside a loop' => ['
					define function f () void {
						range of u is UserEntity
						cursor users = retrieve (u.id)
						foreach (users as row) { transaction { delete u where u.id = row.id } }
					}
				', 'while its cursor is open'],

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
