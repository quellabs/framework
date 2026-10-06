<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Links\ItemLinksSource;
use Quellabs\Recommender\Internal\SlopeOne\SlopeOneSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;

/**
 * Item-to-item and member or visitor lookups over the item-links and Slope One sources.
 *
 * Delegates to the sources. Eligibility backfill is applied here around each list call.
 * Methods throw on database failure.
 */
readonly class ItemRecommender {

	/** @var SlopeOneSource Slope One predictions and candidates */
	private SlopeOneSource $slopeOne;

	/** @var ItemLinksSource Item-links candidates and reasons */
	private ItemLinksSource $itemLinks;

	/** @var EligibilityFilter Applies eligibility providers with bounded backfill */
	private EligibilityFilter $eligibilityFilter;

	/**
	 * Build the recommender.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->slopeOne = new SlopeOneSource($connection, $config);
		$this->itemLinks = new ItemLinksSource($connection, $config);
		$this->eligibilityFilter = new EligibilityFilter($config);
	}

	/**
	 * Return items that co-occur with the given product, ordered by co-occurrence count descending.
	 * Score is the liked count. With eligibility, fewer than $limit results are returned when the depth cap is reached.
	 * @param int $product The product ID
	 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, RecommendationResult> Co-occurring products, scored by liked count
	 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
	 */
	public function linkedProducts(int $product, ?EligibilityProvider $eligibility = null, int $limit = 10, ?int $category = null): array {
		$subject = Subject::product($product);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			fn(int $depth): array => $this->itemLinks->candidates($subject, null, $depth, new SourceSettings(), $category),
			fn(RecommendationResult $row): int => $row->productId);
	}

	/**
	 * Return the products this member has rated that are linked to the given product, the "why we recommend this" list.
	 * Score is the liked count of the link to the given product.
	 * @param MemberId $member The member ID
	 * @param ProductId $product The product ID
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, RecommendationResult> Rated products linked to the given product, scored by liked count
	 * @throws \UnexpectedValueException When a reason row from the database is malformed
	 */
	public function memberReasons(MemberId $member, ProductId $product, int $limit = 10, ?int $category = null): array {
		return $this->itemLinks->reasons(Subject::member($member->value), $product->value, $limit, $category);
	}

	/**
	 * Return the visitor's rated products that are linked to the given product, the "why we recommend this" list for visitors.
	 * Score is the liked count of the link to the given product.
	 * @param VisitorContext $visitor The visitor context holding the current session's ratings
	 * @param int $product The product ID
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, RecommendationResult> Rated products linked to the given product, scored by liked count
	 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
	 * @throws \UnexpectedValueException When a reason row from the database is malformed
	 */
	public function visitorReasons(VisitorContext $visitor, int $product, int $limit = 10, ?int $category = null): array {
		return $this->itemLinks->reasons(Subject::visitor($visitor), $product, $limit, $category);
	}

	/**
	 * Return items sorted by their average Slope One diff relative to the given product, best match first.
	 * Score is the average diff, which can be negative. With eligibility, fewer than $limit results may be returned.
	 * @param int $product The product ID
	 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int $minSupport Minimum co-occurrence count to include a pair, at least 1
	 * @param int|null $category Defaults to configured default
	 * @return array<int, RecommendationResult> Products scored by their average Slope One diff
	 * @throws \InvalidArgumentException When the product ID or minimum support is invalid
	 */
	public function slopeProducts(int $product, ?EligibilityProvider $eligibility = null, int $limit = 10,
		int $minSupport = 1, ?int $category = null): array {
		$subject = Subject::product($product);
		$settings = new SourceSettings(minSupport: $minSupport);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			fn(int $depth): array => $this->slopeOne->candidates($subject, null, $depth, $settings, $category),
			fn(RecommendationResult $row): int => $row->productId);
	}

	/**
	 * Predict a single member rating with directed-pair support.
	 * @param MemberId $member Member ID
	 * @param ProductId $product Candidate ID
	 * @param int $minSupport Minimum summed pair support, at least 1
	 * @param int|null $category Category override
	 * @return RecommendationResult|null
	 * @throws \InvalidArgumentException When the minimum support is below 1
	 */
	public function memberPrediction(MemberId $member, ProductId $product, int $minSupport = 1, ?int $category = null): ?RecommendationResult {
		return $this->slopeOne->predict(Subject::member($member->value), $product->value, $minSupport, $category);
	}

	/**
	 * Predict unseen member ratings with directed-pair support, best prediction first.
	 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
	 * @param int $member Member ID
	 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int $minSupport Minimum summed pair support, at least 1
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult>
	 * @throws \InvalidArgumentException When the member ID or minimum support is invalid
	 */
	public function memberPredictions(int $member, ?EligibilityProvider $eligibility = null, int $limit = 10,
		int $minSupport = 1, ?int $category = null): array {
		$subject = Subject::member($member);
		$settings = new SourceSettings(minSupport: $minSupport);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			fn(int $depth): array => $this->slopeOne->candidates($subject, null, $depth, $settings, $category),
			fn(RecommendationResult $row): int => $row->productId);
	}

	/**
	 * Predict one visitor rating with directed-pair support.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param int $product Candidate ID
	 * @param int $minSupport Minimum summed pair support, at least 1
	 * @param int|null $category Category override
	 * @return RecommendationResult|null
	 * @throws \InvalidArgumentException When the product ID or minimum support is invalid
	 */
	public function visitorPrediction(VisitorContext $visitor, int $product, int $minSupport = 1, ?int $category = null): ?RecommendationResult {
		return $this->slopeOne->predict(Subject::visitor($visitor), $product, $minSupport, $category);
	}

	/**
	 * Predict unseen visitor ratings, best prediction first.
	 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int $minSupport Minimum summed pair support, at least 1
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult>
	 * @throws \InvalidArgumentException When the minimum support is below 1
	 */
	public function visitorPredictions(VisitorContext $visitor, ?EligibilityProvider $eligibility = null, int $limit = 10,
		int $minSupport = 1, ?int $category = null): array {
		$subject = Subject::visitor($visitor);
		$settings = new SourceSettings(minSupport: $minSupport);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			fn(int $depth): array => $this->slopeOne->candidates($subject, null, $depth, $settings, $category),
			fn(RecommendationResult $row): int => $row->productId);
	}
}
