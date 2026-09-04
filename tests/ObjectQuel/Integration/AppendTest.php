<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Integration coverage for QUEL's `append to <range/entity> (...)`
	 * statement, exercised end-to-end via EntityManager::executeStatement()
	 * against the suite's shared MySQL connection — literal single-row and
	 * multi-row append, insert-from-select, and the compile-time checks
	 * (unknown property, missing required column, null into a non-nullable
	 * column) documented in objectquel-append-plan.md.
	 *
	 * Uses App\Entities\UserEntity ("users": id identity PK, username,
	 * password, banned not-null-no-default), the same fixture entity
	 * CreateTableTest/DestroyTest's sibling tests build on.
	 */
	class AppendTest extends ObjectQuelTestCase {

		public function testAppendsASingleRowAndReturnsTheGeneratedIdentity(): void {
			$result = $this->em->executeStatement(
				'append to App\Entities\UserEntity (username = :username, password = :password, banned = false)',
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

		public function testAppendsUsingADeclaredRangeAlias(): void {
			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				append to u (username = "bob", password = "pw", banned = true)
			');

			$this->assertSame(1, $result->getAffectedRows());

			$rows = $this->em->getAll('range of u is App\Entities\UserEntity retrieve (u.username) where u.banned = true');
			$this->assertCount(1, $rows);
			$this->assertSame('bob', $rows[0]['u.username']);
		}

		public function testAppendsMultipleRowsInOneStatement(): void {
			$result = $this->em->executeStatement('
				append to App\Entities\UserEntity
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
			$seeded = $this->em->executeStatement(
				'append to App\Entities\UserEntity (username = :username, password = :password, banned = false)',
				['username' => 'source-user', 'password' => 'pw']
			);

			$result = $this->em->executeStatement('
				range of dst is App\Entities\UserEntity
				range of src is App\Entities\UserEntity
				append to dst (username, password, banned) retrieve (src.username, src.password, src.banned) where src.id = :sourceId
			', ['sourceId' => $seeded->getGeneratedId()]);

			$this->assertSame(1, $result->getAffectedRows());

			$usernames = $this->em->getCol('range of u is App\Entities\UserEntity retrieve (u.username) where u.username = "source-user"');
			$this->assertCount(2, $usernames);
		}

		public function testRejectsAnUnknownProperty(): void {
			$this->expectException(QuelException::class);

			$this->em->executeStatement('append to App\Entities\UserEntity (doesNotExist = "x", password = "pw", banned = false)');
		}

		public function testRejectsAMissingRequiredColumn(): void {
			$this->expectException(QuelException::class);

			// 'password' is not-null with no default and is omitted here.
			$this->em->executeStatement('append to App\Entities\UserEntity (username = "eve", banned = false)');
		}

		public function testRejectsNullForANonNullableColumn(): void {
			$this->expectException(QuelException::class);

			$this->em->executeStatement('append to App\Entities\UserEntity (username = null, password = "pw", banned = false)');
		}

		public function testRejectsMismatchedColumnsAcrossMultipleRows(): void {
			$this->expectException(QuelException::class);

			$this->em->executeStatement('
				append to App\Entities\UserEntity
					(username = "frank", password = "pw1", banned = false),
					(username = "gina", banned = false)
			');
		}

		public function testExecuteQueryRejectsAnAppendStatement(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery('append to App\Entities\UserEntity (username = "x", password = "pw", banned = false)');
		}

		public function testExecuteStatementRejectsARetrieveStatement(): void {
			$this->expectException(QuelException::class);

			$this->em->executeStatement('range of u is App\Entities\UserEntity retrieve (u.username)');
		}
	}
