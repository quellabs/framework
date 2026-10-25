<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRollback;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBreak;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstContinue;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Exception\SemanticException;

	/**
	 * Grammar-level coverage for routine definitions (objectquel-equel-design.md).
	 * Placement/name/type rules are semantic checks and not tested here.
	 */
	class ProcedureParserTest extends TestCase {

		/**
		 * @param string $source Routine source text
		 * @return AstRoutineDefinition
		 */
		private function parse(string $source): AstRoutineDefinition {
			$ast = (new Parser(new Lexer($source), $GLOBALS['test_em']->getEntityStore()))->parse();

			if (!$ast instanceof AstRoutineDefinition) {
				throw new ParserException("A routine source must contain exactly one 'define function'.");
			}

			return $ast;
		}

		/**
		 * Name, parameters and return type are read from the signature.
		 * @return void
		 */
		public function testParsesSignature(): void {
			$routine = $this->parse('define function sync_user (integer userId, string newEmail) void { }');

			self::assertSame('sync_user', $routine->getName());
			self::assertSame('void', $routine->getDeclaredReturnType());
			self::assertTrue($routine->isVoid());
			self::assertCount(2, $routine->getParameters());
			self::assertSame('userId', $routine->getParameters()[0]->getName());
			self::assertSame('integer', $routine->getParameters()[0]->getType());
			self::assertSame('newEmail', $routine->getParameters()[1]->getName());
			self::assertSame([], $routine->getBody());
		}

		/**
		 * `@ignoreSoftDelete true` ahead of `define function` attaches to the routine, same
		 * directive syntax and case-insensitive name lookup as AstDelete/AstRetrieve use.
		 * @return void
		 */
		public function testParsesLeadingDirective(): void {
			$routine = $this->parse('@ignoreSoftDelete true define function sync_user () void { }');

			self::assertTrue($routine->getDirective('ignoreSoftDelete'));
			self::assertTrue($routine->getDirective('IGNORESOFTDELETE'));
			self::assertSame(['ignoresoftdelete' => true], $routine->getDirectives());
		}

		/**
		 * Numeric directive values retain their signs and numeric types.
		 * @return void
		 */
		public function testParsesNumericDirectiveValues(): void {
			$routine = $this->parse('@count 2 @offset -1 @ratio 1.5 define function f () void { }');
			self::assertSame(['count' => 2, 'offset' => -1, 'ratio' => 1.5], $routine->getDirectives());
		}

		/**
		 * A routine with no leading directive carries none.
		 * @return void
		 */
		public function testNoDirectiveByDefault(): void {
			$routine = $this->parse('define function sync_user () void { }');

			self::assertSame([], $routine->getDirectives());
			self::assertNull($routine->getDirective('ignoreSoftDelete'));
		}

		/**
		 * Numeric window values are parsed and validated by the semantic analyzer.
		 * @param string $window Window clause
		 * @return void
		 */
		#[DataProvider('invalidWindows')]
		public function testRejectsInvalidWindowValuesSemantically(string $window): void {
			$store = $GLOBALS['test_em']->getEntityStore();
			$retrieve = (new Parser(new Lexer("retrieve (1) {$window}"), $store))->parse();
			self::assertInstanceOf(AstRetrieve::class, $retrieve);

			$this->expectException(SemanticException::class);
			(new SemanticAnalyzer($store))->validate($retrieve);
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function invalidWindows(): array {
			return [
				'fractional page' => ['window 1.5, 2'],
				'negative page' => ['window -1, 2'],
				'fractional size' => ['window 1, 2.5'],
				'zero size' => ['window 1, 0'],
				'negative size' => ['window 1, -2'],
			];
		}

		/**
		 * An empty parameter list parses, and a non-void return type is not void.
		 * @return void
		 */
		public function testParsesEmptyParameterList(): void {
			$routine = $this->parse('define function f () integer { return 1 }');

			self::assertSame([], $routine->getParameters());
			self::assertFalse($routine->isVoid());
		}

		/**
		 * The design doc's cursor loop parses into declaration, range, cursor, foreach and return.
		 * @return void
		 */
		public function testParsesCursorLoopExample(): void {
			$routine = $this->parse('
				range of u is UserEntity
				define function count_active_users (integer regionId) integer {
					integer active_count = 0

					cursor activeUsers = retrieve (u.id) where u.id = regionId and u.id > 0
					foreach (activeUsers as row) {
						active_count = active_count + 1
					}

					return active_count
				}
			');

			self::assertCount(1, $routine->getRanges());
			self::assertSame('u', $routine->getRanges()[0]->getName());

			$body = $routine->getBody();
			self::assertCount(4, $body);

			self::assertInstanceOf(AstDeclare::class, $body[0]);
			self::assertSame('active_count', $body[0]->getName());
			self::assertSame('integer', $body[0]->getType());
			self::assertNotNull($body[0]->getInitializer());

			self::assertInstanceOf(AstDeclare::class, $body[1]);
			self::assertTrue($body[1]->isCursor());
			$retrieve = $body[1]->getInitializer();
			self::assertInstanceOf(AstRetrieve::class, $retrieve);
			self::assertSame('id', $retrieve->getValues()[0]->getName());
			self::assertNotNull($retrieve->getConditions());

			self::assertInstanceOf(AstForeach::class, $body[2]);
			self::assertSame('activeUsers', $body[2]->getCursorName());
			self::assertSame('row', $body[2]->getRowName());
			self::assertInstanceOf(AstVariableAssignment::class, $body[2]->getBody()[0]);

			self::assertInstanceOf(AstReturn::class, $body[3]);
		}

		/**
		 * A declaration without `=` has no initializer.
		 * @return void
		 */
		public function testDeclarationWithoutInitializer(): void {
			$body = $this->parse('define function f () void { string email }')->getBody();

			self::assertInstanceOf(AstDeclare::class, $body[0]);
			self::assertNull($body[0]->getInitializer());
		}

		/**
		 * A target-list alias names the cursor field.
		 * @return void
		 */
		public function testRetrieveAliasNamesTheField(): void {
			$body = $this->parse('
				range of u is UserEntity
				define function f (integer userId) void {
					cursor found = retrieve (email_address = u.id) where u.id = userId
				}
			')->getBody();

			self::assertSame('email_address', $body[0]->getInitializer()->getValues()[0]->getName());
		}

		/**
		 * A retrieve outside a cursor declaration is a statement of its own.
		 * @return void
		 */
		public function testStandaloneRetrieve(): void {
			$body = $this->parse('
				range of u is UserEntity
				define function f () void {
					retrieve (u.id) where u.id = 1
				}
			')->getBody();

			self::assertInstanceOf(AstRetrieve::class, $body[0]);
		}

		/**
		 * if/else and while bodies parse into their own statement lists.
		 * @return void
		 */
		public function testIfElseAndWhile(): void {
			$body = $this->parse('
				define function f (integer n) integer {
					integer processed = n
					if (processed > 10) {
						processed = 10
					} else {
						processed = 0
					}
					while (processed > 1000) {
						processed = processed - 1000
					}
					return processed
				}
			')->getBody();

			self::assertInstanceOf(AstIf::class, $body[1]);
			self::assertCount(1, $body[1]->getThenBody());
			self::assertCount(1, $body[1]->getElseBody());
			self::assertInstanceOf(AstWhile::class, $body[2]);
			self::assertCount(1, $body[2]->getBody());
		}

		/**
		 * An if without else has a null else body.
		 * @return void
		 */
		public function testIfWithoutElseHasNullElseBody(): void {
			$body = $this->parse('define function f (integer n) void { if (n > 1) { n = 1 } }')->getBody();

			self::assertNull($body[0]->getElseBody());
		}

		/**
		 * Each elseif is an if nested alone in the previous branch's else body; the final else belongs to the last one.
		 * @return void
		 */
		public function testElseifNestsInTheElseBody(): void {
			$body = $this->parse('
				define function f (integer n) integer {
					if (n < 0) {
						return -1
					} elseif (n = 0) {
						return 0
					} ELSEIF (n = 1) {
						return 1
					} else {
						return 2
					}
				}
			')->getBody();

			self::assertCount(1, $body);
			$branch = $body[0];

			foreach (['-1', '0', '1'] as $value) {
				self::assertInstanceOf(AstIf::class, $branch);
				self::assertSame($value, $branch->getThenBody()[0]->getValue()->getValue());
				$elseBody = $branch->getElseBody();
				self::assertIsArray($elseBody);
				self::assertCount(1, $elseBody);
				$branch = $elseBody[0];
			}

			self::assertInstanceOf(AstReturn::class, $branch);
			self::assertSame('2', $branch->getValue()->getValue());
		}

		/**
		 * An elseif chain without a final else leaves the last branch's else body null.
		 * @return void
		 */
		public function testElseifWithoutElse(): void {
			$body = $this->parse('define function f (integer n) void { if (n > 1) { n = 1 } elseif (n < 0) { n = 0 } }')->getBody();

			$elseBody = $body[0]->getElseBody();
			self::assertIsArray($elseBody);
			self::assertInstanceOf(AstIf::class, $elseBody[0]);
			self::assertNull($elseBody[0]->getElseBody());
		}

		/**
		 * replace/delete always target a declared range, inside a loop or not; there is no
		 * separate current-row form — the target must be a range name, not a cursor name.
		 * @return void
		 */
		public function testReplaceAndDeleteAlwaysTargetADeclaredRange(): void {
			$body = $this->parse('
				range of u is UserEntity
				define function f () void {
					cursor users = retrieve (u.id) where u.id > 0
					foreach (users as row) {
						replace u (id = 5) where u.id = row.id
						delete u where u.id = row.id
					}
					replace u (id = 1) where u.id = 2
					delete u where u.id = 3
				}
			')->getBody();

			$loopBody = $body[1]->getBody();
			self::assertInstanceOf(AstReplace::class, $loopBody[0]);
			self::assertInstanceOf(AstDelete::class, $loopBody[1]);

			self::assertInstanceOf(AstReplace::class, $body[2]);
			self::assertInstanceOf(AstDelete::class, $body[3]);
		}

		/**
		 * A cursor's own name is never a valid replace/delete target: it isn't a declared range.
		 * @return void
		 */
		public function testReplaceOnACursorNameIsRejected(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("Undefined range reference 'users' in replace statement");
			$this->parse('
				range of u is UserEntity
				define function f () void {
					cursor users = retrieve (u.id) where u.id > 0
					foreach (users as row) {
						replace users (id = 5)
					}
				}
			');
		}

		/**
		 * Same rejection for delete.
		 * @return void
		 */
		public function testDeleteOnACursorNameIsRejected(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("Undefined range reference 'users' in delete statement");
			$this->parse('
				range of u is UserEntity
				define function f () void {
					cursor users = retrieve (u.id) where u.id > 0
					foreach (users as row) {
						delete users
					}
				}
			');
		}

		/**
		 * An append with `or replace` carries its conflict clause.
		 * @return void
		 */
		public function testAppendAndUpsert(): void {
			$body = $this->parse('
				range of u is UserEntity
				define function sync_user (integer userId, string newEmail) void {
					append to u (id = userId, email = newEmail) or replace (email = newEmail) where u.id = userId
				}
			')->getBody();

			self::assertInstanceOf(AstAppend::class, $body[0]);
			self::assertNotNull($body[0]->getOnConflict());
		}

		/**
		 * An atomic block parses with a rollback inside it.
		 * @return void
		 */
		public function testAtomicWithRollback(): void {
			$body = $this->parse('
				define function f (string newEmail) void {
					atomic {
						if (newEmail = "") {
							rollback
						}
					}
				}
			')->getBody();

			self::assertInstanceOf(AstAtomic::class, $body[0]);
			self::assertInstanceOf(AstRollback::class, $body[0]->getBody()[0]->getThenBody()[0]);
		}

		/**
		 * break and continue parse inside while, foreach and a nested if.
		 * @return void
		 */
		public function testBreakAndContinue(): void {
			$body = $this->parse('
				range of u is UserEntity
				define function f (integer n) void {
					cursor users = retrieve (u.id) where u.id > 0
					while (n > 0) {
						continue
					}
					foreach (users as row) {
						if (n > 1) {
							break
						} else {
							continue
						}
						break
					}
				}
			')->getBody();

			self::assertInstanceOf(AstContinue::class, $body[1]->getBody()[0]);
			self::assertSame(1, $body[1]->getBody()[0]->getLevels());

			$loopBody = $body[2]->getBody();
			self::assertInstanceOf(AstBreak::class, $loopBody[0]->getThenBody()[0]);
			self::assertSame(1, $loopBody[0]->getThenBody()[0]->getLevels());
			self::assertInstanceOf(AstContinue::class, $loopBody[0]->getElseBody()[0]);
			self::assertSame(1, $loopBody[0]->getElseBody()[0]->getLevels());
			self::assertInstanceOf(AstBreak::class, $loopBody[1]);
			self::assertSame(1, $loopBody[1]->getLevels());
		}

		/**
		 * Numeric levels are stored in the AST for semantic validation.
		 * @return void
		 */
		public function testParsesLoopJumpLevels(): void {
			$body = $this->parse('define function f () void { break 2; continue 3; break 1; continue 1; break -1; continue 0; break 1.5; continue -2.5 }')->getBody();

			self::assertInstanceOf(AstBreak::class, $body[0]);
			self::assertSame(2, $body[0]->getLevels());
			self::assertSame(2, $body[0]->deepClone()->getLevels());
			self::assertInstanceOf(AstContinue::class, $body[1]);
			self::assertSame(3, $body[1]->getLevels());
			self::assertSame(3, $body[1]->deepClone()->getLevels());
			self::assertSame(1, $body[2]->getLevels());
			self::assertSame(1, $body[3]->getLevels());
			self::assertSame(-1, $body[4]->getLevels());
			self::assertSame(0, $body[5]->getLevels());
			self::assertSame(1.5, $body[6]->getLevels());
			self::assertSame(-2.5, $body[7]->getLevels());
			self::assertSame(1.5, $body[6]->deepClone()->getLevels());
			self::assertSame(-2.5, $body[7]->deepClone()->getLevels());
		}

		/**
		 * `++`, `--`, `+=`, `-=`, `*=` and `/=` become `name = name op value`.
		 * @return void
		 */
		public function testIncrementAndCompoundAssignment(): void {
			$body = $this->parse('
				define function f (integer n) void {
					integer total = 0
					total++
					total--
					total += n * 2
					total -= n - 1
					total *= n + 1
					total /= n * 2
				}
			')->getBody();

			self::assertCount(7, $body);

			foreach ([1 => '+', 2 => '-'] as $index => $operator) {
				$value = $this->assertIncrementOf('total', $operator, $body[$index]);
				self::assertInstanceOf(AstNumber::class, $value);
				self::assertSame('1', $value->getValue());
			}

			self::assertInstanceOf(AstFactor::class, $this->assertIncrementOf('total', '+', $body[3]));

			$subtracted = $this->assertIncrementOf('total', '-', $body[4]);
			self::assertInstanceOf(AstTerm::class, $subtracted);
			self::assertSame('-', $subtracted->getOperator());

			foreach ([5 => ['*', AstTerm::class], 6 => ['/', AstFactor::class]] as $index => [$operator, $valueClass]) {
				$value = $body[$index]->getValue();
				self::assertInstanceOf(AstFactor::class, $value);
				self::assertSame($operator, $value->getOperator());
				self::assertSame('total', $value->getLeft()->getName());
				self::assertInstanceOf($valueClass, $value->getRight());
			}
		}

		/**
		 * Signs separated by whitespace are still unary operators.
		 * @return void
		 */
		public function testSpacedSignsRemainUnaryOperators(): void {
			$body = $this->parse('define function f (integer n, integer x) void { x = n - -x }')->getBody();

			$value = $body[0]->getValue();
			self::assertInstanceOf(AstTerm::class, $value);
			self::assertInstanceOf(AstUnaryOperation::class, $value->getRight());
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function misplacedIncrements(): array {
			$f = 'define function f (integer n, integer x) integer';

			return [
				'postfix in assignment'  => ["{$f} { x = n++ return x }", "'++' can't be used inside an expression"],
				'prefix in assignment'   => ["{$f} { x = ++n return x }", "'++' can't be used inside an expression"],
				'postfix in return'      => ["{$f} { return n-- }", "'--' can't be used inside an expression"],
				'postfix in condition'   => ["{$f} { while (n++ < 3) { } return n }", "'++' can't be used inside an expression"],
				'signs without spacing'  => ["{$f} { x = n--x return x }", "'--' can't be used inside an expression"],
				'space before postfix'   => ["{$f} { n ++ return n }", "Write '++' directly after 'n'"],
				'prefix statement'       => ["{$f} { ++n return n }", "Write '++' after the variable name, not before it"],
				'spaced prefix'          => ["{$f} { -- n return n }", "Write '--' after the variable name, not before it"],
				'prefix after assignment' => ["{$f} { x = n\n--x return x }", "'--' can't be used inside an expression"],
				'space between signs'    => ["{$f} { n + + return n }", "Expected '++', '--', '+=', '-=', '*=' or '/=' after 'n'"],
				'space in compound'      => ["{$f} { n - = 1 return n }", "Expected '++', '--', '+=', '-=', '*=' or '/=' after 'n'"],
				'space in multiply'      => ["{$f} { n * = 2 return n }", "Expected '++', '--', '+=', '-=', '*=' or '/=' after 'n'"],
				'single sign statement'  => ["{$f} { +n return n }", "Unexpected token"],
			];
		}

		/**
		 * @param string $source Routine source text
		 * @param string $message Fragment of the expected ParserException message
		 * @return void
		 */
		#[DataProvider('misplacedIncrements')]
		public function testRejectsMisplacedIncrement(string $source, string $message): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage($message);
			$this->parse($source);
		}

		/**
		 * Asserts that $statement is `name = name <operator> value`.
		 * @param string $name Variable assigned to
		 * @param string $operator '+' or '-'
		 * @param AstInterface $statement Parsed statement
		 * @return AstInterface The value added or subtracted
		 */
		private function assertIncrementOf(string $name, string $operator, AstInterface $statement): AstInterface {
			self::assertInstanceOf(AstVariableAssignment::class, $statement);
			self::assertSame($name, $statement->getName());

			$term = $statement->getValue();
			self::assertInstanceOf(AstTerm::class, $term);
			self::assertSame($operator, $term->getOperator());
			self::assertInstanceOf(AstIdentifier::class, $term->getLeft());
			self::assertSame($name, $term->getLeft()->getName());

			return $term->getRight();
		}

		/**
		 * Statements may end in a semicolon.
		 * @return void
		 */
		public function testSemicolonsAreOptional(): void {
			$body = $this->parse('define function f () integer { integer x = 1; x = x + 1; return x; }')->getBody();

			self::assertCount(3, $body);
		}

		/**
		 * A foreach nested in a foreach parses.
		 * @return void
		 */
		public function testNestedBlocksParseRecursively(): void {
			$body = $this->parse('
				range of u is UserEntity
				define function f () void {
					cursor a = retrieve (u.id) where u.id > 0
					cursor b = retrieve (u.id) where u.id < 5
					foreach (a as rowA) {
						foreach (b as rowB) {
							delete u where u.id = rowB.id
						}
						delete u where u.id = rowA.id
					}
				}
			')->getBody();

			$inner = $body[2]->getBody()[0];
			self::assertInstanceOf(AstForeach::class, $inner);
			self::assertSame('b', $inner->getCursorName());
			self::assertSame('rowB', $inner->getRowName());
		}

		/**
		 * A signature without a return type is rejected.
		 * @return void
		 */
		public function testRejectsMissingReturnType(): void {
			$this->expectException(ParserException::class);
			$this->parse('define function f () { }');
		}

		/**
		 * define must be followed by function; matchKeyword() reports this
		 * as a LexerException, since it's a plain keyword mismatch.
		 * @return void
		 */
		public function testRejectsDefineWithoutFunction(): void {
			$this->expectException(LexerException::class);
			$this->parse('define view v () void { }');
		}

		/**
		 * Source that doesn't start with define is rejected.
		 * @return void
		 */
		public function testRejectsSourceNotStartingWithDefine(): void {
			$this->expectException(ParserException::class);
			$this->parse('retrieve (1)');
		}

		/**
		 * One source defines one routine.
		 * @return void
		 */
		public function testRejectsASecondRoutineInOneSource(): void {
			$this->expectException(ParserException::class);
			$this->parse('define function f () void { } define function g () void { }');
		}

		/**
		 * A body without its closing brace is rejected.
		 * @return void
		 */
		public function testRejectsUnterminatedBody(): void {
			$this->expectException(ParserException::class);
			$this->parse('define function f () void { integer x = 1');
		}

		/**
		 * else without a preceding if is rejected.
		 * @return void
		 */
		public function testRejectsElseWithoutIf(): void {
			$this->expectException(ParserException::class);
			$this->parse('define function f () void { else { } }');
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function unparenthesizedConditions(): array {
			$f = 'define function f (integer n) void';

			return [
				'if'                  => ["{$f} { if n > 1 { } }", "Expected '(' after 'if'"],
				'elseif'              => ["{$f} { if (n > 1) { } elseif n < 0 { } }", "Expected '(' after 'elseif'"],
				'while'               => ["{$f} { while n > 1 { } }", "Expected '(' after 'while'"],
				'unclosed'            => ["{$f} { if (n > 1 { } }", "Expected ')' to close the 'if' condition"],
				'more after the parens' => ["{$f} { if (n > 1) or (n < 0) { } }", "Expected '{'"],
			];
		}

		/**
		 * if, elseif and while need their whole condition in one pair of parentheses.
		 * @param string $source Routine source text
		 * @param string $message Fragment of the expected ParserException message
		 * @return void
		 */
		#[DataProvider('unparenthesizedConditions')]
		public function testRejectsUnparenthesizedCondition(string $source, string $message): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage($message);
			$this->parse($source);
		}

		/**
		 * elseif without a preceding if is rejected.
		 * @return void
		 */
		public function testRejectsElseifWithoutIf(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("'elseif' without a preceding 'if'");
			$this->parse('define function f (integer n) void { elseif (n > 1) { } }');
		}

		/**
		 * elseif after else is rejected: else ends the chain.
		 * @return void
		 */
		public function testRejectsElseifAfterElse(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("'elseif' without a preceding 'if'");
			$this->parse('define function f (integer n) void { if (n > 1) { } else { } elseif (n < 0) { } }');
		}

		/**
		 * else if parses like elseif, and the two spellings can be mixed in one chain.
		 * @return void
		 */
		public function testElseIfWithASpaceMatchesElseif(): void {
			$body = $this->parse('
				define function f (integer n) integer {
					if (n < 0) {
						return -1
					} else if (n = 0) {
						return 0
					} elseif (n = 1) {
						return 1
					} else {
						return 2
					}
				}
			')->getBody();

			$branch = $body[0];

			foreach (['-1', '0', '1'] as $value) {
				self::assertInstanceOf(AstIf::class, $branch);
				self::assertSame($value, $branch->getThenBody()[0]->getValue()->getValue());
				$elseBody = $branch->getElseBody();
				self::assertIsArray($elseBody);
				self::assertCount(1, $elseBody);
				$branch = $elseBody[0];
			}

			self::assertInstanceOf(AstReturn::class, $branch);
		}

		/**
		 * The old begin keyword is not a routine statement.
		 * @return void
		 */
		public function testRejectsBeginKeyword(): void {
			$this->expectException(ParserException::class);
			$this->parse('define function f () void { begin { } }');
		}

		/**
		 * An identifier that starts no statement is rejected.
		 * @return void
		 */
		public function testRejectsBareIdentifierStatement(): void {
			$this->expectException(ParserException::class);
			$this->parse('define function f () void { foo }');
		}

		/**
		 * deepClone copies every node and reparents the copies.
		 * @return void
		 */
		public function testDeepCloneProducesAnEqualButDistinctTree(): void {
			$routine = $this->parse('
				define function f (integer n) integer {
					integer x = n
					if (x > 1) { x = 1 } else { x = 2 }
					while (x > 0) { x = x - 1 }
					return x
				}
			');

			$clone = $routine->deepClone();

			self::assertNotSame($routine->getBody()[1], $clone->getBody()[1]);
			self::assertInstanceOf(AstIf::class, $clone->getBody()[1]);
			self::assertSame($clone, $clone->getBody()[1]->getParent());
			self::assertCount(1, $clone->getBody()[1]->getElseBody());
			self::assertSame('n', $clone->getParameters()[0]->getName());
		}
	}
