<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Parser-level coverage for `replace` — no EntityStore involved, so this
	 * only exercises the grammar (see Rules\Replace) and the shape of the
	 * resulting AstReplace, not entity-metadata-dependent semantics (unknown
	 * property, type mismatch, etc. — covered by
	 * tests/Integration/ReplaceTest.php against a real entity).
	 */
	class ReplaceParserTest extends TestCase {

		private function parse(string $query): AstReplace {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstReplace::class, $ast);
			return $ast;
		}

		public function testParsesABasicReplace(): void {
			$ast = $this->parse('
				range of u is UserEntity
				replace u (active = false) where u.id = :userId
			');

			self::assertSame('u', $ast->getRange()->getName());
			self::assertSame('UserEntity', $ast->getRange()->getEntityName());
			self::assertCount(1, $ast->getAssignments());
			self::assertSame('active', $ast->getAssignments()[0]->getProperty());
			self::assertInstanceOf(AstBool::class, $ast->getAssignments()[0]->getValue());
			self::assertNotNull($ast->getConditions());
		}

		public function testParsesMultipleAssignments(): void {
			$ast = $this->parse('
				range of u is UserEntity
				replace u (name = "Bob", age = 40) where u.id = :userId
			');

			$assignments = $ast->getAssignments();
			self::assertCount(2, $assignments);
			self::assertSame('name', $assignments[0]->getProperty());
			self::assertSame('age', $assignments[1]->getProperty());
		}

		public function testRejectsAMissingWhereClause(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of u is UserEntity
				replace u (active = false)
			');
		}

		public function testRejectsAnUndeclaredTargetRange(): void {
			$this->expectException(ParserException::class);

			$this->parse('replace u (active = false) where u.id = :userId');
		}

		public function testRejectsANonEntityRangeTarget(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of j is JSON_SOURCE("data.json")
				replace j (active = false) where j.id = :id
			');
		}

		public function testRejectsADuplicatePropertyInTheAssignmentList(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of u is UserEntity
				replace u (name = "Bob", name = "Carol") where u.id = :userId
			');
		}

		public function testGetRangesReturnsTheSingleTargetRange(): void {
			$ast = $this->parse('
				range of u is UserEntity
				replace u (active = false) where u.id = :userId
			');

			self::assertCount(1, $ast->getRanges());
			self::assertSame($ast->getRange(), $ast->getRanges()[0]);
		}
	}
