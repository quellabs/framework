<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	
	/**
	 * Tests the semantic analyzer's validation rules in isolation.
	 * These tests require no fixture data — they only care whether the
	 * query is accepted or rejected before hitting the database.
	 *
	 * SemanticException is an internal exception that never leaks through
	 * the public API — executeQuery() always wraps it as QuelException with
	 * the SemanticException as getPrevious(). All tests here assert on that
	 * public contract.
	 */
	class SemanticValidationTest extends ObjectQuelTestCase {
		
		/**
		 * Asserts that a query throws a QuelException caused by a SemanticException.
		 * This is the correct way to test semantic validation since SemanticException
		 * is an internal exception that is always wrapped before reaching the caller.
		 */
		private function assertSemanticError(callable $fn): void {
			try {
				$fn();
				$this->fail('Expected QuelException to be thrown');
			} catch (QuelException $e) {
				$this->assertInstanceOf(
					SemanticException::class,
					$e->getPrevious(),
					'Expected QuelException to wrap a SemanticException'
				);
			}
		}
		
		/**
		 * Asserts that a query throws a QuelException caused by a ParserException.
		 */
		private function assertParserError(callable $fn): void {
			try {
				$fn();
				$this->fail('Expected QuelException to be thrown');
			} catch (QuelException $e) {
				$this->assertInstanceOf(
					ParserException::class,
					$e->getPrevious(),
					'Expected QuelException to wrap a ParserException'
				);
			}
		}
		
		// -------------------------------------------------------------------------
		// Duplicate range names
		// -------------------------------------------------------------------------
		
		public function testDuplicateRangeNameThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				range of p is UserEntity
				retrieve (p)
			"));
		}
		
		// -------------------------------------------------------------------------
		// Aggregates in WHERE clause
		// -------------------------------------------------------------------------
		
		public function testAggregateInWhereClauseThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where count(p.id) > 1
			"));
		}
		
		// -------------------------------------------------------------------------
		// RegExp in value list
		// -------------------------------------------------------------------------
		
		public function testRegExpInValueListThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				retrieve (/foo/)
			"));
		}
		
		// -------------------------------------------------------------------------
		// Empty projection
		// -------------------------------------------------------------------------
		
		public function testEmptyRetrieveThrows(): void {
			// retrieve() with no arguments is rejected at parse time, not semantic analysis
			$this->assertParserError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				retrieve ()
			"));
		}
		
		// -------------------------------------------------------------------------
		// Subquery range rules
		// -------------------------------------------------------------------------
		
		public function testEntireSubqueryRangeInValueListThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of x is (
					range of y is PostEntity
					retrieve(y.id, y.title)
				)
				retrieve (x)
			"));
		}
		
		public function testBareEntityInSubqueryProjectionThrows(): void {
			// retrieve(y) inside a subquery must be rejected — the subquery's
			// projection is its column-set contract and must be explicit.
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of x is (
					range of y is PostEntity
					retrieve(y)
				)
				retrieve (x.id)
			"));
		}
		
		public function testBareJsonSourceRangeInProjectionThrows(): void {
			// retrieve(y) where y is a json source range must be rejected —
			// it produces empty arrays at runtime because the engine has no schema to hydrate from.
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of y is json_source('f:\\\\test.json', '$.rows')
				retrieve(y)
			"));
		}
		
		public function testBareJsonSourceRangeInWhereThrows(): void {
			// where y = 10 where y is a bare json source range must be rejected —
			// JsonRoot identifiers are not valid operands in expressions.
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of y is json_source('f:\\\\test.json', '$.rows')
				retrieve(y.id)
				where y = 10
			"));
		}
		
		public function testWhereReferenceToUnexportedSubqueryFieldThrows(): void {
			// x only exports 'hello'; referencing x.id in WHERE must be rejected
			// at semantic analysis time, before any execution.
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of x is (
					range of a is PostEntity
					retrieve(hello=a.id)
				)
				retrieve(x.hello)
				where x.id = 1
			"));
		}
		
		public function testProjectionReferenceToUnexportedSubqueryFieldThrows(): void {
			// x only exports 'id'; referencing x.title in the outer retrieve list must be
			// rejected at semantic analysis time, before any execution.
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of x is (
					range of y is PostEntity
					retrieve(y.id)
				)
				retrieve(x.id, x.title)
			"));
		}
		
		public function testSubqueryPropertyAccessIsAllowed(): void {
			// Should not throw — x.id is a valid scalar reference into an explicit projection
			$result = $this->em->executeQuery("
				range of x is (
					range of y is PostEntity
					retrieve(y.id)
				)
				retrieve (x.id)
			");
			
			$this->assertNotNull($result);
		}
		
		public function testSubqueryAliasedExportIsAccessible(): void {
			// Should not throw — the inner query exports 'hello' as an alias for y.id,
			// and the outer query references x.hello, which is a valid exported name.
			$result = $this->em->executeQuery("
				range of x is (
					range of a is PostEntity
					retrieve(hello=a.id)
				)
				retrieve(x.hello)
			");
			
			$this->assertNotNull($result);
		}
		
		// -------------------------------------------------------------------------
		// Expressions on entire entities
		// -------------------------------------------------------------------------
		
		public function testArithmeticOnEntityThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where p + 1 = 2
			"));
		}
	}