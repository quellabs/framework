<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\Sources\ItemLinksSource;
use Quellabs\Recommender\Sources\SlopeOneSource;
use Quellabs\Recommender\RecommendationResult;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\Reconciliation\SourceSettings;

/**
 * Integration tests for eligibility providers applied to item-based recommendations.
 * Requires a live MySQL database — see tests/bootstrap.php.
 */
class EligibilityProviderTest extends IntegrationTestCase {
    use ItemLinkSlates;


	/** @var ItemLinksSource Item-links candidates */
	private ItemLinksSource $itemLinks;

	/** @var SlopeOneSource Slope One candidates and predictions */
	private SlopeOneSource $slopeOne;

	protected function setUp(): void {
		parent::setUp();
		$this->itemLinks = new ItemLinksSource($this->connection, $this->config);
		$this->slopeOne = new SlopeOneSource($this->connection, $this->config);
	}

	/**
	 * Return a provider that accepts every candidate except the given IDs.
	 * @param int ...$rejected IDs the provider refuses
	 * @return EligibilityProvider Provider that is not an array allowlist
	 */
	private function rejecting(int ...$rejected): EligibilityProvider {
		return new class($rejected) implements EligibilityProvider {

			/** @var array<int, int> IDs the provider refuses */
			private array $rejected;

			/**
			 * @param array<int, int> $rejected IDs the provider refuses
			 */
			public function __construct(array $rejected) {
				$this->rejected = $rejected;
			}

			/**
			 * Keep candidates that are not rejected, in candidate order.
			 * @param array<int, int> $candidateIds Candidate IDs
			 * @return array<int, int> Eligible IDs
			 */
			public function filterEligible(array $candidateIds): array {
				return array_values(array_filter($candidateIds, fn($id) => !in_array($id, $this->rejected, true)));
			}
		};
	}

	/**
	 * Extract product IDs from results, preserving order.
	 * @param array<int, RecommendationResult> $results Recommendation results
	 * @return array<int, int> Product IDs in result order
	 */
	private function itemIds(array $results): array {
		return array_map(fn($result) => $result->productId, $results);
	}

	/** @return void */
	public function testCustomProviderSkipsIneligibleTopRows(): void {
		$this->insertLink(1, 2, 10);
		$this->insertLink(1, 3, 5);
		$this->insertLink(1, 4, 1);
		$this->assertSame([3], $this->itemIds($this->itemLinks->candidates(Subject::product(1), $this->rejecting(2), 1, new SourceSettings())));
		$this->assertSame([4], $this->itemIds($this->itemLinks->candidates(Subject::product(1), $this->rejecting(2, 3), 1, new SourceSettings())));
	}

	/** Fetching deeper must reach eligible rows beyond the first doubling steps.
	 * @return void
	 */
	public function testCustomProviderFetchesDeeperThanTheLimit(): void {
		$rejected = [];

		for ($id = 2; $id <= 61; $id++) {
			$this->insertLink(1, $id, 100 - $id);

			if ($id <= 56) {
				$rejected[] = $id;
			}
		}

		$this->assertSame([57], $this->itemIds($this->itemLinks->candidates(Subject::product(1), $this->rejecting(...$rejected), 1, new SourceSettings())));
	}

	/** @return void */
	public function testCustomProviderReturnsFewerResultsWhenNothingIsEligible(): void {
		$this->insertLink(1, 2, 10);
		$this->insertLink(1, 3, 5);
		$this->assertSame([], $this->itemLinks->candidates(Subject::product(1), $this->rejecting(2, 3), 1, new SourceSettings()));
	}

	/** An array provider and a custom provider with the same eligible set must give the same results.
	 * @return void
	 */
	public function testArrayAndCustomProvidersAgree(): void {
		$this->insertRating(1, 10, 0.9);
		$this->insertLink(10, 20, 10);
		$this->insertLink(10, 30, 5);
		$this->insertLink(10, 40, 2);
		$array = new ArrayEligibilityProvider([30, 40]);
		$custom = $this->rejecting(20);
		$this->assertSame($this->itemIds($this->memberLinks(1, $array, 2)),
			$this->itemIds($this->memberLinks(1, $custom, 2)));
		$this->assertSame([30, 40], $this->itemIds($this->memberLinks(1, $custom, 2)));
	}

	/** An empty array provider means nothing is eligible, not "no restriction".
	 * @return void
	 */
	public function testEmptyArrayProviderReturnsNothing(): void {
		$this->insertLink(1, 2, 10);
		$this->assertSame([], $this->itemLinks->candidates(Subject::product(1), new ArrayEligibilityProvider([]), 10, new SourceSettings()));
		$this->assertSame([], $this->slopeOne->candidates(Subject::product(1), new ArrayEligibilityProvider([]), 10, new SourceSettings()));
	}

	/** @return void */
	public function testCustomProviderAppliesToMemberRecommendations(): void {
		$this->insertRating(1, 10, 0.9);
		$this->insertLink(10, 20, 10);
		$this->insertLink(10, 30, 5);
		$this->assertSame([30], $this->itemIds($this->memberLinks(1, $this->rejecting(20), 1)));
	}

	/** @return void */
	public function testCustomProviderAppliesToPredictions(): void {
		$this->insertRating(1, 10, 0.8);
		$this->insertLink(10, 20, 2, 0.1);
		$this->insertLink(10, 30, 2, 0.2);
		$this->assertSame([30], $this->itemIds($this->slopeOne->candidates(Subject::member(1), $this->rejecting(20), 10, new SourceSettings())));
	}

	/** A provider answer that reorders its input must be rejected.
	 * @return void
	 */
	public function testOutOfOrderProviderAnswerIsRejected(): void {
		$this->insertLink(1, 2, 10);
		$this->insertLink(1, 3, 5);
		$reversing = new class implements EligibilityProvider {

			/**
			 * Return the candidates in reverse order.
			 * @param array<int, int> $candidateIds Candidate IDs
			 * @return array<int, int> Reversed IDs
			 */
			public function filterEligible(array $candidateIds): array {
				return array_reverse($candidateIds);
			}
		};
		$this->expectException(\UnexpectedValueException::class);
		$this->itemLinks->candidates(Subject::product(1), $reversing, 10, new SourceSettings());
	}
}
