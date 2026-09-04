<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Integration coverage for QUEL's `delete <range> where ...` statement,
	 * exercised end-to-end via EntityManager::executeStatement() against the
	 * suite's shared MySQL connection — basic delete, a condition matching
	 * no rows, and the compile-time checks (missing where — parser-level,
	 * see DeleteParserTest — and wrong-entry-point rejection) documented in
	 * objectquel-delete-plan.md.
	 *
	 * Uses App\Entities\UserEntity, the same fixture entity Append/Replace
	 * tests build on.
	 */
	class DeleteTest extends ObjectQuelTestCase {

		private function seedUser(string $username, string $password): int {
			$result = $this->em->executeStatement(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => $username, 'password' => $password]
			);

			return $result->getGeneratedId();
		}

		public function testDeletesAMatchingRow(): void {
			$id = $this->seedUser('alice', 'secret');

			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				delete u where u.id = :id
			', ['id' => $id]);

			$this->assertInstanceOf(QuelResult::class, $result);
			$this->assertSame(1, $result->getAffectedRows());
			$this->assertNull($result->getGeneratedId());

			$rows = $this->em->getAll(
				'range of u is App\Entities\UserEntity retrieve (u.username) where u.id = :id',
				['id' => $id]
			);
			$this->assertCount(0, $rows);
		}

		public function testDeletesOnlyRowsMatchingTheCondition(): void {
			$keepId = $this->seedUser('bob', 'pw1');
			$removeId = $this->seedUser('carol', 'pw2');

			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				delete u where u.id = :id
			', ['id' => $removeId]);

			$this->assertSame(1, $result->getAffectedRows());

			$usernames = $this->em->getCol('range of u is App\Entities\UserEntity retrieve (u.username)');
			$this->assertSame(['bob'], $usernames);
			$this->assertNotSame(0, $keepId);
		}

		public function testConditionMatchingNoRowsAffectsZeroRows(): void {
			$this->seedUser('dave', 'pw');

			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				delete u where u.id = :id
			', ['id' => 999999]);

			$this->assertSame(0, $result->getAffectedRows());

			$usernames = $this->em->getCol('range of u is App\Entities\UserEntity retrieve (u.username)');
			$this->assertSame(['dave'], $usernames);
		}

		public function testExecuteQueryRejectsADeleteStatement(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery('
				range of u is App\Entities\UserEntity
				delete u where u.id = 1
			');
		}

		public function testExecuteStatementRejectsARetrieveStatement(): void {
			$this->expectException(QuelException::class);

			$this->em->executeStatement('range of u is App\Entities\UserEntity retrieve (u.username)');
		}
	}
