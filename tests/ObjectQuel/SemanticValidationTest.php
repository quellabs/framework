<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	
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
		// Subquery range rules
		// -------------------------------------------------------------------------
		
		public function testEntireSubqueryRangeInValueListThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x)
		"));
		}
		
		public function testSubqueryPropertyAccessIsAllowed(): void {
			// Should not throw — x.id is a valid scalar reference
			$result = $this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x.id)
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