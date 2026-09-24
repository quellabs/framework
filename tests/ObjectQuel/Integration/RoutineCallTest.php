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
			self::em()->executeQuery("destroy function {$this->name}_flag if exists");
			self::em()->executeQuery("destroy function {$this->name}_echo if exists");
			self::em()->executeQuery("destroy function {$this->name}_proc if exists");

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
		 * A standalone call converts the value to the function's return type.
		 * @return void
		 */
		public function testStandaloneCallConvertsToReturnType(): void {
			self::em()->executeQuery("define function {$this->name}_flag (int n) boolean { if (n > 0) { return true } return false }");
			self::em()->executeQuery("define function {$this->name}_echo (datetime d) datetime { return d }");

			$flag = self::em()->executeQuery("{$this->name}_flag(:n)", ['n' => 5]);
			$echo = self::em()->executeQuery("{$this->name}_echo(:d)", ['d' => '2026-09-24 10:30:00']);

			self::assertNotNull($flag);
			self::assertNotNull($echo);
			self::assertSame([["{$this->name}_flag" => true]], iterator_to_array($flag));

			$value = iterator_to_array($echo)[0]["{$this->name}_echo"];
			self::assertInstanceOf(\DateTime::class, $value);
			self::assertSame('2026-09-24 10:30:00', $value->format('Y-m-d H:i:s'));
		}

		/**
		 * A call in the target list is converted like a column of the function's return type.
		 * @return void
		 */
		public function testCallInTargetListConvertsToReturnType(): void {
			self::em()->executeQuery("define function {$this->name}_flag (int n) boolean { if (n > 0) { return true } return false }");

			$result = self::em()->executeQuery("range of u is UserEntity retrieve (u.id, flag = {$this->name}_flag(u.id)) sort by u.id");

			self::assertNotNull($result);
			self::assertGreaterThan(0, $result->count());

			foreach ($result as $row) {
				self::assertSame($row['u.id'] > 0, $row['flag']);
			}
		}

		/**
		 * @return void
		 */
		public function testProcedureInQueryIsRejected(): void {
			self::em()->executeQuery("define function {$this->name}_proc (int n) void { }");

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'{$this->name}_proc' is a procedure, which returns no value; only a function can be called inside a query.");
			self::em()->executeQuery("range of u is UserEntity retrieve (u.id) where {$this->name}_proc(u.id) = 1");
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
