<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use PHPUnit\Framework\Attributes\DataProvider;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Wildcard comparisons: escaped * and ? match literally, and literal SQL wildcards in a pattern stay literal on every engine.
	 */
	class WildcardEscapeTest extends ObjectQuelTestCase {

		/**
		 * Seeds usernames containing characters that are wildcards in Quel or in SQL LIKE.
		 * @return void
		 */
		protected function seedFixtures(): void {
			$usernames = [
				1 => 'a*b',
				2 => 'axb',
				3 => 'a%b',
				4 => 'a_b*',
				5 => 'a\\b',
				6 => 'a?b',
				7 => 'a[b]'
			];

			foreach ($usernames as $id => $username) {
				$this->exec(
					'INSERT INTO users (id, username, password, banned) VALUES (:id, :username, :password, FALSE)',
					['id' => $id, 'username' => $username, 'password' => 'h']
				);
			}
		}

		/**
		 * Lists where clauses with the user ids they must match.
		 * @return array<string, array{string, int[]}>
		 */
		public static function comparisons(): array {
			return [
				'unescaped star is a wildcard'        => ["u.username = 'a*b'", [1, 2, 3, 5, 6]],
				'escaped star is literal'             => ["u.username = 'a\\*b'", [1]],
				'escaped question mark is literal'    => ['u.username = "a\\?b"', [6]],
				'escaped star negated'                => ["u.username <> 'a\\*b'", [2, 3, 4, 5, 6, 7]],
				'partly escaped keeps other wildcard' => ["u.username = '?_b\\*'", [4]],
				'partly escaped star with wildcard'   => ["u.username = '?\\*b'", [1]],
				'literal percent in pattern'          => ["u.username = 'a%*'", [3]],
				'literal underscore in pattern'       => ["u.username = 'a_b?'", [4]],
				'literal backslash in pattern'        => ["u.username = 'a\\\\*'", [5]],
				'literal brackets in pattern'         => ["u.username = 'a\\[b?'", [7]],
				'unescaped wildcards negated'         => ["u.username <> 'a?b'", [4, 7]]
			];
		}

		/**
		 * Runs each comparison against the database and checks the matched ids.
		 * @param string $condition Quel where condition
		 * @param int[] $expectedIds Ids that must match, ascending
		 * @return void
		 */
		#[DataProvider('comparisons')]
		public function testWildcardComparison(string $condition, array $expectedIds): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where {$condition}
				sort by u.id asc
			");

			$this->assertSame($expectedIds, array_map('intval', array_column($rows, 'u.id')));
		}

		/**
		 * An escaped wildcard in an assignment stores the plain character.
		 * @return void
		 */
		public function testEscapedWildcardInAssignmentStoresPlainCharacter(): void {
			$this->em->executeQuery("range of u is App\\Entities\\UserEntity replace u (username = 'H\\*?') where u.id = 2");

			$rows = $this->em->getAll("range of u is App\\Entities\\UserEntity retrieve (u.username) where u.id = 2");

			$this->assertSame('H*?', $rows[0]['u.username']);
		}
	}
