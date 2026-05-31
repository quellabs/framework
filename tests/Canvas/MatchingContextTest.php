<?php
	
	namespace Quellabs\Canvas\Tests\Routing;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\MatchingContext;
	use Quellabs\Canvas\Routing\SegmentTypes;
	
	/**
	 * Unit tests for MatchingContext.
	 *
	 * MatchingContext is a pure state machine used by matching strategies.
	 * Tests cover cursor advancement, variable collection, and final-match validation.
	 */
	class MatchingContextTest extends TestCase {
		
		private function makeStaticSegment(string $original = 'users'): array {
			return [
				'type'                     => SegmentTypes::STATIC,
				'original'                 => $original,
				'variable_name'            => null,
				'pattern'                  => null,
				'is_multi_wildcard'        => false,
				'compiled_regex'           => null,
				'variable_names'           => [],
				'literal_prefix'           => null,
				'literal_suffix'           => null,
				'remaining_segments_count' => 0,
			];
		}
		
		private function makeMultiWildcardSegment(): array {
			return [
				'type'                     => SegmentTypes::MULTI_WILDCARD,
				'original'                 => '**',
				'variable_name'            => '**',
				'pattern'                  => null,
				'is_multi_wildcard'        => true,
				'compiled_regex'           => null,
				'variable_names'           => [],
				'literal_prefix'           => null,
				'literal_suffix'           => null,
				'remaining_segments_count' => 0,
			];
		}
		
		// -------------------------------------------------------------------------
		// hasMoreSegments
		// -------------------------------------------------------------------------
		
		public function testHasMoreSegmentsReturnsTrueWhenBothHaveSegments(): void {
			$ctx = new MatchingContext(['users'], [$this->makeStaticSegment()]);
			$this->assertTrue($ctx->hasMoreSegments());
		}
		
		public function testHasMoreSegmentsReturnsFalseWhenNoUrlSegments(): void {
			$ctx = new MatchingContext([], [$this->makeStaticSegment()]);
			$this->assertFalse($ctx->hasMoreSegments());
		}
		
		public function testHasMoreSegmentsReturnsFalseWhenNoRouteSegments(): void {
			$ctx = new MatchingContext(['users'], []);
			$this->assertFalse($ctx->hasMoreSegments());
		}
		
		public function testHasMoreSegmentsReturnsFalseAfterFullAdvance(): void {
			$ctx = new MatchingContext(['users'], [$this->makeStaticSegment()]);
			$ctx->advance();
			$this->assertFalse($ctx->hasMoreSegments());
		}
		
		// -------------------------------------------------------------------------
		// getCurrentUrlSegment / getCurrentRouteSegment
		// -------------------------------------------------------------------------
		
		public function testGetCurrentUrlSegmentReturnsFirstSegment(): void {
			$ctx = new MatchingContext(['users', 'profile'], [$this->makeStaticSegment()]);
			$this->assertSame('users', $ctx->getCurrentUrlSegment());
		}
		
		public function testGetCurrentRouteSegmentReturnsFirstSegment(): void {
			$seg = $this->makeStaticSegment('users');
			$ctx = new MatchingContext(['users'], [$seg]);
			$this->assertSame($seg, $ctx->getCurrentRouteSegment());
		}
		
		// -------------------------------------------------------------------------
		// advance / advanceUrl
		// -------------------------------------------------------------------------
		
		public function testAdvanceMovesBoothIndices(): void {
			$ctx = new MatchingContext(['a', 'b'], [
				$this->makeStaticSegment('a'),
				$this->makeStaticSegment('b'),
			]);
			$ctx->advance();
			$this->assertSame('b', $ctx->getCurrentUrlSegment());
		}
		
		public function testAdvanceUrlMovesOnlyUrlIndex(): void {
			$ctx = new MatchingContext(['a', 'b', 'c'], [$this->makeStaticSegment('a')]);
			$ctx->advanceUrl(2);
			$this->assertSame('c', $ctx->getCurrentUrlSegment());
		}
		
		// -------------------------------------------------------------------------
		// getRemainingUrlSegments
		// -------------------------------------------------------------------------
		
		public function testGetRemainingUrlSegmentsReturnsAllInitially(): void {
			$ctx = new MatchingContext(['a', 'b', 'c'], [$this->makeStaticSegment()]);
			$this->assertSame(['a', 'b', 'c'], $ctx->getRemainingUrlSegments());
		}
		
		public function testGetRemainingUrlSegmentsAfterAdvance(): void {
			$ctx = new MatchingContext(['a', 'b', 'c'], [
				$this->makeStaticSegment('a'),
				$this->makeStaticSegment('b'),
			]);
			$ctx->advance();
			$this->assertSame(['b', 'c'], $ctx->getRemainingUrlSegments());
		}
		
		// -------------------------------------------------------------------------
		// setVariable / addToVariableArray / getVariables
		// -------------------------------------------------------------------------
		
		public function testSetVariableStoresValue(): void {
			$ctx = new MatchingContext(['42'], [$this->makeStaticSegment()]);
			$ctx->setVariable('id', '42');
			$this->assertSame(['id' => '42'], $ctx->getVariables());
		}
		
		public function testAddToVariableArrayBuildsArray(): void {
			$ctx = new MatchingContext(['a', 'b'], [$this->makeStaticSegment()]);
			$ctx->addToVariableArray('parts', 'a');
			$ctx->addToVariableArray('parts', 'b');
			$this->assertSame(['parts' => ['a', 'b']], $ctx->getVariables());
		}
		
		public function testAddToVariableArrayOnScalarThrows(): void {
			$ctx = new MatchingContext(['a'], [$this->makeStaticSegment()]);
			$ctx->setVariable('name', 'scalar');
			$this->expectException(\LogicException::class);
			$ctx->addToVariableArray('name', 'oops');
		}
		
		public function testGetVariablesReturnsEmptyArrayInitially(): void {
			$ctx = new MatchingContext(['a'], [$this->makeStaticSegment()]);
			$this->assertSame([], $ctx->getVariables());
		}
		
		// -------------------------------------------------------------------------
		// validateFinalMatch
		// -------------------------------------------------------------------------
		
		public function testValidateFinalMatchReturnsTrueWhenBothConsumed(): void {
			$ctx = new MatchingContext(['users'], [$this->makeStaticSegment()]);
			$ctx->advance();
			$this->assertTrue($ctx->validateFinalMatch());
		}
		
		public function testValidateFinalMatchReturnsFalseWhenUrlSegmentsRemain(): void {
			// URL has 2 segments but pattern only 1 — after one advance, URL still has one left
			$ctx = new MatchingContext(['users', 'extra'], [$this->makeStaticSegment()]);
			$ctx->advance();
			$this->assertFalse($ctx->validateFinalMatch());
		}
		
		public function testValidateFinalMatchReturnsFalseWhenRouteSegmentsRemain(): void {
			// Pattern has 2 segments but URL only 1
			$ctx = new MatchingContext(['users'], [
				$this->makeStaticSegment('users'),
				$this->makeStaticSegment('profile'),
			]);
			$ctx->advance();
			$this->assertFalse($ctx->validateFinalMatch());
		}
		
		public function testValidateFinalMatchReturnsTrueForMultiWildcardWithExtraUrlSegments(): void {
			// Route pattern ends with **, extra URL segments are allowed
			$ctx = new MatchingContext(['files', 'deep', 'path'], [
				$this->makeStaticSegment('files'),
				$this->makeMultiWildcardSegment(),
			]);
			// Advance past 'files' and the '**' route segment
			$ctx->advance();
			$ctx->advance();
			// URL still has 'deep' and 'path' remaining, but last route segment is multi-wildcard
			$this->assertTrue($ctx->validateFinalMatch());
		}
	}