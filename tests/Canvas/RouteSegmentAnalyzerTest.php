<?php
	
	namespace Quellabs\Canvas\Tests\Routing;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\Components\RouteSegmentAnalyzer;
	use Quellabs\Canvas\Routing\SegmentTypes;
	
	/**
	 * Unit tests for RouteSegmentAnalyzer.
	 *
	 * Covers segment type classification, variable name extraction,
	 * priority calculation, and route classification.
	 */
	class RouteSegmentAnalyzerTest extends TestCase {
		
		private RouteSegmentAnalyzer $analyzer;
		
		protected function setUp(): void {
			$this->analyzer = new RouteSegmentAnalyzer();
		}
		
		// -------------------------------------------------------------------------
		// getSegmentType
		// -------------------------------------------------------------------------
		
		public function testGetSegmentTypeReturnsStaticForLiteralSegment(): void {
			$this->assertSame(SegmentTypes::STATIC, $this->analyzer->getSegmentType('users'));
		}
		
		public function testGetSegmentTypeReturnsStaticForNumericLiteral(): void {
			$this->assertSame(SegmentTypes::STATIC, $this->analyzer->getSegmentType('42'));
		}
		
		public function testGetSegmentTypeReturnsVariableForSimpleVariable(): void {
			$this->assertSame(SegmentTypes::VARIABLE, $this->analyzer->getSegmentType('{id}'));
		}
		
		public function testGetSegmentTypeReturnsVariableForTypedVariable(): void {
			$this->assertSame(SegmentTypes::VARIABLE, $this->analyzer->getSegmentType('{id:int}'));
		}
		
		public function testGetSegmentTypeReturnsVariableForAlphaTypedVariable(): void {
			$this->assertSame(SegmentTypes::VARIABLE, $this->analyzer->getSegmentType('{slug:alpha}'));
		}
		
		public function testGetSegmentTypeReturnsSingleWildcard(): void {
			$this->assertSame(SegmentTypes::SINGLE_WILDCARD, $this->analyzer->getSegmentType('*'));
		}
		
		public function testGetSegmentTypeReturnsMultiWildcard(): void {
			$this->assertSame(SegmentTypes::MULTI_WILDCARD, $this->analyzer->getSegmentType('**'));
		}
		
		public function testGetSegmentTypeReturnsMultiWildcardVarForNamedDoubleStarVariable(): void {
			$this->assertSame(SegmentTypes::MULTI_WILDCARD_VAR, $this->analyzer->getSegmentType('{path:**}'));
		}
		
		public function testGetSegmentTypeReturnsMultiWildcardVarForDotStarVariable(): void {
			$this->assertSame(SegmentTypes::MULTI_WILDCARD_VAR, $this->analyzer->getSegmentType('{path:.*}'));
		}
		
		public function testGetSegmentTypeReturnsPartialVariableForPrefixedVariable(): void {
			$this->assertSame(SegmentTypes::PARTIAL_VARIABLE, $this->analyzer->getSegmentType('v{version}'));
		}
		
		public function testGetSegmentTypeReturnsPartialVariableForSuffixedVariable(): void {
			$this->assertSame(SegmentTypes::PARTIAL_VARIABLE, $this->analyzer->getSegmentType('{name}.json'));
		}
		
		public function testGetSegmentTypeReturnsPartialVariableForEmbeddedVariable(): void {
			$this->assertSame(SegmentTypes::PARTIAL_VARIABLE, $this->analyzer->getSegmentType('file-{id}-data'));
		}

		public function testGetSegmentTypeReturnsPartialVariableForTwoVariablesWithNoLiteralOutsideBraces(): void {
			// "{id}-{id}" starts with '{' and ends with '}' like a complete variable,
			// but contains two separate brace groups — it must be classified as a
			// partial variable so each one is parsed individually, not trimmed into
			// a single garbage variable name.
			$this->assertSame(SegmentTypes::PARTIAL_VARIABLE, $this->analyzer->getSegmentType('{id}-{id}'));
		}

		public function testGetSegmentTypeReturnsPartialVariableForTwoAdjacentVariables(): void {
			$this->assertSame(SegmentTypes::PARTIAL_VARIABLE, $this->analyzer->getSegmentType('{id}{slug}'));
		}

		// -------------------------------------------------------------------------
		// isVariable / hasPartialVariable
		// -------------------------------------------------------------------------

		public function testIsVariableReturnsTrueForCompleteVariable(): void {
			$this->assertTrue($this->analyzer->isVariable('{id}'));
		}

		public function testIsVariableReturnsTrueForTypedCompleteVariable(): void {
			$this->assertTrue($this->analyzer->isVariable('{id:int}'));
		}

		public function testIsVariableReturnsFalseForPartialVariable(): void {
			$this->assertFalse($this->analyzer->isVariable('v{id}'));
		}

		public function testIsVariableReturnsFalseForStaticSegment(): void {
			$this->assertFalse($this->analyzer->isVariable('users'));
		}

		public function testIsVariableReturnsFalseForTwoBraceGroupsWithNoOuterLiteral(): void {
			// Starts with '{' and ends with '}', but is not a SINGLE complete variable
			$this->assertFalse($this->analyzer->isVariable('{id}-{id}'));
		}

		public function testHasPartialVariableReturnsTrueForPrefixedSegment(): void {
			$this->assertTrue($this->analyzer->hasPartialVariable('v{version}'));
		}

		public function testHasPartialVariableReturnsFalseForCompleteVariable(): void {
			// {id} is a complete variable, not a partial one
			$this->assertFalse($this->analyzer->hasPartialVariable('{id}'));
		}

		public function testHasPartialVariableReturnsFalseForStaticSegment(): void {
			$this->assertFalse($this->analyzer->hasPartialVariable('users'));
		}

		public function testHasPartialVariableReturnsTrueForTwoBraceGroupsWithNoOuterLiteral(): void {
			$this->assertTrue($this->analyzer->hasPartialVariable('{id}-{id}'));
		}
		
		// -------------------------------------------------------------------------
		// extractVariableName
		// -------------------------------------------------------------------------
		
		public function testExtractVariableNameFromSimpleVariable(): void {
			$this->assertSame('id', $this->analyzer->extractVariableName('{id}'));
		}
		
		public function testExtractVariableNameFromTypedVariable(): void {
			$this->assertSame('id', $this->analyzer->extractVariableName('{id:int}'));
		}
		
		public function testExtractVariableNameFromMultiWildcardVariable(): void {
			$this->assertSame('path', $this->analyzer->extractVariableName('{path:**}'));
		}
		
		public function testExtractVariableNameFromSlugTypedVariable(): void {
			$this->assertSame('slug', $this->analyzer->extractVariableName('{slug:alpha}'));
		}
		
		// -------------------------------------------------------------------------
		// getSegmentPenalty
		// -------------------------------------------------------------------------
		
		public function testStaticSegmentHasZeroPenalty(): void {
			$this->assertSame(0, $this->analyzer->getSegmentPenalty(SegmentTypes::STATIC));
		}
		
		public function testVariableSegmentHasLowerPenaltyThanWildcard(): void {
			$variable = $this->analyzer->getSegmentPenalty(SegmentTypes::VARIABLE);
			$wildcard  = $this->analyzer->getSegmentPenalty(SegmentTypes::SINGLE_WILDCARD);
			$this->assertLessThan($wildcard, $variable);
		}
		
		public function testSingleWildcardHasLowerPenaltyThanMultiWildcard(): void {
			$single = $this->analyzer->getSegmentPenalty(SegmentTypes::SINGLE_WILDCARD);
			$multi  = $this->analyzer->getSegmentPenalty(SegmentTypes::MULTI_WILDCARD);
			$this->assertLessThan($multi, $single);
		}
		
		public function testMultiWildcardAndMultiWildcardVarHaveEqualPenalty(): void {
			$this->assertSame(
				$this->analyzer->getSegmentPenalty(SegmentTypes::MULTI_WILDCARD),
				$this->analyzer->getSegmentPenalty(SegmentTypes::MULTI_WILDCARD_VAR)
			);
		}
		
		// -------------------------------------------------------------------------
		// calculateRoutePriority — ordering guarantees
		// -------------------------------------------------------------------------
		
		public function testFullyStaticRouteHasHigherPriorityThanRouteWithVariable(): void {
			$static  = $this->analyzer->calculateRoutePriority('/users/profile');
			$dynamic = $this->analyzer->calculateRoutePriority('/users/{id}');
			$this->assertGreaterThan($dynamic, $static);
		}
		
		public function testRouteWithVariableHasHigherPriorityThanSingleWildcard(): void {
			$variable = $this->analyzer->calculateRoutePriority('/users/{id}');
			$wildcard = $this->analyzer->calculateRoutePriority('/users/*');
			$this->assertGreaterThan($wildcard, $variable);
		}
		
		public function testSingleWildcardHasHigherPriorityThanMultiWildcard(): void {
			$single = $this->analyzer->calculateRoutePriority('/files/*');
			$multi  = $this->analyzer->calculateRoutePriority('/files/**');
			$this->assertGreaterThan($multi, $single);
		}
		
		public function testLongerStaticRouteHasHigherPriorityThanShorterStaticRoute(): void {
			$longer  = $this->analyzer->calculateRoutePriority('/users/profile/settings');
			$shorter = $this->analyzer->calculateRoutePriority('/users/profile');
			$this->assertGreaterThan($shorter, $longer);
		}
		
		public function testPriorityIsPositiveInteger(): void {
			$priority = $this->analyzer->calculateRoutePriority('/users/{id}/posts');
			$this->assertIsInt($priority);
			$this->assertGreaterThan(0, $priority);
		}
	}