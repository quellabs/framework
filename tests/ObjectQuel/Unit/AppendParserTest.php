<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Parser-level coverage for `append` — no EntityStore involved, so this
	 * only exercises the grammar (see Rules\Append) and the shape of the
	 * resulting AstAppend, not entity-metadata-dependent semantics (unknown
	 * property, missing required column, etc. — covered by
	 * tests/Integration/AppendTest.php against a real entity).
	 */
	class AppendParserTest extends TestCase {

		private function parse(string $query): AstAppend {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstAppend::class, $ast);
			return $ast;
		}

		public function testParsesASingleLiteralRowAgainstABareEntityName(): void {
			$ast = $this->parse('append to UserEntity (name = "Alice", active = true)');

			self::assertSame('UserEntity', $ast->getEntityName());
			self::assertFalse($ast->isInsertFromSelect());
			self::assertCount(1, $ast->getRows());

			$row = $ast->getRows()[0];
			self::assertCount(2, $row);
			self::assertSame('name', $row[0]->getProperty());
			self::assertInstanceOf(AstString::class, $row[0]->getValue());
			self::assertSame('Alice', $row[0]->getValue()->getValue());
			self::assertSame('active', $row[1]->getProperty());
		}

		public function testResolvesTheTargetThroughADeclaredRangeAlias(): void {
			$ast = $this->parse('
				range of u is UserEntity
				append to u (name = "Alice")
			');

			self::assertSame('UserEntity', $ast->getEntityName());
		}

		public function testParsesAParameterAndArithmeticExpressionAsAValue(): void {
			$ast = $this->parse('append to UserEntity (region_id = :regionId + 1)');

			$value = $ast->getRows()[0][0]->getValue();
			self::assertNotInstanceOf(AstParameter::class, $value); // it's the AstTerm wrapping :regionId + 1
		}

		public function testParsesAMultiRowAppend(): void {
			$ast = $this->parse('
				append to UserEntity
					(name = "Alice", age = 30),
					(name = "Bob", age = 40)
			');

			$rows = $ast->getRows();
			self::assertCount(2, $rows);
			self::assertSame('Alice', $rows[0][0]->getValue()->getValue());
			self::assertSame('Bob', $rows[1][0]->getValue()->getValue());
			self::assertInstanceOf(AstNumber::class, $rows[1][1]->getValue());
		}

		public function testRejectsMismatchedPropertiesAcrossRows(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				append to UserEntity
					(name = "Alice", age = 30),
					(name = "Bob")
			');
		}

		public function testRejectsADuplicatePropertyInTheSameRow(): void {
			$this->expectException(ParserException::class);

			$this->parse('append to UserEntity (name = "Alice", name = "Bob")');
		}

		public function testParsesInsertFromSelect(): void {
			$ast = $this->parse('
				range of o is ArchivedOrderEntity
				range of a is ActiveOrderEntity
				append to o (name, total) retrieve (a.name, a.total) where a.closed = true
			');

			self::assertSame('ArchivedOrderEntity', $ast->getEntityName());
			self::assertTrue($ast->isInsertFromSelect());
			self::assertNull($ast->getRows());
			self::assertSame(['name', 'total'], $ast->getColumns());
			self::assertInstanceOf(AstRetrieve::class, $ast->getSource());
		}

		public function testRejectsADuplicateColumnInTheInsertFromSelectColumnList(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of o is ArchivedOrderEntity
				range of a is ActiveOrderEntity
				append to o (name, name) retrieve (a.name, a.total)
			');
		}
	}
