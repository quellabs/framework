<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureParser;
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
					foreach (users as row) {
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
		 * A row binding's field inside its own loop is typed CursorRoot/CursorField, also inside an
		 * embedded append; the identifier is rewritten to the cursor's own name during analysis.
		 * @return void
		 */
		public function testTypesCursorFieldReads(): void {
			$routine = $this->analyze('
				define function copy_titles () void {
					range of u is UserEntity
					range of p is PostEntity
					cursor users = retrieve (name = u.username) where u.banned = false
					foreach (users as row) {
						append to p (title = row.name, content = "")
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
		 * Two cursors, nested loops writing through their row's own field, and sequential reuse
		 * of the same cursor's row-binding name across separate (non-nested) loops.
		 * @return void
		 */
		public function testAcceptsNestedLoopsAndSequentialReuse(): void {
			$this->analyze('
				define function purge () void {
					range of u is UserEntity
					cursor banned = retrieve (u.id) where u.banned = true
					cursor early = retrieve (u.id) where u.id < 5
					foreach (banned as row) {
						foreach (early as earlyRow) {
							delete u where u.id = earlyRow.id
						}
						delete u where u.id = row.id
					}
					foreach (banned as row) {
						replace u (banned = false) where u.id = row.id
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * A nested row binding takes precedence, then the outer row is visible again.
		 * @return void
		 */
		public function testNestedForeachRowsShadowAndRestoreOuterBinding(): void {
			$routine = $this->analyze('
				define function f () void {
					range of u is UserEntity
					integer x = 0
					cursor a = retrieve (u.id) where u.id > 0
					cursor b = retrieve (u.id) where u.id > 1
					foreach (a as row) {
						x = row.id
						foreach (b as row) { x = row.id }
						x = row.id
					}
				}
			');
			$collector = new CollectNodes(AstIdentifier::class);
			$routine->accept($collector);
			$rows = array_values(array_filter($collector->getCollectedNodes(), fn(AstIdentifier $node) => $node->getType() === IdentifierType::CursorRoot));

			self::assertSame(['a.id', 'b.id', 'a.id'], array_map(fn(AstIdentifier $node) => $node->getCompleteName(), $rows));
		}

		/**
		 * rollback as the last statement of one branch is legal; the other branch may continue.
		 * @return void
		 */
		public function testAcceptsRollbackAsLastStatementOnItsPath(): void {
			$this->analyze('
				define function rename (integer userId, string newName) void {
					range of u is UserEntity
					atomic {
						replace u (username = newName) where u.id = userId
						if (newName = "") {
							rollback
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
		 * A bare `return` (no value) is a valid early exit in a void routine.
		 * @return void
		 */
		public function testAcceptsBareReturnInVoidRoutine(): void {
			$this->analyze('
				define function maybe_ban (integer targetId) void {
					range of u is UserEntity
					if (targetId <= 0) {
						return
					}
					replace u (banned = true) where u.id = targetId
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * A bare `return` followed by an explicit `;` parses the same as one with none.
		 * @return void
		 */
		public function testAcceptsBareReturnWithSemicolon(): void {
			$this->analyze('define function f () void { return; }');
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
		 * break/continue in a loop inside an atomic block, and in the inner loop of a nested pair.
		 * @return void
		 */
		public function testAcceptsBreakAndContinueInsideTheirLoop(): void {
			$this->analyze('
				define function f (integer n) void {
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.id > 0
					atomic {
						while (n > 0) {
							if (n = 5) {
								break
							}
							n = n - 1
						}
					}
					while (n < 10) {
						foreach (users as row) {
							if (row.id = n) {
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
		 * A cursor is a pure read source now: `unique` and an aggregate are both fine in its
		 * query, since nothing about the loop identifies a single table row to write back to —
		 * any write inside the loop is an ordinary, independently-targeted replace/delete with
		 * its own ordinary WHERE.
		 * @return void
		 */
		public function testAcceptsAggregateAndUniqueCursors(): void {
			$this->analyze('
				define function f () void {
					range of u is UserEntity
					cursor stats = retrieve unique (total = count(u.id)) where u.banned = false
					foreach (stats as row) {
						if (row.total > 10) {
							replace u (banned = true) where u.banned = false
						}
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * A local declared inside a block is invisible outside it, so a sibling branch may
		 * reuse the same name for an unrelated local.
		 * @return void
		 */
		public function testAcceptsBlockScopedLocalReusedInSiblingBranch(): void {
			$this->analyze('
				define function f (integer n) void {
					if (n > 0) {
						integer x = 1
						n = n + x
					} else {
						integer x = 2
						n = n + x
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * A local declared inside a loop body is analyzed once but re-runs every iteration.
		 * @return void
		 */
		public function testAcceptsLocalDeclaredInsideLoopReassignedEachIteration(): void {
			$this->analyze('
				define function f (integer n) void {
					while (n > 0) {
						integer x = n
						x = x + 1
						n = n - x
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * A local declared inside a `foreach` body, reading the current row.
		 * @return void
		 */
		public function testAcceptsLocalDeclaredInsideForeach(): void {
			$this->analyze('
				define function f () void {
					range of u is UserEntity
					cursor c = retrieve (u.id) where u.banned = true
					foreach (c as row) {
						integer x = row.id
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * A cursor declared and looped over inside an `if` branch; the sibling branch reuses
		 * the same name for a differently-queried cursor.
		 * @return void
		 */
		public function testAcceptsBlockScopedCursorReusedInSiblingBranch(): void {
			$this->analyze('
				define function f (integer n) void {
					range of u is UserEntity
					if (n > 0) {
						cursor c = retrieve (u.id) where u.banned = true
						foreach (c as row) {
							n = n + 1
						}
					} else {
						cursor c = retrieve (u.id) where u.banned = false
						foreach (c as row) {
							n = n - 1
						}
					}
				}
			');

			$this->addToAssertionCount(1);
		}

		/**
		 * A nested local uses a fresh SQL name, while the outer local is restored afterward.
		 * @return void
		 */
		public function testLocalShadowsOuterLocalWithoutLeaking(): void {
			$routine = $this->analyze('define function f () integer { integer x = 1 if (x > 0) { integer x = 2 x = x + 1 } x = x + 1 return x }');
			$declarations = new CollectNodes(AstDeclare::class);
			$assignments = new CollectNodes(AstVariableAssignment::class);
			$routine->accept($declarations);
			$routine->accept($assignments);

			self::assertSame(['x', 'x_2'], array_map(fn(AstDeclare $node) => $node->getName(), $declarations->getCollectedNodes()));
			self::assertSame(['x_2', 'x'], array_map(fn(AstVariableAssignment $node) => $node->getName(), $assignments->getCollectedNodes()));
			self::assertSame(IdentifierType::RoutineVariable, $this->rootIdentifierTypes($routine)['x_2']);
		}

		/**
		 * A nested cursor resolves its loop to the new name and leaves the outer cursor visible later.
		 * @return void
		 */
		public function testCursorShadowsOuterCursorWithoutLeaking(): void {
			$routine = $this->analyze('
				define function f () void {
					range of u is UserEntity
					cursor c = retrieve (u.id) where u.id > 0
					if (1 = 1) {
						cursor c = retrieve (u.id) where u.id > 1
						foreach (c as innerRow) { }
					}
					foreach (c as outerRow) { }
				}
			');
			$declarations = new CollectNodes(AstDeclare::class);
			$loops = new CollectNodes(AstForeach::class);
			$routine->accept($declarations);
			$routine->accept($loops);

			self::assertSame(['c', 'c_2'], array_map(fn(AstDeclare $node) => $node->getName(), $declarations->getCollectedNodes()));
			self::assertSame(['c_2', 'c'], array_map(fn(AstForeach $node) => $node->getCursorName(), $loops->getCollectedNodes()));
		}

		/**
		 * `c = retrieve (...)` rebinds a cursor to a fresh resolved name; an earlier `foreach`
		 * keeps reading the original query, a later one reads the new one.
		 * @return void
		 */
		public function testAcceptsCursorRebindBetweenSequentialLoops(): void {
			$routine = $this->analyze('
				define function f () void {
					range of u is UserEntity
					cursor c = retrieve (u.id) where u.banned = false
					foreach (c as row) { }
					c = retrieve (u.id) where u.banned = true
					foreach (c as row) { }
				}
			');
			$assignments = new CollectNodes(AstVariableAssignment::class);
			$loops = new CollectNodes(AstForeach::class);
			$routine->accept($assignments);
			$routine->accept($loops);

			self::assertSame(['c_2'], array_map(fn(AstVariableAssignment $node) => $node->getName(), $assignments->getCollectedNodes()));
			self::assertSame(['c', 'c_2'], array_map(fn(AstForeach $node) => $node->getCursorName(), $loops->getCollectedNodes()));
		}

		/**
		 * A rebind inside an `if` block only shadows the cursor for that block; the loop after
		 * the block still reads the original query.
		 * @return void
		 */
		public function testCursorRebindInsideIfDoesNotLeak(): void {
			$routine = $this->analyze('
				define function f (integer n) void {
					range of u is UserEntity
					cursor c = retrieve (u.id) where u.banned = false
					if (n > 0) {
						c = retrieve (u.id) where u.banned = true
						foreach (c as innerRow) { }
					}
					foreach (c as outerRow) { }
				}
			');
			$loops = new CollectNodes(AstForeach::class);
			$routine->accept($loops);

			self::assertSame(['c_2', 'c'], array_map(fn(AstForeach $node) => $node->getCursorName(), $loops->getCollectedNodes()));
		}

		/**
		 * A rebind inside a cursor's own open `foreach` shadows it to a new resolved name;
		 * the enclosing loop keeps iterating the original, unaffected.
		 * @return void
		 */
		public function testAcceptsCursorRebindInsideItsOwnOpenLoop(): void {
			$routine = $this->analyze('
				define function f () void {
					range of u is UserEntity
					cursor c = retrieve (u.id) where u.id > 0
					foreach (c as row) {
						c = retrieve (u.id) where u.id > 1
					}
				}
			');
			$loops = new CollectNodes(AstForeach::class);
			$routine->accept($loops);

			self::assertSame(['c'], array_map(fn(AstForeach $node) => $node->getCursorName(), $loops->getCollectedNodes()));
		}

		/**
		 * A nearer declaration wins even when it has a different kind from the outer name.
		 * @return void
		 */
		public function testShadowingAcrossScalarAndCursorKinds(): void {
			$routine = $this->analyze('
				define function f () integer {
					integer x = 1
					range of u is UserEntity
					if (x > 0) {
						cursor x = retrieve (u.id)
						foreach (x as row) { }
					}
					return x
				}
			');
			$loops = new CollectNodes(AstForeach::class);
			$routine->accept($loops);
			self::assertSame('x_2', $loops->getCollectedNodes()[0]->getCursorName());
		}

		/**
		 * A nested scalar hides an outer cursor until the block ends.
		 * @return void
		 */
		public function testScalarShadowsOuterCursor(): void {
			$routine = $this->analyze('
				define function f () void {
					range of u is UserEntity
					cursor c = retrieve (u.id)
					if (1 = 1) {
						integer c = 2
						c = c + 1
					}
					foreach (c as row) { }
				}
			');
			$assignments = new CollectNodes(AstVariableAssignment::class);
			$loops = new CollectNodes(AstForeach::class);
			$routine->accept($assignments);
			$routine->accept($loops);
			self::assertSame('c_2', $assignments->getCollectedNodes()[0]->getName());
			self::assertSame('c', $loops->getCollectedNodes()[0]->getCursorName());
		}

		/**
		 * A nested local can shadow a parameter, then the parameter is visible again afterward.
		 * @return void
		 */
		public function testLocalShadowsParameter(): void {
			$routine = $this->analyze('define function f (integer n) integer { if (n > 0) { integer n = 2 n = n + 1 } return n }');
			$declarations = new CollectNodes(AstDeclare::class);
			$assignments = new CollectNodes(AstVariableAssignment::class);
			$routine->accept($declarations);
			$routine->accept($assignments);
			self::assertSame('n_2', $declarations->getCollectedNodes()[0]->getName());
			self::assertSame('n_2', $assignments->getCollectedNodes()[0]->getName());
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
				'JSON range declaration'         => ['define function f () void { range of j is json_source("data.json") }', "JSON ranges aren't supported in routines"],
				'JSON range in retrieve'         => ['define function f () void { range of j is json_source("data.json") retrieve (j.id) }', "JSON ranges aren't supported in routines"],
				'void parameter'                => ['define function f (void n) void { }', "Unknown type 'void' for parameter"],
				'cursor parameter'              => ['define function f (cursor c) void { }', "Unknown type 'cursor' for parameter"],
				'unknown return type'           => ['define function f () number { return 1 }', "Unknown return type 'number'"],
				'cursor return type'            => ['define function f () cursor { }', "Unknown return type 'cursor'"],
				'void local'                    => ['define function f () void { void x }', "Unknown type 'void' for local"],
				'cursor without initializer'    => ['define function f () void { cursor c }', 'must be initialized with a retrieve'],
				'cursor with expression'        => ['define function f () void { cursor c = 1 }', 'must be initialized with a retrieve'],
				'scalar with retrieve'          => ["define function f () void { {$range} integer x = retrieve (u.id) where u.id = 1 }", 'Only a cursor can be initialized'],
				'range inside while'            => ['define function f (integer n) void { while (n > 1) { range of u is UserEntity } }', 'must be at the top level'],
				'range inside if'               => ['define function f (integer n) void { if (n > 1) { range of u is UserEntity } }', 'must be at the top level'],
				'local redeclares parameter'    => ['define function f (integer n) void { integer n }', "'n' is already declared"],
				'local redeclares range'        => ["define function f () void { {$range} integer u }", "'u' is already declared"],
				'local duplicate in one block'  => ['define function f () void { integer x = 1 integer x = 2 }', "'x' is already declared in this scope"],
				'local shadows outer range'     => ["define function f (integer n) void { {$range} while (n > 0) { integer u } }", "'u' is already declared"],
				'range redeclares local'        => ['define function f () void { integer u range of u is UserEntity }', "'u' is already declared"],
				'statement keyword as name'     => ['define function f () void { integer foreach }', 'statement keyword'],
				'break as name'                 => ['define function f () void { integer break }', 'statement keyword'],
				'atomic as name'                => ['define function f () void { integer atomic }', 'statement keyword'],
				'rollback as name'              => ['define function f (integer rollback) void { }', 'statement keyword'],
				'elseif as name'                => ['define function f (integer elseif) void { }', 'statement keyword'],
				'locals differing in case'      => ['define function f () void { integer total integer Total }', "'total' and 'Total' differ only in case"],
				'nested locals differing case'  => ['define function f () void { integer total if (1 = 1) { integer Total } }', "'total' and 'Total' differ only in case"],
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
				'whole entity target'           => ["define function f () void { {$range} cursor c = retrieve (u) where u.id > 0 }", 'not whole entities'],
				'cursor as a value'             => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 integer x = c }", "Cursor 'c' is not a value"],
				'cursor assigned a scalar'       => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 c = 1 }", "Cursor 'c' can only be assigned a retrieve"],
				'inner cursor hides scalar'     => ["define function f () void { integer x = 1 {$range} if (x > 0) { cursor x = retrieve (u.id) x = 2 } }", "Cursor 'x' can only be assigned a retrieve"],
				'inner scalar hides cursor'     => ["define function f () void { {$range} cursor c = retrieve (u.id) if (1 = 1) { integer c = 2 foreach (c as row) { } } }", "needs a cursor"],
				'cursor incremented'            => ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 c += 1 }", "Cursor 'c' can only be assigned a retrieve"],
				'cursor field outside loop'     => ["define function f () integer { {$range} cursor c = retrieve (u.id) where u.id > 0 return c.id }", "Cursor 'c' is not a value; read its fields through its 'foreach (c as row)' binding"],
				'missing cursor field'          => ["define function f () void { {$range} integer x cursor c = retrieve (u.id) where u.id > 0 foreach (c as row) { x = row.username } }", "has no field 'username'"],
				'foreach over scalar'           => ['define function f (integer n) void { foreach (n as row) { } }', "needs a cursor, but 'n' is not one"],
				'foreach undefined'             => ['define function f () void { foreach (c as row) { } }', "Undefined cursor 'c'"],
				'foreach on open cursor'        => ["define function f () void { {$range} cursor a = retrieve (u.id) where u.id > 0 cursor b = retrieve (u.id) where u.id > 1 foreach (a as ra) { foreach (b as rb) { foreach (a as ra2) { } } } }", 'same cursor'],
				'foreach row name is its cursor'=> ["define function f () void { {$range} cursor c = retrieve (u.id) where u.id > 0 foreach (c as c) { } }", "'c' is already in use"],
				'void returns a value'          => ['define function f () void { return 1 }', "A void routine can't return a value"],
				'bare return in non-void'       => ['define function f () integer { return }', 'must return a value'],
				'return only in if'             => ['define function f (integer n) integer { if (n > 0) { return 1 } }', 'Not every path'],
				'elseif without else'           => ['define function f (integer n) integer { if (n > 0) { return 1 } elseif (n < 0) { return -1 } }', 'Not every path'],
				'return only in loop'           => ['define function f (integer n) integer { while (n > 0) { return 1 } }', 'Not every path'],
				'rollback outside atomic'       => ['define function f () void { rollback }', "only valid inside 'atomic"],
				'statement after rollback'      => ['define function f (integer n) void { atomic { if (n > 0) { rollback } n = 1 } }', "A statement follows 'rollback'"],
				'statement after rollback in if'=> ['define function f (integer n) void { atomic { if (n > 0) { rollback n = 1 } } }', "A statement follows 'rollback'"],
				'rollback in a loop'            => ['define function f (integer n) void { atomic { while (n > 0) { rollback } } }', 'inside a loop'],
				'nested atomic blocks'          => ['define function f () void { atomic { atomic { } } }', "can't be nested"],
				'return inside atomic'          => ['define function f () integer { atomic { return 1 } }', "'return' inside 'atomic"],
				'bare return inside atomic'     => ['define function f () void { atomic { return } }', "'return' inside 'atomic"],
				'break at top level'            => ['define function f () void { break }', "'break' is only valid inside 'while' or 'foreach'"],
				'continue in if without loop'   => ['define function f (integer n) void { if (n > 0) { continue } }', "'continue' is only valid inside 'while' or 'foreach'"],
				'break out of atomic'           => ['define function f (integer n) void { while (n > 0) { atomic { if (n = 5) { break } } } }', "'break' would leave 'atomic { }' without finishing it"],
				'continue out of atomic'        => ['define function f (integer n) void { while (n > 0) { atomic { continue } } }', "'continue' would leave 'atomic { }'"],
				'scalar assigned a retrieve'    => ["define function f () void { {$range} integer x = 1 x = retrieve (u.id) where u.id > 0 }", "declared as a scalar, so it can't be assigned a retrieve"],
				'undeclared assigned a retrieve'=> ["define function f () void { {$range} y = retrieve (u.id) where u.id > 0 }", "undeclared variable 'y'"],
				'range assigned a retrieve'     => ["define function f () void { {$range} u = retrieve (u.id) where u.id > 0 }", "Range 'u' can't be assigned"],
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
