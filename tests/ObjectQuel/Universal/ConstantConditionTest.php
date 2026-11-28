<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use PHPUnit\Framework\Attributes\DataProvider;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Where conditions that reference no range still filter the rows a database query returns.
	 */
	class ConstantConditionTest extends ObjectQuelTestCase {

		/**
		 * Seeds one username that matches 'H*' and one that doesn't.
		 * @return void
		 */
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'Hank', 'h', FALSE)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'h', FALSE)");
		}

		/**
		 * Lists where clauses with the user ids they must match.
		 * @return array<string, array{string, int[]}>
		 */
		public static function conditions(): array {
			return [
				'false constant alone'           => ['1 = 2', []],
				'true constant alone'            => ['2 = 2', [1, 2]],
				'false constant and wildcard'    => ['u.username = "H*" and 1 = 2', []],
				'false constant and comparison'  => ['u.id > 0 and 1 = 2', []],
				'false constant or wildcard'     => ['u.username = "H*" or 1 = 2', [1]],
				'true constant or wildcard'      => ['u.username = "H*" or 2 = 2', [1, 2]]
			];
		}

		/**
		 * Runs each condition against the database and checks the matched ids.
		 * @param string $condition Quel where condition
		 * @param int[] $expectedIds Ids that must match, ascending
		 * @return void
		 */
		#[DataProvider('conditions')]
		public function testConstantCondition(string $condition, array $expectedIds): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where {$condition}
				sort by u.id asc
			");

			$this->assertSame($expectedIds, array_map('intval', array_column($rows, 'u.id')));
		}
	}
