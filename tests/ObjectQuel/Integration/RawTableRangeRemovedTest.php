<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * `range of x is Name` requires `Name` to resolve against a declared
	 * entity; an unresolved name is a parse-time error.
	 */
	class RawTableRangeRemovedTest extends TestCase {

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		private const string UNMAPPED_NAME = 'definitely_not_a_mapped_entity_xyz';

		public function testRetrieveRejectsAnUnresolvedRangeName(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage(self::UNMAPPED_NAME);

			self::em()->getAll('
				range of a is ' . self::UNMAPPED_NAME . '
				retrieve (a.id)
			');
		}

		public function testRetrieveRejectionIsAParseTimeSyntaxErrorNotASemanticError(): void {
			try {
				self::em()->getAll('
					range of a is ' . self::UNMAPPED_NAME . '
					retrieve (a.id)
				');

				$this->fail('Expected a QuelException');
			} catch (QuelException $e) {
				$this->assertSame('syntax_error', $e->type);
			}
		}

		public function testAppendRejectsAnUnresolvedRangeName(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage(self::UNMAPPED_NAME);

			self::em()->executeQuery('
				range of a is ' . self::UNMAPPED_NAME . '
				append to a (message = :message)
			', ['message' => 'x']);
		}

		public function testReplaceRejectsAnUnresolvedRangeName(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage(self::UNMAPPED_NAME);

			self::em()->executeQuery('
				range of a is ' . self::UNMAPPED_NAME . '
				replace a (message = :message) where a.id = :id
			', ['message' => 'x', 'id' => 1]);
		}

		public function testDeleteRejectsAnUnresolvedRangeName(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage(self::UNMAPPED_NAME);

			self::em()->executeQuery('
				range of a is ' . self::UNMAPPED_NAME . '
				delete a where a.id = :id
			', ['id' => 1]);
		}

		public function testEntityRangeIsUnaffected(): void {
			$rows = self::em()->getAll('
				range of u is App\Entities\UserEntity
				retrieve (u.id) where u.id = :id
			', ['id' => -1]);

			$this->assertSame([], $rows);
		}
	}
