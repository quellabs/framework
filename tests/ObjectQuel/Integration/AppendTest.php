<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHubLocator;
	use Quellabs\SignalHub\Slot;

	/**
	 * Integration coverage for QUEL's `append to <range> (...)` statement,
	 * exercised end-to-end via EntityManager::executeQuery() against the
	 * suite's shared MySQL connection — literal single-row and multi-row
	 * append, insert-from-select, and the compile-time checks (unknown
	 * property, missing required column, null into a non-nullable column)
	 * documented in objectquel-append-plan.md.
	 *
	 * Uses App\Entities\UserEntity ("users": id identity PK, username,
	 * password, banned not-null-no-default), the same fixture entity
	 * CreateTableTest/DestroyTest's sibling tests build on. The target must
	 * always be a declared range — there's no bare-entity-name form.
	 */
	class AppendTest extends ObjectQuelTestCase {

		public function testAppendsASingleRowAndReturnsTheGeneratedIdentity(): void {
			$result = $this->em->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'alice', 'password' => 'secret']
			);

			$this->assertInstanceOf(QuelResult::class, $result);
			$this->assertSame(1, $result->getAffectedRows());
			$this->assertIsInt($result->getGeneratedId());

			$row = $this->em->getAll(
				'range of u is App\Entities\UserEntity retrieve (u.username, u.password, u.banned) where u.id = :id',
				['id' => $result->getGeneratedId()]
			);

			$this->assertCount(1, $row);
			$this->assertSame('alice', $row[0]['u.username']);
			$this->assertSame('secret', $row[0]['u.password']);
			$this->assertFalse((bool)$row[0]['u.banned']);
		}

		public function testAppendsMultipleRowsInOneStatement(): void {
			$result = $this->em->executeQuery('
				range of u is App\Entities\UserEntity
				append to u
					(username = "carol", password = "pw1", banned = false),
					(username = "dave", password = "pw2", banned = false)
			');

			$this->assertSame(2, $result->getAffectedRows());
			// Which row an identity value would even refer to is ambiguous for
			// a multi-row append, so it's left null rather than guessed at.
			$this->assertNull($result->getGeneratedId());

			$usernames = $this->em->getCol('range of u is App\Entities\UserEntity retrieve (u.username) sort by u.username asc');
			$this->assertSame(['carol', 'dave'], $usernames);
		}

		public function testInsertFromSelectCopiesRowsFromAnotherRange(): void {
			$seeded = $this->em->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'source-user', 'password' => 'pw']
			);

			$result = $this->em->executeQuery('
				range of dst is App\Entities\UserEntity
				range of src is App\Entities\UserEntity
				append to dst (username, password, banned) retrieve (src.username, src.password, src.banned) where src.id = :sourceId
			', ['sourceId' => $seeded->getGeneratedId()]);

			$this->assertSame(1, $result->getAffectedRows());

			$usernames = $this->em->getCol('range of u is App\Entities\UserEntity retrieve (u.username) where u.username = "source-user"');
			$this->assertCount(2, $usernames);
		}

		/**
		 * Regression test: EntityManager::executeQuery() unconditionally calls
		 * explainQuery() in development mode to build the debug signal's query
		 * plan. explainQuery() used to reject write-verb statements outright
		 * (replaying them via the retrieve pipeline's dry-run would re-execute
		 * the write for real), so a naive call used to bubble that
		 * QuelException up and fail the append outright. explainQuery() now
		 * compiles append/replace/delete/DDL statements to SQL directly
		 * (AppendExecutor::compileSql() and friends) instead of replaying
		 * them, so the debug signal carries the real compiled SQL.
		 */
		public function testAppendSucceedsWithDevelopmentModeDebugSignalEnabled(): void {
			$configProperty = new \ReflectionProperty($this->em, 'configuration');
			$configProperty->setAccessible(true);
			$configuration = $configProperty->getValue($this->em);
			$configuration->setDevelopmentMode(true);

			$signal = SignalHubLocator::getInstance()->getSignal('debug.database.query');
			$captured = null;
			$slot = new Slot(function (array $payload) use (&$captured): void {
				$captured = $payload;
			});
			$signal->connect($slot);

			try {
				$result = $this->em->executeQuery(
					'range of u is App\Entities\UserEntity
					append to u (username = :username, password = :password, banned = false)',
					['username' => 'heidi', 'password' => 'secret']
				);

				$this->assertInstanceOf(QuelResult::class, $result);
				$this->assertSame(1, $result->getAffectedRows());
				$this->assertNotNull($captured);
				// Write-verb statements bypass the optimizer/planner pipeline
				// entirely, so there are no planning notes — only the compiled SQL.
				$this->assertSame([], $captured['query_plan']->getNotes());
				$this->assertCount(1, $captured['query_plan']->getSql());
				$this->assertStringContainsString('INSERT INTO', $captured['query_plan']->getSql()[0]);
				$this->assertStringContainsString('`users`', $captured['query_plan']->getSql()[0]);
			} finally {
				$signal->disconnect($slot);
				$configuration->setDevelopmentMode(false);
			}
		}

		public function testRejectsAnUnknownProperty(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery('
				range of u is App\Entities\UserEntity
				append to u (doesNotExist = "x", password = "pw", banned = false)
			');
		}

		public function testRejectsAMissingRequiredColumn(): void {
			$this->expectException(QuelException::class);

			// 'password' is not-null with no default and is omitted here.
			$this->em->executeQuery('
				range of u is App\Entities\UserEntity
				append to u (username = "eve", banned = false)
			');
		}

		public function testRejectsNullForANonNullableColumn(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery('
				range of u is App\Entities\UserEntity
				append to u (username = null, password = "pw", banned = false)
			');
		}

		public function testRejectsMismatchedColumnsAcrossMultipleRows(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery('
				range of u is App\Entities\UserEntity
				append to u
					(username = "frank", password = "pw1", banned = false),
					(username = "gina", banned = false)
			');
		}

		public function testRejectsATargetThatIsNotADeclaredRange(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery('append to App\Entities\UserEntity (username = "x", password = "pw", banned = false)');
		}

	}
