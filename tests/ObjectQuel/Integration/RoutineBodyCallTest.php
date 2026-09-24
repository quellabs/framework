<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Routines that call routines, against the suite's MySQL connection.
	 */
	class RoutineBodyCallTest extends TestCase {

		/** @var string Prefix of the routines a test defines */
		private string $prefix;

		/** @var list<string> Routines a test defined, destroyed afterwards */
		private array $routines = [];

		/** @var int|null User row a test seeded, deleted afterwards */
		private ?int $userId = null;

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
		 * Defines a routine and records it for cleanup.
		 * @param string $suffix Name suffix, appended to the test's prefix
		 * @param string $definition Everything after the name: parameters, return type and body
		 * @return string The routine's name
		 */
		private function define(string $suffix, string $definition): string {
			$name = "{$this->prefix}_{$suffix}";
			$this->routines[] = $name;
			self::em()->executeQuery("define function {$name} {$definition}");
			return $name;
		}

		/**
		 * Seeds a user row, deleted in tearDown.
		 * @return int The new user's id
		 */
		private function seedUser(): int {
			$seeded = self::em()->executeQuery(
				'range of u is UserEntity append to u (username = :username, password = :password, banned = false)',
				['username' => $this->prefix, 'password' => 'pw']
			);

			self::assertNotNull($seeded);
			$this->userId = (int)$seeded->getGeneratedId();
			return $this->userId;
		}

		/**
		 * @param int $id User id
		 * @return mixed Username of the user
		 */
		private function username(int $id): mixed {
			$result = self::em()->executeQuery('range of u is UserEntity retrieve (u.username) where u.id = :id', ['id' => $id]);
			self::assertNotNull($result);
			self::assertCount(1, $result);
			return $result[0]['u.username'];
		}

		/**
		 * @return void
		 */
		protected function setUp(): void {
			$this->prefix = 'body_call_' . getmypid();
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			foreach (array_reverse($this->routines) as $name) {
				self::em()->executeQuery("destroy function {$name} if exists");
			}

			if ($this->userId !== null) {
				self::em()->executeQuery('range of u is UserEntity delete u where u.id = :id', ['id' => $this->userId]);
			}
		}

		/**
		 * @return void
		 */
		public function testFunctionCallsFunction(): void {
			$double = $this->define('double', '(int n) integer { return n * 2 }');
			$outer = $this->define('outer', "(int n) integer { integer x = {$double}(n) + 1 return x }");

			$result = self::em()->executeQuery("{$outer}(:n)", ['n' => 21]);

			self::assertNotNull($result);
			self::assertSame([[$outer => 43]], iterator_to_array($result));
		}

		/**
		 * @return void
		 */
		public function testProcedureCallsProcedure(): void {
			$rename = $this->define('rename', '(int uid, string who) void {
				range of u is UserEntity
				replace u (username = who) where u.id = uid
			}');

			$outer = $this->define('outer', "(int uid) void { string who = \"nested\" {$rename}(uid, who) }");
			$id = $this->seedUser();

			self::assertNull(self::em()->executeQuery("{$outer}(:id)", ['id' => $id]));
			self::assertSame('nested', $this->username($id));
		}

		/**
		 * MySQL lets a function call a procedure; SQL Server doesn't.
		 * @return void
		 */
		public function testFunctionCallsProcedure(): void {
			$rename = $this->define('rename', '(int uid) void {
				range of u is UserEntity
				replace u (username = "from function") where u.id = uid
			}');

			$outer = $this->define('outer', "(int uid) integer { {$rename}(uid) return 1 }");
			$id = $this->seedUser();

			$result = self::em()->executeQuery("{$outer}(:id)", ['id' => $id]);

			self::assertNotNull($result);
			self::assertSame([[$outer => 1]], iterator_to_array($result));
			self::assertSame('from function', $this->username($id));
		}

		/**
		 * @return void
		 */
		public function testCallInEmbeddedQueryAndCursorLoop(): void {
			$suffix = $this->define('suffix', '(string s) string { return concat(s, "!") }');
			$rename = $this->define('rename', '(int uid, string who) void {
				range of u is UserEntity
				replace u (username = who) where u.id = uid
			}');

			$outer = $this->define('outer', "(int uid) void {
				range of u is UserEntity
				cursor users = retrieve (u.id, name = (string){$suffix}(u.username)) where u.id = uid
				foreach users {
					{$rename}(users.id, users.name)
				}
			}");

			$id = $this->seedUser();

			self::assertNull(self::em()->executeQuery("{$outer}(:id)", ['id' => $id]));
			self::assertSame("{$this->prefix}!", $this->username($id));
		}
	}
