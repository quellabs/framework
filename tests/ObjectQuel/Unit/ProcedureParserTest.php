<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAbort;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBreak;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstContinue;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeleteCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDeclaration;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplaceCurrent;
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
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureParser;

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
			return (new ProcedureParser(new Lexer($source), $GLOBALS['test_em']->getEntityStore()))->parse();
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
				define function count_active_users (integer regionId) integer {
					integer active_count = 0

					range of u is UserEntity
					cursor activeUsers = retrieve (u.id) where u.id = regionId and u.id > 0
					foreach activeUsers {
						active_count = active_count + 1
					}

					return active_count
				}
			');

			$body = $routine->getBody();
			self::assertCount(5, $body);

			self::assertInstanceOf(AstDeclare::class, $body[0]);
			self::assertSame('active_count', $body[0]->getName());
			self::assertSame('integer', $body[0]->getType());
			self::assertNotNull($body[0]->getInitializer());

			self::assertInstanceOf(AstRangeDeclaration::class, $body[1]);
			self::assertSame('u', $body[1]->getRange()->getName());

			self::assertInstanceOf(AstDeclare::class, $body[2]);
			self::assertTrue($body[2]->isCursor());
			$retrieve = $body[2]->getInitializer();
			self::assertInstanceOf(AstRetrieve::class, $retrieve);
			self::assertSame('id', $retrieve->getValues()[0]->getName());
			self::assertNotNull($retrieve->getConditions());

			self::assertInstanceOf(AstForeach::class, $body[3]);
			self::assertSame('activeUsers', $body[3]->getCursorName());
			self::assertInstanceOf(AstVariableAssignment::class, $body[3]->getBody()[0]);

			self::assertInstanceOf(AstReturn::class, $body[4]);
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
				define function f (integer userId) void {
					range of u is UserEntity
					cursor found = retrieve (email_address = u.id) where u.id = userId
				}
			')->getBody();

			self::assertSame('email_address', $body[1]->getInitializer()->getValues()[0]->getName());
		}

		/**
		 * A retrieve outside a cursor declaration is a statement of its own.
		 * @return void
		 */
		public function testStandaloneRetrieve(): void {
			$body = $this->parse('
				define function f () void {
					range of u is UserEntity
					retrieve (u.id) where u.id = 1
				}
			')->getBody();

			self::assertInstanceOf(AstRetrieve::class, $body[1]);
		}

		/**
		 * if/else and while bodies parse into their own statement lists.
		 * @return void
		 */
		public function testIfElseAndWhile(): void {
			$body = $this->parse('
				define function f (integer n) integer {
					integer processed = n
					if processed > 10 {
						processed = 10
					} else {
						processed = 0
					}
					while processed > 1000 {
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
			$body = $this->parse('define function f (integer n) void { if n > 1 { n = 1 } }')->getBody();

			self::assertNull($body[0]->getElseBody());
		}

		/**
		 * replace/delete on a cursor are current-row writes; on a range they are ordinary writes.
		 * @return void
		 */
		public function testCurrentTupleWritesVersusRangeWrites(): void {
			$body = $this->parse('
				define function f () void {
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.id > 0
					foreach users {
						replace users (id = 5)
						delete users
					}
					replace u (id = 1) where u.id = 2
					delete u where u.id = 3
				}
			')->getBody();

			$loopBody = $body[2]->getBody();
			self::assertInstanceOf(AstReplaceCurrent::class, $loopBody[0]);
			self::assertSame('users', $loopBody[0]->getCursorName());
			self::assertCount(1, $loopBody[0]->getAssignments());
			self::assertInstanceOf(AstDeleteCurrent::class, $loopBody[1]);
			self::assertSame('users', $loopBody[1]->getCursorName());

			self::assertInstanceOf(AstReplace::class, $body[3]);
			self::assertInstanceOf(AstDelete::class, $body[4]);
		}

		/**
		 * An append with `or replace` carries its conflict clause.
		 * @return void
		 */
		public function testAppendAndUpsert(): void {
			$body = $this->parse('
				define function sync_user (integer userId, string newEmail) void {
					range of u is UserEntity
					append to u (id = userId, email = newEmail) or replace (email = newEmail) where u.id = userId
				}
			')->getBody();

			self::assertInstanceOf(AstAppend::class, $body[1]);
			self::assertNotNull($body[1]->getOnConflict());
		}

		/**
		 * A transaction block parses with an abort inside it.
		 * @return void
		 */
		public function testTransactionWithAbort(): void {
			$body = $this->parse('
				define function f (string newEmail) void {
					begin transaction {
						if newEmail = "" {
							abort
						}
					}
				}
			')->getBody();

			self::assertInstanceOf(AstBeginTransaction::class, $body[0]);
			self::assertInstanceOf(AstAbort::class, $body[0]->getBody()[0]->getThenBody()[0]);
		}

		/**
		 * break and continue parse inside while, foreach and a nested if.
		 * @return void
		 */
		public function testBreakAndContinue(): void {
			$body = $this->parse('
				define function f (integer n) void {
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.id > 0
					while n > 0 {
						continue
					}
					foreach users {
						if n > 1 {
							break
						} else {
							continue
						}
						break
					}
				}
			')->getBody();

			self::assertInstanceOf(AstContinue::class, $body[2]->getBody()[0]);

			$loopBody = $body[3]->getBody();
			self::assertInstanceOf(AstBreak::class, $loopBody[0]->getThenBody()[0]);
			self::assertInstanceOf(AstContinue::class, $loopBody[0]->getElseBody()[0]);
			self::assertInstanceOf(AstBreak::class, $loopBody[1]);
		}

		/**
		 * `++`, `--`, `+=` and `-=` become `name = name ± value`.
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
				}
			')->getBody();

			self::assertCount(5, $body);

			foreach ([1 => '+', 2 => '-'] as $index => $operator) {
				$value = $this->assertIncrementOf('total', $operator, $body[$index]);
				self::assertInstanceOf(AstNumber::class, $value);
				self::assertSame('1', $value->getValue());
			}

			self::assertInstanceOf(AstFactor::class, $this->assertIncrementOf('total', '+', $body[3]));

			$subtracted = $this->assertIncrementOf('total', '-', $body[4]);
			self::assertInstanceOf(AstTerm::class, $subtracted);
			self::assertSame('-', $subtracted->getOperator());
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
				'postfix in condition'   => ["{$f} { while n++ < 3 { } return n }", "'++' can't be used inside an expression"],
				'signs without spacing'  => ["{$f} { x = n--x return x }", "'--' can't be used inside an expression"],
				'space before postfix'   => ["{$f} { n ++ return n }", "Write '++' directly after 'n'"],
				'prefix statement'       => ["{$f} { ++n return n }", "Write '++' after the variable name, not before it"],
				'spaced prefix'          => ["{$f} { -- n return n }", "Write '--' after the variable name, not before it"],
				'prefix after assignment' => ["{$f} { x = n\n--x return x }", "'--' can't be used inside an expression"],
				'space between signs'    => ["{$f} { n + + return n }", "Expected '++', '--', '+=' or '-=' after 'n'"],
				'space in compound'      => ["{$f} { n - = 1 return n }", "Expected '++', '--', '+=' or '-=' after 'n'"],
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
				define function f () void {
					range of u is UserEntity
					cursor a = retrieve (u.id) where u.id > 0
					cursor b = retrieve (u.id) where u.id < 5
					foreach a {
						foreach b {
							delete b
						}
						delete a
					}
				}
			')->getBody();

			$inner = $body[3]->getBody()[0];
			self::assertInstanceOf(AstForeach::class, $inner);
			self::assertSame('b', $inner->getCursorName());
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
		 * define must be followed by function.
		 * @return void
		 */
		public function testRejectsDefineWithoutFunction(): void {
			$this->expectException(ParserException::class);
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
		 * begin must be followed by transaction.
		 * @return void
		 */
		public function testRejectsBeginWithoutTransaction(): void {
			$this->expectException(LexerException::class);
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
					if x > 1 { x = 1 } else { x = 2 }
					while x > 0 { x = x - 1 }
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
