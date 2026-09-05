<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Parser-level coverage for `delete` — no EntityStore involved, so this
	 * only exercises the grammar (see Rules\Delete) and the shape of the
	 * resulting AstDelete, not entity-metadata-dependent semantics (covered
	 * by tests/Integration/DeleteTest.php against a real entity).
	 *
	 * `delete` is a distinct keyword/token from `destroy` (table/index
	 * dropping — see objectquel-destroy-plan.md), so a `delete` statement
	 * always parses as this DML verb — no dispatch ambiguity to test for.
	 */
	class DeleteParserTest extends TestCase {

		private function parse(string $query): AstDelete {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstDelete::class, $ast);
			return $ast;
		}

		public function testParsesABasicDelete(): void {
			$ast = $this->parse('
				range of u is UserEntity
				delete u where u.id = :userId
			');

			self::assertSame('u', $ast->getRange()->getName());
			self::assertSame('UserEntity', $ast->getRange()->getEntityName());
			self::assertNotNull($ast->getConditions());
		}

		public function testRejectsAMissingWhereClause(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of u is UserEntity
				delete u
			');
		}

		public function testRejectsAnUndeclaredTargetRange(): void {
			$this->expectException(ParserException::class);

			$this->parse('delete u where u.id = :userId');
		}

		public function testRejectsANonEntityRangeTarget(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of j is JSON_SOURCE("data.json")
				delete j where j.id = :id
			');
		}

		public function testGetRangesReturnsTheSingleTargetRange(): void {
			$ast = $this->parse('
				range of u is UserEntity
				delete u where u.id = :userId
			');

			self::assertCount(1, $ast->getRanges());
			self::assertSame($ast->getRange(), $ast->getRanges()[0]);
		}

		public function testIsNotConfusedWithDestroy(): void {
			$ast = (new Parser(new Lexer('destroy Foo')))->parse();
			self::assertNotInstanceOf(AstDelete::class, $ast);
		}
	}
