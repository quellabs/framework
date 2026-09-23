<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDelete;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLReplace;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Both sides of a datetime comparison are Unix timestamps: columns, date strings and parameters,
	 * in retrieve, replace and delete alike.
	 */
	class DateTimeComparisonTest extends TestCase {

		/**
		 * @return int 2099-01-01 00:00:00 as a Unix timestamp, read in PHP's time zone like the compiler does
		 */
		private static function year2099(): int {
			return (new \DateTime('2099-01-01 00:00:00'))->getTimestamp();
		}

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		/**
		 * @param string $query QUEL delete statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string The compiled MySQL statement
		 */
		private function compileDelete(string $query, array &$parameters = []): string {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstDelete::class, $ast);
			return (new QuelToSQLDelete($this->em()->getEntityStore(), new FakePlatformCapabilities('mysql')))->convertToSQL($ast, $parameters);
		}

		/**
		 * @param string $query QUEL replace statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string The compiled MySQL statement
		 */
		private function compileReplace(string $query, array &$parameters = []): string {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstReplace::class, $ast);
			$compiler = new QuelToSQLReplace($this->em()->getEntityStore(), new FakePlatformCapabilities('mysql'), $this->em()->getUnitOfWork()->getVersionValueHandler());
			return $compiler->convertToSQL($ast, $parameters);
		}

		/**
		 * @return void
		 */
		public function testDeleteComparesDateArithmeticWithTheColumnAsATimestamp(): void {
			$sql = $this->compileDelete('range of p is PostEntity delete p where p.createdAt < date("now") - date("30 days")');
			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`p`.`created_at`) < UNIX_TIMESTAMP() - 2592000', $sql);
		}

		/**
		 * @return void
		 */
		public function testDateStringBecomesATimestamp(): void {
			$sql = $this->compileDelete('range of p is PostEntity delete p where p.createdAt > "2099-01-01"');
			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`p`.`created_at`) > ' . self::year2099(), $sql);
		}

		/**
		 * @return void
		 */
		public function testDeleteParameterBecomesATimestamp(): void {
			$parameters = ['since' => new \DateTime('2099-01-01')];
			$sql = $this->compileDelete('range of p is PostEntity delete p where p.createdAt > :since', $parameters);

			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`p`.`created_at`) > :since', $sql);
			self::assertSame(self::year2099(), $parameters['since']);
		}

		/**
		 * @return void
		 */
		public function testReplaceWhereComparesAsTimestamps(): void {
			$sql = $this->compileReplace('range of p is PostEntity replace p (title = "x") where "2099-01-01" <= p.createdAt');
			self::assertStringContainsString('WHERE ' . self::year2099() . ' <= UNIX_TIMESTAMP(`p`.`created_at`)', $sql);
		}

		/**
		 * A placeholder can't be a datetime string in SET and a timestamp in WHERE at once.
		 * @return void
		 */
		public function testReplaceRejectsAParameterAssignedAndComparedAsDatetime(): void {
			$parameters = ['at' => new \DateTime('2099-01-01')];

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Parameter ':at' is both assigned and compared with a datetime");
			$this->compileReplace('range of p is PostEntity replace p (createdAt = :at) where p.createdAt < :at', $parameters);
		}

		/**
		 * @return void
		 */
		public function testStringThatIsNotADateIsRejected(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'yesterday' is compared with a datetime");
			$this->compileDelete('range of p is PostEntity delete p where p.createdAt > "yesterday"');
		}
	}
