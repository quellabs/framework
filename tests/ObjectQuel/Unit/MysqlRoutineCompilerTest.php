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
				range of u is UserEntity
				define function find_user (string who, int minId) string {
					string found = ""
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
				range of u is UserEntity
				define function count_users (int minId) integer {
					integer total = 0
					boolean found = false
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
				range of u is UserEntity
				range of p is PostEntity
				define function purge (string who) void {
					cursor users = retrieve (u.id, u.username) where u.username = who
					foreach (users as row) {
						delete p where p.userId = 5
						replace u (banned = true) where u.id = row.id
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
				CREATE PROCEDURE `purge`(_v_who VARCHAR(255))
				MODIFIES SQL DATA
				COMMENT '{"objectQuel":1,"returnType":"void","atomic":true,"parameters":[{"kind":"scalar","type":"string"}],"safety":{"calls":[],"reads":[],"writes":["posts","users"],"features":[]}}'
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
		 * `@ignoreSoftDelete true` ahead of `define function` covers the whole routine body:
		 * `delete` issues a real DELETE instead of the soft-delete UPDATE, and `retrieve`
		 * doesn't get the automatic `deleted_at IS NULL` filter — contrast with
		 * testProcedureStatements(), whose identical body (no directive) keeps both.
		 * @return void
		 */
		public function testIgnoreSoftDeleteDirectiveAppliesToWholeRoutineBody(): void {
			$statements = $this->compile('
				@ignoreSoftDelete true
				range of u is UserEntity
				range of p is PostEntity
				define function purge (string who) void {
					cursor users = retrieve (u.id, u.username) where u.username = who
					foreach (users as row) {
						delete p where p.userId = 5
					}
					retrieve (p.title) where p.userId = 5
				}
			');

			self::assertStringContainsString('DELETE FROM `posts` as `p` WHERE `p`.`user_id` = 5;', $statements[0]);
			self::assertStringContainsString('FROM `posts` as `p` WHERE `p`.`user_id` = 5) AS `_discard`;', $statements[0]);
			self::assertStringNotContainsString('deleted_at', $statements[0]);
		}

		/**
		 * MySQL procedures don't support RETURN at all (function-only); a bare `return`
		 * (void routines only) instead labels the routine body and lowers to `LEAVE` it.
		 * @return void
		 */
		public function testBareReturnCompilesToLeaveLabeledRoutine(): void {
			$statements = $this->compile('
				range of u is UserEntity
				define function maybe_ban (integer targetId) void {
					if (targetId <= 0) {
						return
					}
					replace u (banned = true) where u.id = targetId
				}
			');

			self::assertSame(<<<'SQL'
				CREATE PROCEDURE `maybe_ban`(_v_targetId INT)
				MODIFIES SQL DATA
				COMMENT '{"objectQuel":1,"returnType":"void","atomic":false,"parameters":[{"kind":"scalar","type":"integer"}],"safety":{"calls":[],"reads":[],"writes":["users"],"features":[]}}'
				_equel_routine: BEGIN
					IF _v_targetId <= 0 THEN
						LEAVE _equel_routine;
					END IF;
					UPDATE `users` as `u` SET `u`.`banned` = true WHERE `u`.`id` = _v_targetId;
				END _equel_routine
				SQL, $statements[0]);
		}

		/**
		 * A bare `return` inside a `foreach` needs no CLOSE: MySQL closes a cursor when its
		 * declaring block exits, same as leaving the labeled routine body via LEAVE.
		 * @return void
		 */
		public function testBareReturnInsideLoopNeedsNoCursorClose(): void {
			$statements = $this->compile('
				range of u is UserEntity
				define function stop_early () void {
					cursor users = retrieve (u.id) where u.banned = false
					foreach (users as row) {
						if (row.id > 100) {
							return
						}
						replace u (banned = true) where u.id = row.id
					}
				}
			');

			self::assertStringContainsString("IF _row_users\$id > 100 THEN\n\t\t\tLEAVE _equel_routine;\n\t\tEND IF;", $statements[0]);
			self::assertStringNotContainsString('CLOSE _cur_users;' . "\n\t\tLEAVE", $statements[0]);
		}

		/**
		 * A routine with no bare `return` keeps its unlabeled body unchanged.
		 * @return void
		 */
		public function testVoidRoutineWithoutBareReturnStaysUnlabeled(): void {
			$statements = $this->compile('
				range of u is UserEntity
				define function ban_all () void {
					replace u (banned = true) where u.id > 0
				}
			');

			self::assertStringStartsWith("CREATE PROCEDURE `ban_all`()\nMODIFIES SQL DATA\n", $statements[0]);
			self::assertStringContainsString("\nBEGIN\n", $statements[0]);
			self::assertStringEndsWith("END", $statements[0]);
			self::assertStringNotContainsString('_equel_routine', $statements[0]);
		}

		/**
		 * A write inside a loop referencing the row's own fetched field compiles to the
		 * variable that field was fetched into.
		 * @return void
		 */
		public function testWriteInsideLoopReferencesRowField(): void {
			$statements = $this->compile('
				range of u is UserEntity
				define function unban () void {
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
				range of u is UserEntity
				define function unban () void {
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
			$statements = $this->compile('range of u is UserEntity define function f () void {
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
				range of u is UserEntity
				range of p is PostEntity
				define function f () void {
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
				COMMENT '{"objectQuel":1,"returnType":"void","atomic":false,"parameters":[{"kind":"scalar","type":"integer"}],"safety":{"calls":[],"reads":[],"writes":["users"],"features":[]}}'
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
				range of u is UserEntity
				define function nested (integer n) integer {
					integer total = 0
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

			self::assertStringContainsString("DECLARE _cur_c CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` = true;", $statements[0]);
			self::assertStringContainsString("DECLARE _cur_c_2 CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` = false;", $statements[0]);
		}

		/**
		 * `c = retrieve (...)` rebinds a cursor to a fresh SQL cursor, declared alongside the
		 * original; the rebind statement itself compiles to no SQL of its own, and each
		 * `foreach` opens whichever cursor was current at that point in the source.
		 * @return void
		 */
		public function testCursorRebindGetsItsOwnDeclarationAndNoStatement(): void {
			$sql = $this->compile('
				range of u is UserEntity
				define function f () void {
					cursor c = retrieve (u.id) where u.banned = false
					foreach (c as row) { }
					c = retrieve (u.id) where u.banned = true
					foreach (c as row) { }
				}
			');

			self::assertStringContainsString("DECLARE _cur_c CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` = false;", $sql[0]);
			self::assertStringContainsString("DECLARE _cur_c_2 CURSOR FOR SELECT `u`.`id` as `id` FROM `users` as `u` WHERE `u`.`banned` = true;", $sql[0]);
			self::assertStringContainsString("OPEN _cur_c;", $sql[0]);
			self::assertStringContainsString("OPEN _cur_c_2;", $sql[0]);
			self::assertSame(1, substr_count($sql[0], 'banned` = true'), 'the rebind must not emit a statement of its own');
		}

		/**
		 * Nested shadowing gives locals and cursors separate declarations and preserves outer references.
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
				'atomic in a function' => ['
					range of u is UserEntity
					define function f () integer {
						atomic {
							delete u where u.id = 1
						}
						return 1
					}
				', 'only supported in void or trigger functions'],
				'atomic inside a loop' => ['
					range of u is UserEntity
					define function f () void {
						cursor users = retrieve (u.id)
						foreach (users as row) { atomic { delete u where u.id = row.id } }
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

		/**
		 * The routine's JSON metadata (RoutineMetadataTest) is embedded as the procedure's
		 * COMMENT, inline in the single CREATE statement MySQL/MariaDB produce.
		 * @return void
		 */
		public function testCommentCarriesRoutineMetadata(): void {
			$statements = $this->compile('
				range of u is UserEntity
				define function ban_all () void { replace u (banned = true) where u.id > 0 }
			');

			self::assertCount(1, $statements);
			self::assertMatchesRegularExpression('/COMMENT \'(\{.*\})\'/', $statements[0], 'metadata JSON must be a single-quoted COMMENT');
			preg_match('/COMMENT \'(\{.*\})\'/', $statements[0], $match);
			$metadata = json_decode($match[1], true);
			self::assertSame('void', $metadata['returnType']);
			self::assertSame(['users'], $metadata['safety']['writes']);
		}
	}
