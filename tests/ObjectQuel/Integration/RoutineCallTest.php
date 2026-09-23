<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Calling a deployed routine from ObjectQuel queries, against the suite's MySQL connection.
	 */
	class RoutineCallTest extends TestCase {

		private string $name;

		/** @var list<string> JSON fixture files to remove */
		private array $createdFiles = [];

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		/**
		 * @return void
		 */
		protected function setUp(): void {
			$this->name = 'routine_call_' . getmypid();
			self::em()->executeQuery("define function {$this->name} (int n) integer { return n * 2 }");
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			self::em()->executeQuery("destroy function {$this->name} if exists");

			foreach ($this->createdFiles as $path) {
				@unlink($path);
				@unlink($path . '.lock');
			}
		}

		/**
		 * @param list<array<string, mixed>> $rows Fixture rows
		 * @return string Path of a new JSON source file
		 */
		private function jsonSource(array $rows): string {
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "{$this->name}_" . count($this->createdFiles) . '.json';
			file_put_contents($path, json_encode($rows));
			$this->createdFiles[] = $path;
			return $path;
		}

		/**
		 * The function runs in the target list and in the where clause.
		 * @return void
		 */
		public function testCallsInTargetListAndWhere(): void {
			$total = self::em()->executeQuery('range of u is UserEntity retrieve (n = count(u.id))');
			self::assertNotNull($total);
			self::assertGreaterThan(0, $total[0]['n']);

			$result = self::em()->executeQuery("
				range of u is UserEntity
				retrieve (u.id, doubled = (int){$this->name}(u.id)) where {$this->name}(u.id) = u.id * 2 sort by u.id
			");

			self::assertNotNull($result);
			self::assertSame($total[0]['n'], $result->count());

			foreach ($result as $row) {
				self::assertSame($row['u.id'] * 2, $row['doubled']);
			}

			$none = self::em()->executeQuery("range of u is UserEntity retrieve (u.id) where {$this->name}(u.id) = u.id * 2 + 1");
			self::assertNotNull($none);
			self::assertSame(0, $none->count());
		}

		/**
		 * @return void
		 */
		public function testRangelessRetrieveIsRejected(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'{$this->name}' is a routine call, which the database runs, but this query runs in PHP");
			self::em()->executeQuery("retrieve (n = {$this->name}(1))");
		}

		/**
		 * @return void
		 */
		public function testJsonSourceRetrieveIsRejected(): void {
			$path = $this->jsonSource([['n' => 1]]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'{$this->name}' is a routine call, which the database runs, but this query runs in PHP");
			self::em()->executeQuery("range of j is json_source('" . addslashes($path) . "') retrieve (d = {$this->name}(j.n))");
		}

		/**
		 * @return void
		 */
		public function testJsonSourceAppendIsRejected(): void {
			$path = $this->jsonSource([['n' => 1]]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'{$this->name}' is a routine call, which the database runs, but PHP writes a JSON source.");
			self::em()->executeQuery("range of j is json_source('" . addslashes($path) . "') append to j (n = {$this->name}(2))");
		}
	}
