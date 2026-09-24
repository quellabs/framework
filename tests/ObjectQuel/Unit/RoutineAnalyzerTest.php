<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureParser;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineAnalyzer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Semantic rules for routines (objectquel-equel-design.md): scope, placement,
	 * type positions, cursor/foreach rules and control flow.
	 */
	class RoutineAnalyzerTest extends TestCase {

		/**
		 * Parses and analyzes a routine.
		 * @param string $source Routine source text
		 * @return AstRoutineDefinition The analyzed routine
		 */
		private function analyze(string $source): AstRoutineDefinition {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$routine = (new ProcedureParser(new Lexer($source), $entityStore))->parse();
			(new RoutineAnalyzer($entityStore))->analyze($routine);
			return $routine;
		}

		/**
		 * Returns the type of every root identifier in the routine, keyed by complete name.
		 * @param AstRoutineDefinition $routine Analyzed routine
		 * @return array<string, IdentifierType>
		 */
		private function rootIdentifierTypes(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes(AstIdentifier::class);
			$routine->accept($collector);

			$types = [];

			foreach ($collector->getCollectedNodes() as $identifier) {
				if (!$identifier->getParent() instanceof AstIdentifier) {
					$types[$identifier->getCompleteName()] = $identifier->getType();
				}
			}

			return $types;
		}

		/**
		 * Variables in queries and procedural expressions are typed as routine variables.
		 * @return void
		 */
		public function testTypesVariableReferences(): void {
			$routine = $this->analyze('
				define function count_users (int minId) integer {
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.id > minId
					foreach users {
						total = total + 1
					}
					return total
				}
			');

			$types = $this->rootIdentifierTypes($routine);
			self::assertSame(IdentifierType::RoutineVariable, $types['minId']);
			self::assertSame(IdentifierType::RoutineVariable, $types['total']);
			self::assertSame(IdentifierType::Unresolved, $types['u.id'], 'Range references are left to the query pipeline');
		}

		/**
		 * A cursor row field inside its own loop is typed CursorRoot/CursorField, also inside an embedded append.
		 * @return void
		 */
		public function testTypesCursorFieldReads(): void {
			$routine = $this->analyze('
				define function copy_titles () void {
					range of u is UserEntity
					range of p is PostEntity
					cursor users = retrieve (name = u.username) where u.banned = false
					foreach users {
						append to p (title = users.name, content = "")
					}
				}
			');

			$collector = new CollectNodes(AstIdentifier::class);
			$routine->accept($collector);
			$root = array_values(array_filter($collector->getCollectedNodes(), fn($i) => $i->getCompleteName() === 'users.name'))[0];

			self::assertSame(IdentifierType::CursorRoot, $root->getType());
			self::assertSame(IdentifierType::CursorField, $root->getNext()->getType());
		}

		/**
		 * The design's nested-loop example: two cursors, current-row deletes, and sequential reuse.
		 * @return void
		 */
		public function testAcceptsNestedLoopsAndSequentialReuse(): void {
			$this->analyze('
				define function purge () void {
					range of u is UserEntity
					cursor banned = retrieve (u.id) where u.banned = true
					cursor early = retrieve (u.id) where u.id < 5
					foreach banned {
						foreach early {
							delete early
						}
						delete banned
					}
					foreach banned {
						replace banned (banned = false)
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * abort as the last statement of one branch is legal; the other branch may continue.
		 * @return void
		 */
		public function testAcceptsAbortAsLastStatementOnItsPath(): void {
			$this->analyze('
				define function rename (integer userId, string newName) void {
					range of u is UserEntity
					begin transaction {
						replace u (username = newName) where u.id = userId
						if (newName = "") {
							abort
						} else {
							replace u (banned = false) where u.id = userId
						}
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * if/else returning on both branches satisfies the exit-path check.
		 * @return void
		 */
		public function testAcceptsReturnOnBothBranches(): void {
			$this->analyze('define function sign (INT n) Integer { if (n < 0) { return -1 } else { return 1 } }');
			$this->addToAssertionCount(1);
		}

		/**
		 * An elseif chain ending in else returns on every path.
		 * @return void
		 */
		public function testAcceptsReturnOnEveryElseifBranch(): void {
			$this->analyze('define function sign (INT n) Integer { if (n < 0) { return -1 } elseif (n = 0) { return 0 } else { return 1 } }');
			$this->addToAssertionCount(1);
		}

		/**
		 * `retrieve (n)` names its entry after the variable it reads; that is not a collision.
		 * @return void
		 */
		public function testAcceptsTargetNamedAfterItsOwnVariable(): void {
			$this->analyze('
				define function f (integer n) void {
					range of u is UserEntity
					retrieve (n) where u.id = n
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * break/continue in a loop inside a transaction, and in the inner loop of a nested pair.
		 * @return void
		 */
		public function testAcceptsBreakAndContinueInsideTheirLoop(): void {
			$this->analyze('
				define function f (integer n) void {
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.id > 0
					begin transaction {
						while (n > 0) {
							if (n = 5) {
								break
							}
							n = n - 1
						}
					}
					while (n < 10) {
						foreach users {
							if (users.id = n) {
								continue
							}
							break
						}
						n = n + 1
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * Rejected routines and a fragment of the expected message.
		 * @return array<string, array{string, string}>
		 */
		public static function rejectedRoutines(): array {
			$range = 'range of u is UserEntity ';

			return [
				'unknown parameter type'        => ['define function f (number n) void { }', "Unknown type 'number' for parameter"],
				'query placeholder'             => ["define function f () void { {$range} delete u where u.id = :id }", "':id' placeholders aren't allowed"],
				'void parameter'                => ['define function f (void n) void { }', "Unknown type 'void' for parameter"],
				'cursor parameter'              => ['define function f (cursor c) void { }', "Unknown type 'cursor' for parameter"],
				'unknown return type'           => ['define function f () number { return 1 }', "Unknown return type 'number'"],
				'cursor return type'            => ['define function f () cursor { }', "Unknown return type 'cursor'"],
				'void local'                    => ['define function f () void { void x }', "Unknown type 'void' for local"],
				'cursor without initializer'    => ['define function f () void { cursor c }', 'must be initialized with a retrieve'],
				'cursor with expression'        => ['define function f () void { cursor c = 1 }', 'must be initialized with a retrieve'],
				'scalar with retrieve'          => ["define function f () void { {$range} integer x = retrieve (u.id) where u.id = 1 }", 'Only a cursor can be initialized'],
				'declaration inside if'         => ['define function f (integer n) void { if (n > 1) { integer x = 1 } }', 'must be at the top level'],
				'range inside while'            => ['define function f (integer n) void { while (n > 1) { range of u is UserEntity } }', 'must be at the top level'],
				'local redeclares parameter'    => ['define function f (integer n) void { integer n }', "'n' is already declared"],
				'local redeclares range'        => ["define function f () void { {$range} integer u }", "'u' is already declared"],
				'statement keyword as name'     => ['define function f () void { integer foreach }', 'statement keyword'],
				'break as name'                 => ['define function f () void { integer break }', 'statement keyword'],
				'elseif as name'                => ['define function f (integer elseif) void { }', 'statement keyword'],
				'locals differing in case'      => ['define function f () void { integer total integer Total }', "'total' and 'Total' differ only in case"],
				'local and parameter case'      => ['define function f (integer n) void { string N }', "'n' and 'N' differ only in case"],
				'cursor and local case'         => ["define function f () void { {$range} integer rows cursor Rows = retrieve (u.id) }", "'rows' and 'Rows' differ only in case"],
				'cursor fields differing case'  => ["define function f () void { {$range} cursor c = retrieve (Id = u.username, u.id) }", "Fields 'c.Id' and 'c.id' differ only in case"],
				'assignment before declaration' => ['define function f () void { x = 1 integer x }', 'assigned before its declaration'],
				'read before declaration'       => ['define function f () void { integer y = x integer x }', "'x' is used before its declaration"],
				'range before declaration'      => ['define function f () void { cursor c = retrieve (u.id) where u.id = 1 range of u is UserEntity }', "'u' is used before its declaration"],
				'self-referencing initializer'  => ['define function f () void { integer x = x + 1 }', "'x' is used before its declaration"],
				'undefined name'                => ['define function f () integer { return y }', "Undefined name 'y'"],
				'assignment to undeclared'      => ['define function f () void { y = 1 }', "undeclared variable 'y'"],
				'increment of undeclared'       => ['define function f () void { y++ }', "undeclared variable 'y'"],
				'range in expression'           => ["define function f () void { {$range} if (u.id > 1) { } }", "Range 'u' can only be used inside"],
				'assignment to range'           => ["define function f () void { {$range} u = 1 }", "Range 'u' can't be assigned"],
				'field on scalar'               => ['define function f (integer n) integer { return n.x }', 'has no fields'],
				'variable is also a property'   => ["define function f (string username) void { {$range} retrieve (u.id) where username = \"x\" }", 'both a routine variable and a property'],
				'variable is also a target'     => ["define function f (integer k) void { {$range} retrieve (k = u.id) where u.id > k }", 'both a routine variable and a target-list name'],
				'window'                        => ["define function f () void { {$range} retrieve (u.id) where u.id > 0 window 0, 10 }", "'window' is not supported"],
				'whole entity target'           => ["define function f () void { {$range} cursor c = retrieve (u) where u.id > 0 }", 'not whole entities'],
				'cursor as a value'             => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 integer x = c }", "Cursor 'c' is not a value"],
				'cursor reassigned'             => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 c = 1 }", "Cursor 'c' can't be reassigned"],
				'cursor incremented'            => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 c += 1 }", "Cursor 'c' can't be reassigned"],
				'cursor field outside loop'     => ["define function f () integer { {$range} cursor c = retrieve (u.id) where u.id > 0 return c.id }", "can only be read inside 'foreach c'"],
				'declaration inside foreach'    => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 foreach c { integer x } }", 'must be at the top level'],
				'missing cursor field'          => ["define function f () void { {$range} integer x cursor c = retrieve (u.id) where u.id > 0 foreach c { x = c.username } }", "has no field 'username'"],
				'foreach over scalar'           => ['define function f (integer n) void { foreach n { } }', "needs a cursor, but 'n' is not one"],
				'foreach undefined'             => ['define function f () void { foreach c { } }', "Undefined cursor 'c'"],
				'foreach on open cursor'        => ["define function f () void { {$range} cursor a = retrieve (u.id) where u.id > 0 cursor b = retrieve (u.id) where u.id > 1 foreach a { foreach b { foreach a { } } } }", 'same cursor'],
				'delete current outside loop'   => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 delete c }", "must be inside 'foreach c'"],
				'delete current on multi-range' => ["define function f () void { {$range} range of p is PostEntity cursor c = retrieve (u.id, p.title) where p.userId = u.id foreach c { delete c } }", 'reads more than one range'],
				'delete current on unique'      => ["define function f () void { {$range} cursor c = retrieve unique (u.username) where u.id > 0 foreach c { delete c } }", "uses 'retrieve unique'"],
				'delete current on aggregate'   => ["define function f () void { {$range} cursor c = retrieve (n = count(u.id)) where u.id > 0 foreach c { delete c } }", 'uses an aggregate'],
				'delete current on relation'    => ["define function f () void { {$range} cursor c = retrieve (u.id, u.posts.title) where u.id > 0 foreach c { delete c } }", "reads related entity 'u.posts.title'"],
				'replace current unknown column'=> ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 foreach c { replace c (nickname = \"x\") } }", "'nickname' is not a column"],
				'void returns a value'          => ['define function f () void { return 1 }', "A void routine can't return a value"],
				'return only in if'             => ['define function f (integer n) integer { if (n > 0) { return 1 } }', 'Not every path'],
				'elseif without else'           => ['define function f (integer n) integer { if (n > 0) { return 1 } elseif (n < 0) { return -1 } }', 'Not every path'],
				'return only in loop'           => ['define function f (integer n) integer { while (n > 0) { return 1 } }', 'Not every path'],
				'abort outside transaction'     => ['define function f () void { abort }', "only valid inside 'begin transaction"],
				'statement after abort'         => ['define function f (integer n) void { begin transaction { if (n > 0) { abort } n = 1 } }', "A statement follows 'abort'"],
				'statement after abort in if'   => ['define function f (integer n) void { begin transaction { if (n > 0) { abort n = 1 } } }', "A statement follows 'abort'"],
				'abort in a loop'               => ['define function f (integer n) void { begin transaction { while (n > 0) { abort } } }', 'inside a loop'],
				'nested transactions'           => ['define function f () void { begin transaction { begin transaction { } } }', "can't be nested"],
				'return inside transaction'     => ['define function f () integer { begin transaction { return 1 } }', "'return' inside 'begin transaction"],
				'break at top level'            => ['define function f () void { break }', "'break' is only valid inside 'while' or 'foreach'"],
				'continue in if without loop'   => ['define function f (integer n) void { if (n > 0) { continue } }', "'continue' is only valid inside 'while' or 'foreach'"],
				'break out of transaction'      => ['define function f (integer n) void { while (n > 0) { begin transaction { if (n = 5) { break } } } }', "'break' would leave 'begin transaction { }' without committing it"],
				'continue out of transaction'   => ['define function f (integer n) void { while (n > 0) { begin transaction { continue } } }', "'continue' would leave 'begin transaction { }'"],
			];
		}

		/**
		 * @param string $source Routine source text
		 * @param string $message Fragment of the expected SemanticException message
		 * @return void
		 */
		#[DataProvider('rejectedRoutines')]
		public function testRejects(string $source, string $message): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage($message);
			$this->analyze($source);
		}
	}
