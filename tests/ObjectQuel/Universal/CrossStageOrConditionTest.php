<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * A WHERE clause with an OR spanning a database range and a JSON-source
	 * range decomposes into separate ExecutionStages (StageFactory) that get
	 * joined back together (PlanExecutor). ConditionFilter used to collapse
	 * such an OR to whichever branch it could evaluate for a given stage —
	 * sound for AND, not for OR, since `A or B` is not implied by either `A`
	 * or `B` alone. That silently dropped rows that only satisfied the
	 * branch referencing the *other* stage's range, with no error at all.
	 *
	 * Self-contained: writes its own JSON fixture and removes its own rows
	 * from `users`, like JsonSourceRangeAppendTest.
	 */
	class CrossStageOrConditionTest extends TestCase {

		private static int $fileCounter = 0;

		/** @var string[] Fixture files created by the current test, removed in tearDown() */
		private array $createdFiles = [];

		/** @var int[] user ids inserted by the current test, removed in tearDown() */
		private array $createdUserIds = [];

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function tearDown(): void {
			foreach ($this->createdFiles as $path) {
				@unlink($path);
			}

			$this->createdFiles = [];

			$connection = self::em()->getConnection();

			foreach ($this->createdUserIds as $id) {
				$connection->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
			}

			$this->createdUserIds = [];
		}

		private function nextJsonFile(): string {
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'csor_test_' . getmypid() . '_' . (++self::$fileCounter) . '.json';
			$this->createdFiles[] = $path;
			return $path;
		}

		private function insertUser(string $username): int {
			$connection = self::em()->getConnection();
			$connection->execute(
				'INSERT INTO users (username, password, banned) VALUES (:username, :password, :banned)',
				['username' => $username, 'password' => 'pw', 'banned' => false]
			);

			$row = $connection->execute('SELECT id FROM users WHERE username = :username', ['username' => $username])->fetch('assoc');
			$this->assertIsArray($row);

			$id = (int)$row['id'];
			$this->createdUserIds[] = $id;
			return $id;
		}

		/**
		 * `where u.id = :matchId or j.flag = 1` — correct answer is the full
		 * cross join of u (2 rows) and j (2 rows), filtered by the OR, which
		 * is 3 of the 4 pairs. Before the fix, both stages independently
		 * dropped the branch they couldn't evaluate, producing only the one
		 * pair that happened to satisfy both branches at once.
		 */
		public function testOrAcrossADatabaseRangeAndAJsonSourceRangeKeepsEveryMatchingPair(): void {
			$pid = getmypid();
			$matchId = $this->insertUser("csor_match_{$pid}");
			$otherId = $this->insertUser("csor_other_{$pid}");

			$path = $this->nextJsonFile();
			file_put_contents($path, json_encode([
				['tag' => 'nomatch', 'flag' => 0],
				['tag' => 'flagset', 'flag' => 1],
			]));

			// The `u.id = :otherId` branch scopes 'u' down to just this test's own
			// two rows (the shared `users` table otherwise has other rows from
			// elsewhere in the suite) without affecting the cross-stage OR under
			// test: it's AND-ed alongside it, and ORing in the row's own id is a
			// no-op for a row that already matches its own id.
			$query = "
				range of u is App\\Entities\\UserEntity
				range of j is json_source('" . addslashes($path) . "')
				retrieve (u.id, j.tag)
				where (u.id = {$matchId} or u.id = {$otherId}) and (u.id = {$matchId} or j.flag = 1)
			";

			$rows = self::em()->executeQuery($query);
			$this->assertNotNull($rows);

			$pairs = [];

			foreach ($rows as $row) {
				$pairs[] = [(int)$row['u.id'], $row['j.tag']];
			}

			sort($pairs);

			$this->assertCount(3, $pairs, 'Expected every (u,j) pair satisfying u.id = matchId or j.flag = 1');
			$this->assertContains([$matchId, 'nomatch'], $pairs, "matchId pairs with every j row regardless of flag");
			$this->assertContains([$matchId, 'flagset'], $pairs, "matchId pairs with every j row regardless of flag");
			$this->assertContains([$otherId, 'flagset'], $pairs, "otherId only pairs with the flagged j row");
		}

		/**
		 * The AND case must still work exactly as before — each stage still
		 * prunes down before the join runs, not just via the final recheck.
		 */
		public function testAndAcrossADatabaseRangeAndAJsonSourceRangeStillWorks(): void {
			$pid = getmypid();
			$matchId = $this->insertUser("csor_and_match_{$pid}");
			$this->insertUser("csor_and_other_{$pid}");

			$path = $this->nextJsonFile();
			file_put_contents($path, json_encode([
				['tag' => 'nomatch', 'flag' => 0],
				['tag' => 'flagset', 'flag' => 1],
			]));

			$query = "
				range of u is App\\Entities\\UserEntity
				range of j is json_source('" . addslashes($path) . "')
				retrieve (u.id, j.tag)
				where u.id = {$matchId} and j.flag = 1
			";

			$rows = self::em()->executeQuery($query);
			$this->assertNotNull($rows);

			$this->assertCount(1, $rows);
			$this->assertSame($matchId, (int)$rows[0]['u.id']);
			$this->assertSame('flagset', $rows[0]['j.tag']);
		}
	}
