<?php

	namespace Quellabs\Canvas\Tests\Routing;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\MatchingContext;
	use Quellabs\Canvas\Routing\MatchResult;
	use Quellabs\Canvas\Routing\Strategies\PartialVariableStrategy;

	/**
	 * Unit tests for PartialVariableStrategy.
	 *
	 * Covers the legacy fallback path (matchWithLegacyParsing), which runs whenever
	 * a partial-variable segment has no compiled_regex — i.e. compilation of the
	 * segment's regex failed. This path re-parses the segment's literal
	 * prefix/suffix at match time and must enforce any type constraint
	 * (e.g. {id:int}) manually, since no compiled regex exists to do so.
	 */
	class PartialVariableStrategyTest extends TestCase {

		private PartialVariableStrategy $strategy;

		protected function setUp(): void {
			$this->strategy = new PartialVariableStrategy();
		}

		/**
		 * Build a minimal CompiledSegment that forces the legacy fallback path,
		 * as if regex compilation for this segment had failed.
		 * @param string $original
		 * @return array<string, mixed>
		 */
		private function legacySegment(string $original): array {
			return [
				'type'                     => 'partial_variable',
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

		private function contextFor(string $urlSegment): MatchingContext {
			return new MatchingContext([$urlSegment], []);
		}

		public function testLegacyParsingRejectsValueViolatingIntConstraint(): void {
			$segment = $this->legacySegment('item-{id:int}');
			$context = $this->contextFor('item-abc');

			$this->assertSame(MatchResult::NO_MATCH, $this->strategy->match($segment, $context));
		}

		public function testLegacyParsingAcceptsValueSatisfyingIntConstraint(): void {
			$segment = $this->legacySegment('item-{id:int}');
			$context = $this->contextFor('item-123');

			$this->assertSame(MatchResult::CONTINUE_MATCHING, $this->strategy->match($segment, $context));
			$this->assertSame('123', $context->getVariables()['id']);
		}

		public function testLegacyParsingRejectsValueViolatingAlphaConstraint(): void {
			$segment = $this->legacySegment('{slug:alpha}.json');
			$context = $this->contextFor('abc123.json');

			$this->assertSame(MatchResult::NO_MATCH, $this->strategy->match($segment, $context));
		}

		public function testLegacyParsingAcceptsValueSatisfyingAlphaConstraint(): void {
			$segment = $this->legacySegment('{slug:alpha}.json');
			$context = $this->contextFor('report.json');

			$this->assertSame(MatchResult::CONTINUE_MATCHING, $this->strategy->match($segment, $context));
			$this->assertSame('report', $context->getVariables()['slug']);
		}

		public function testLegacyParsingStillWorksWithoutTypeConstraint(): void {
			$segment = $this->legacySegment('v{version}');
			$context = $this->contextFor('v42');

			$this->assertSame(MatchResult::CONTINUE_MATCHING, $this->strategy->match($segment, $context));
			$this->assertSame('42', $context->getVariables()['version']);
		}

		public function testLegacyParsingRejectsSegmentNotMatchingPrefixOrSuffix(): void {
			$segment = $this->legacySegment('item-{id:int}');
			$context = $this->contextFor('other-123');

			$this->assertSame(MatchResult::NO_MATCH, $this->strategy->match($segment, $context));
		}
	}
