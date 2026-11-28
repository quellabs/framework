<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use PHPUnit\Framework\Attributes\DataProvider;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * [...] character classes in string patterns match the same rows on every engine.
	 */
	class WildcardCharacterClassTest extends ObjectQuelTestCase {

		/**
		 * Seeds usernames exercising ranges, case and characters that are special in LIKE, GLOB or regex syntax.
		 * @return void
		 */
		protected function seedFixtures(): void {
			$usernames = [
				1  => 'JanA9',
				2  => 'JanB-x9',
				3  => 'JanD9',
				4  => 'jana9',
				5  => 'a]b',
				6  => 'a-b',
				7  => 'a^b',
				8  => 'a[b',
				9  => 'x7',
				10 => 'Array0',
				11 => 'a.b',
				12 => "line\nA"
			];

			foreach ($usernames as $id => $username) {
				$this->exec(
					'INSERT INTO users (id, username, password, banned) VALUES (:id, :username, :password, FALSE)',
					['id' => $id, 'username' => $username, 'password' => 'h']
				);
			}
		}

		/**
		 * Lists where clauses with the ids they match, and the extra ids they match on case-insensitive engines.
		 * @return array<string, array{string, int[], int[]}>
		 */
		public static function comparisons(): array {
			return [
				'range with star'           => ["u.username = 'Jan[A-C]*9'", [1, 2], [4]],
				'range without star'        => ["u.username = 'Jan[A-C]9'", [1], [4]],
				'listed members'            => ["u.username = 'Jan[ABD]9'", [1, 3], [4]],
				'digit range'               => ["u.username = 'x[0-9]'", [9], []],
				'class replaces exact text' => ["u.username = 'Array[0]'", [10], []],
				'escaped bracket member'    => ["u.username = 'a[\\]]b'", [5], []],
				'leading dash member'       => ["u.username = 'a[-x]b'", [6], []],
				'caret is a member'         => ["u.username = 'a[^]b'", [7], []],
				'open bracket member'       => ["u.username = 'a[\\[]b'", [8], []],
				'escaped open bracket'      => ["u.username = 'a\\[b'", [8], []],
				'all special members'       => ["u.username = 'a[\\]^-]b'", [5, 6, 7], []],
				'dot stays literal'         => ["u.username = 'a.[b]'", [11], []],
				'star spans newline'        => ["u.username = 'line*[A]'", [12], []],
				'negated'                   => ["u.username <> 'x[0-9]'", [1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12], []],
				'computed operand'          => ["concat(u.username, '!') = 'x[0-9]!'", [9], []]
			];
		}

		/**
		 * Runs each comparison and checks the matched ids; case sensitivity follows the engine's LIKE.
		 * @param string $condition Quel where condition
		 * @param int[] $expectedIds Ids matched on every engine
		 * @param int[] $caseInsensitiveIds Extra ids matched where LIKE is case-insensitive
		 * @return void
		 */
		#[DataProvider('comparisons')]
		public function testCharacterClassComparison(string $condition, array $expectedIds, array $caseInsensitiveIds): void {
			if ($this->em->getConnection()->getDatabaseType() !== 'pgsql') {
				$expectedIds = array_merge($expectedIds, $caseInsensitiveIds);
				sort($expectedIds);
			}

			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where {$condition}
				sort by u.id asc
			");

			$this->assertSame($expectedIds, array_map('intval', array_column($rows, 'u.id')));
		}

		/**
		 * Outside a comparison the brackets are plain text.
		 * @return void
		 */
		public function testClassInAssignmentStoresPlainText(): void {
			$this->em->executeQuery("range of u is App\\Entities\\UserEntity replace u (username = 'a[b]\\[') where u.id = 9");

			$rows = $this->em->getAll("range of u is App\\Entities\\UserEntity retrieve (u.username) where u.id = 9");

			$this->assertSame('a[b][', $rows[0]['u.username']);
		}
	}
