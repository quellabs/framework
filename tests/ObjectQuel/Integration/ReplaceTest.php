<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Integration coverage for QUEL's `replace <range> (...) where ...`
	 * statement, exercised end-to-end via EntityManager::executeStatement()
	 * against the suite's shared MySQL connection — basic replace, multiple
	 * assignments, a condition matching no rows, and the compile-time checks
	 * (unknown property, type mismatch, missing where) documented in
	 * objectquel-replace-plan.md.
	 *
	 * Uses App\Entities\UserEntity, the same fixture entity AppendTest builds
	 * on. UserEntity has no @Orm\Version column — that bump behavior is
	 * covered in isolation by
	 * tests/Unit/Persistence/VersionValueHandlerTest.php instead.
	 */
	class ReplaceTest extends ObjectQuelTestCase {

		private function seedUser(string $username, string $password, bool $banned = false): int {
			$result = $this->em->executeStatement(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = :banned)',
				['username' => $username, 'password' => $password, 'banned' => $banned]
			);

			return $result->getGeneratedId();
		}

		public function testReplacesAMatchingRow(): void {
			$id = $this->seedUser('alice', 'secret');

			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				replace u (password = :password) where u.id = :id
			', ['password' => 'newpass', 'id' => $id]);

			$this->assertInstanceOf(QuelResult::class, $result);
			$this->assertSame(1, $result->getAffectedRows());
			$this->assertNull($result->getGeneratedId());

			$rows = $this->em->getAll(
				'range of u is App\Entities\UserEntity retrieve (u.password) where u.id = :id',
				['id' => $id]
			);
			$this->assertSame('newpass', $rows[0]['u.password']);
		}

		public function testReplacesMultipleAssignmentsInOneStatement(): void {
			$id = $this->seedUser('bob', 'pw', false);

			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				replace u (password = :password, banned = true) where u.id = :id
			', ['password' => 'pw2', 'id' => $id]);

			$this->assertSame(1, $result->getAffectedRows());

			$rows = $this->em->getAll(
				'range of u is App\Entities\UserEntity retrieve (u.password, u.banned) where u.id = :id',
				['id' => $id]
			);
			$this->assertSame('pw2', $rows[0]['u.password']);
			$this->assertTrue((bool)$rows[0]['u.banned']);
		}

		public function testAssignmentValueCanReferenceAnotherColumnOfTheSameRow(): void {
			$id = $this->seedUser('carol', 'pw');

			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				replace u (username = concat(u.username, "!")) where u.id = :id
			', ['id' => $id]);

			$this->assertSame(1, $result->getAffectedRows());

			$rows = $this->em->getAll(
				'range of u is App\Entities\UserEntity retrieve (u.username) where u.id = :id',
				['id' => $id]
			);
			$this->assertSame('carol!', $rows[0]['u.username']);
		}

		public function testConditionMatchingNoRowsAffectsZeroRows(): void {
			$this->seedUser('dave', 'pw');

			$result = $this->em->executeStatement('
				range of u is App\Entities\UserEntity
				replace u (password = "x") where u.id = :id
			', ['id' => 999999]);

			$this->assertSame(0, $result->getAffectedRows());
		}

		public function testRejectsAnUnknownProperty(): void {
			$id = $this->seedUser('eve', 'pw');

			$this->expectException(QuelException::class);

			$this->em->executeStatement('
				range of u is App\Entities\UserEntity
				replace u (doesNotExist = "x") where u.id = :id
			', ['id' => $id]);
		}

		public function testRejectsNullForANonNullableColumn(): void {
			$id = $this->seedUser('frank', 'pw');

			$this->expectException(QuelException::class);

			$this->em->executeStatement('
				range of u is App\Entities\UserEntity
				replace u (password = null) where u.id = :id
			', ['id' => $id]);
		}

		public function testRejectsATypeMismatchedLiteralValue(): void {
			$id = $this->seedUser('gina', 'pw');

			$this->expectException(QuelException::class);

			// banned is a boolean column; a string literal is a static mismatch.
			$this->em->executeStatement('
				range of u is App\Entities\UserEntity
				replace u (banned = "yes") where u.id = :id
			', ['id' => $id]);
		}

		public function testExecuteQueryRejectsAReplaceStatement(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery('
				range of u is App\Entities\UserEntity
				replace u (password = "x") where u.id = 1
			');
		}
	}
