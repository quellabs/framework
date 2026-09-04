<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Parser-level coverage for upsert's `append ... or replace (...) where
	 * ...` extension — no EntityStore involved, so this only exercises the
	 * grammar (see Rules\Append's parseOptionalOnConflict()) and the shape
	 * of the resulting AstAppend::getOnConflict(), not entity-metadata-
	 * dependent semantics (conflict-target-matches-a-real-unique-constraint,
	 * dialect-specific SQL — covered by
	 * tests/Unit/QuelToSQLAppendUpsertTest.php and
	 * tests/Integration/UpsertTest.php against a real entity).
	 */
	class UpsertParserTest extends TestCase {

		private function parse(string $query): AstAppend {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstAppend::class, $ast);
			return $ast;
		}

		public function testParsesTheOnConflictClause(): void {
			$ast = $this->parse('
				range of u is UserEntity
				append to u (email = :e, name = :n) or replace (name = :n) where u.email = :e
			');

			$onConflict = $ast->getOnConflict();
			self::assertInstanceOf(AstReplace::class, $onConflict);
			self::assertSame('u', $onConflict->getRange()->getName());
			self::assertCount(1, $onConflict->getAssignments());
			self::assertSame('name', $onConflict->getAssignments()[0]->getProperty());
			self::assertNotNull($onConflict->getConditions());
		}

		public function testOnConflictIsNullWhenAbsent(): void {
			$ast = $this->parse('
				range of u is UserEntity
				append to u (name = "Alice")
			');
			self::assertNull($ast->getOnConflict());
		}

		public function testOnConflictSupportsMultipleAssignments(): void {
			$ast = $this->parse('
				range of u is UserEntity
				append to u (email = :e, name = :n, active = true) or replace (name = :n, active = true) where u.email = :e
			');

			$assignments = $ast->getOnConflict()->getAssignments();
			self::assertCount(2, $assignments);
			self::assertSame('name', $assignments[0]->getProperty());
			self::assertSame('active', $assignments[1]->getProperty());
		}

		public function testOnConflictWorksWithAMultiRowAppend(): void {
			$ast = $this->parse('
				range of u is UserEntity
				append to u
					(email = :e1, name = :n1),
					(email = :e2, name = :n2)
				or replace (name = :n1) where u.email = :e1
			');

			self::assertCount(2, $ast->getRows());
			self::assertNotNull($ast->getOnConflict());
		}

		public function testRejectsOnConflictWhenTheTargetIsNotADeclaredRange(): void {
			// append's own "target must be a declared range" restriction
			// (see AppendParserTest::testRejectsATargetThatIsNotADeclaredRange())
			// applies here too — it isn't specific to the onConflict clause,
			// but this locks in that the combination still fails correctly.
			$this->expectException(ParserException::class);

			$this->parse('append to UserEntity (email = :e, name = :n) or replace (name = :n) where email = :e');
		}

		public function testRejectsADuplicatePropertyInTheOnConflictAssignmentList(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of u is UserEntity
				append to u (email = :e, name = :n) or replace (name = :n, name = :n2) where u.email = :e
			');
		}

		public function testRejectsAMissingWhereClauseInTheOnConflictClause(): void {
			$this->expectException(ParserException::class);

			$this->parse('
				range of u is UserEntity
				append to u (email = :e, name = :n) or replace (name = :n)
			');
		}
	}
