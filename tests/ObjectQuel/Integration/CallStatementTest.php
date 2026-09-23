<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * The `call name(args)` statement against the suite's MySQL connection.
	 */
	class CallStatementTest extends TestCase {

		private string $name;

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
			$this->name = 'call_statement_' . getmypid();
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			self::em()->executeQuery("destroy function {$this->name} if exists");

			if ($this->userId !== null) {
				self::em()->executeQuery('range of u is UserEntity delete u where u.id = :id', ['id' => $this->userId]);
			}
		}

		/**
		 * @return void
		 */
		public function testFunctionYieldsOneRow(): void {
			self::em()->executeQuery("define function {$this->name} (int n) integer { return n * 2 }");

			$result = self::em()->executeQuery("call {$this->name}(:n)", ['n' => 21]);

			self::assertNotNull($result);
			self::assertSame([[$this->name => 42]], iterator_to_array($result));
		}

		/**
		 * The procedure runs, yields null and leaves the connection usable.
		 * @return void
		 */
		public function testProcedureYieldsNull(): void {
			self::em()->executeQuery("
				define function {$this->name} (int uid, string who) void {
					range of u is UserEntity
					replace u (username = who) where u.id = uid
				}
			");

			$seeded = self::em()->executeQuery(
				'range of u is UserEntity append to u (username = :username, password = :password, banned = false)',
				['username' => $this->name, 'password' => 'pw']
			);
			self::assertNotNull($seeded);
			$this->userId = (int)$seeded->getGeneratedId();

			self::assertNull(self::em()->executeQuery("call {$this->name}(:id, :who)", ['id' => $this->userId, 'who' => 'called']));
			self::assertSame('called', $this->username($this->userId));
		}

		/**
		 * @return void
		 */
		public function testMissingRoutineIsRejected(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Can't call '{$this->name}': no routine by that name exists.");
			self::em()->executeQuery("call {$this->name}()");
		}

		/**
		 * MySQL keeps functions and procedures apart, so one of each can share a name.
		 * @return void
		 */
		public function testNameOfBothKindsIsRejected(): void {
			$connection = self::em()->getConnection();
			self::assertNotNull($connection->execute("CREATE FUNCTION `{$this->name}`() RETURNS INT DETERMINISTIC RETURN 1"));
			self::assertNotNull($connection->execute("CREATE PROCEDURE `{$this->name}`() BEGIN END"));

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Can't call '{$this->name}': both a function and a procedure have that name.");
			self::em()->executeQuery("call {$this->name}()");
		}
	}
