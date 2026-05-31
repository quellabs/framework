<?php
	
	namespace Quellabs\Canvas\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\SegmentTypes;
	
	/**
	 * Unit tests for SegmentTypes — the segment-type constant class and its
	 * static classification helpers.
	 *
	 * SegmentTypes has no I/O or external dependencies, making it ideal for
	 * pure unit testing. Every branch of each helper is covered.
	 */
	class SegmentTypesTest extends TestCase {
		
		// -------------------------------------------------------------------------
		// isStatic
		// -------------------------------------------------------------------------
		
		public function testIsStaticReturnsTrueForStaticSegment(): void {
			$segment = ['type' => SegmentTypes::STATIC];
			$this->assertTrue(SegmentTypes::isStatic($segment));
		}
		
		public function testIsStaticReturnsFalseForVariableSegment(): void {
			$segment = ['type' => SegmentTypes::VARIABLE];
			$this->assertFalse(SegmentTypes::isStatic($segment));
		}
		
		public function testIsStaticReturnsFalseForWildcardSegment(): void {
			$segment = ['type' => SegmentTypes::SINGLE_WILDCARD];
			$this->assertFalse(SegmentTypes::isStatic($segment));
		}
		
		// -------------------------------------------------------------------------
		// isWildcard
		// -------------------------------------------------------------------------
		
		public function testIsWildcardReturnsTrueForSingleWildcard(): void {
			$segment = ['type' => SegmentTypes::SINGLE_WILDCARD];
			$this->assertTrue(SegmentTypes::isWildcard($segment));
		}
		
		public function testIsWildcardReturnsTrueForMultiWildcard(): void {
			$segment = ['type' => SegmentTypes::MULTI_WILDCARD];
			$this->assertTrue(SegmentTypes::isWildcard($segment));
		}
		
		public function testIsWildcardReturnsTrueForMultiWildcardVar(): void {
			$segment = ['type' => SegmentTypes::MULTI_WILDCARD_VAR];
			$this->assertTrue(SegmentTypes::isWildcard($segment));
		}
		
		public function testIsWildcardReturnsFalseForStaticSegment(): void {
			$segment = ['type' => SegmentTypes::STATIC];
			$this->assertFalse(SegmentTypes::isWildcard($segment));
		}
		
		public function testIsWildcardReturnsFalseForVariableSegment(): void {
			$segment = ['type' => SegmentTypes::VARIABLE];
			$this->assertFalse(SegmentTypes::isWildcard($segment));
		}
		
		// -------------------------------------------------------------------------
		// isMultiWildcard
		// -------------------------------------------------------------------------
		
		public function testIsMultiWildcardReturnsTrueForMultiWildcardType(): void {
			$segment = ['type' => SegmentTypes::MULTI_WILDCARD];
			$this->assertTrue(SegmentTypes::isMultiWildcard($segment));
		}
		
		public function testIsMultiWildcardReturnsTrueForMultiWildcardVarType(): void {
			$segment = ['type' => SegmentTypes::MULTI_WILDCARD_VAR];
			$this->assertTrue(SegmentTypes::isMultiWildcard($segment));
		}
		
		public function testIsMultiWildcardReturnsTrueForVariableSegmentWithFlagSet(): void {
			$segment = ['type' => SegmentTypes::VARIABLE, 'is_multi_wildcard' => true];
			$this->assertTrue(SegmentTypes::isMultiWildcard($segment));
		}
		
		public function testIsMultiWildcardReturnsFalseForVariableSegmentWithoutFlag(): void {
			$segment = ['type' => SegmentTypes::VARIABLE, 'is_multi_wildcard' => false];
			$this->assertFalse(SegmentTypes::isMultiWildcard($segment));
		}
		
		public function testIsMultiWildcardReturnsTrueForPartialVariableWithFlagSet(): void {
			$segment = ['type' => SegmentTypes::PARTIAL_VARIABLE, 'is_multi_wildcard' => true];
			$this->assertTrue(SegmentTypes::isMultiWildcard($segment));
		}
		
		public function testIsMultiWildcardReturnsFalseForSingleWildcard(): void {
			$segment = ['type' => SegmentTypes::SINGLE_WILDCARD];
			$this->assertFalse(SegmentTypes::isMultiWildcard($segment));
		}
		
		public function testIsMultiWildcardReturnsFalseForStaticSegment(): void {
			$segment = ['type' => SegmentTypes::STATIC];
			$this->assertFalse(SegmentTypes::isMultiWildcard($segment));
		}
		
		// -------------------------------------------------------------------------
		// hasVariables
		// -------------------------------------------------------------------------
		
		public function testHasVariablesReturnsTrueForVariableSegment(): void {
			$segment = ['type' => SegmentTypes::VARIABLE];
			$this->assertTrue(SegmentTypes::hasVariables($segment));
		}
		
		public function testHasVariablesReturnsTrueForPartialVariableSegment(): void {
			$segment = ['type' => SegmentTypes::PARTIAL_VARIABLE];
			$this->assertTrue(SegmentTypes::hasVariables($segment));
		}
		
		public function testHasVariablesReturnsTrueForSingleWildcard(): void {
			$segment = ['type' => SegmentTypes::SINGLE_WILDCARD];
			$this->assertTrue(SegmentTypes::hasVariables($segment));
		}
		
		public function testHasVariablesReturnsTrueForMultiWildcard(): void {
			$segment = ['type' => SegmentTypes::MULTI_WILDCARD];
			$this->assertTrue(SegmentTypes::hasVariables($segment));
		}
		
		public function testHasVariablesReturnsTrueForMultiWildcardVar(): void {
			$segment = ['type' => SegmentTypes::MULTI_WILDCARD_VAR];
			$this->assertTrue(SegmentTypes::hasVariables($segment));
		}
		
		public function testHasVariablesReturnsFalseForStaticSegment(): void {
			$segment = ['type' => SegmentTypes::STATIC];
			$this->assertFalse(SegmentTypes::hasVariables($segment));
		}
		
		// -------------------------------------------------------------------------
		// Constants are unique and non-empty
		// -------------------------------------------------------------------------
		
		public function testAllConstantsAreNonEmptyStrings(): void {
			$constants = [
				SegmentTypes::STATIC,
				SegmentTypes::VARIABLE,
				SegmentTypes::SINGLE_WILDCARD,
				SegmentTypes::MULTI_WILDCARD,
				SegmentTypes::MULTI_WILDCARD_VAR,
				SegmentTypes::PARTIAL_VARIABLE,
			];
			
			foreach ($constants as $constant) {
				$this->assertIsString($constant);
				$this->assertNotEmpty($constant);
			}
		}
		
		public function testAllConstantsAreDistinct(): void {
			$constants = [
				SegmentTypes::STATIC,
				SegmentTypes::VARIABLE,
				SegmentTypes::SINGLE_WILDCARD,
				SegmentTypes::MULTI_WILDCARD,
				SegmentTypes::MULTI_WILDCARD_VAR,
				SegmentTypes::PARTIAL_VARIABLE,
			];
			
			// array_unique preserves values; if count drops the constants collide
			$this->assertCount(count($constants), array_unique($constants));
		}
	}