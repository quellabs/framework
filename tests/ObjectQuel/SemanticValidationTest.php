<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\SemanticException;
	
	/**
	 * Tests the semantic analyzer's validation rules in isolation.
	 * These tests require no fixture data — they only care whether the
	 * query is accepted or rejected before hitting the database.
	 */
	class SemanticValidationTest extends ObjectQuelTestCase {
		
		// -------------------------------------------------------------------------
		// Duplicate range names
		// -------------------------------------------------------------------------
		
		public function testDuplicateRangeNameThrows(): void {
			$this->expectException(SemanticException::class);
			
			$this->em->executeQuery("
			range of p is PostEntity
			range of p is UserEntity
			retrieve (p)
		");
		}
		
		// -------------------------------------------------------------------------
		// Missing primary range (all ranges have via)
		// -------------------------------------------------------------------------
		
		public function testAllRangesWithViaThrows(): void {
			$this->expectException(SemanticException::class);
			
			// No bare range — every range has a via, so there's no FROM table
			$this->em->executeQuery("
			range of u is UserEntity via p.user
			retrieve (u)
		");
		}
		
		// -------------------------------------------------------------------------
		// Aggregates in WHERE clause
		// -------------------------------------------------------------------------
		
		public function testAggregateInWhereClauseThrows(): void {
			$this->expectException(SemanticException::class);
			
			$this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.id)
			where count(p.id) > 1
		");
		}
		
		// -------------------------------------------------------------------------
		// RegExp in value list
		// -------------------------------------------------------------------------
		
		public function testRegExpInValueListThrows(): void {
			$this->expectException(SemanticException::class);
			
			$this->em->executeQuery("
			range of p is PostEntity
			retrieve (/foo/)
		");
		}
		
		// -------------------------------------------------------------------------
		// Subquery range rules
		// -------------------------------------------------------------------------
		
		public function testEntireSubqueryRangeInValueListThrows(): void {
			$this->expectException(SemanticException::class);
			
			$this->em->executeQuery("
			range of x is (
				range of y is PostEntity
				retrieve(y)
			)
			retrieve (x)
		");
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
			$this->expectException(SemanticException::class);
			
			$this->em->executeQuery("
			range of p is PostEntity
			retrieve (p.id)
			where p + 1 = 2
		");
		}
	}