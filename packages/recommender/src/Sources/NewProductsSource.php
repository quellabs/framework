<?php

namespace Quellabs\Recommender\Sources;

use Cake\Database\Connection;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Query\SubjectRatings;
use Quellabs\Recommender\RecommendationResult;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;

/**
 * New-products source: the caller's ordered list of new products, minus those the subject has seen.
 *
 * Results have no native score. Their score is 0.0 because RecommendationResult requires a float.
 */
readonly class NewProductsSource implements CandidateSource {

	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;

	/** @var SubjectRatings Seen ratings of the subject */
	private SubjectRatings $ratings;

	/** @var EligibilityFilter Applies eligibility providers with bounded backfill */
	private EligibilityFilter $eligibilityFilter;

	/** @var array<int, int> Ordered new-product IDs */
	private array $productIds;

	/**
	 * Build the new-products source for one ordered list.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 * @param array<int, int> $productIds Ordered, distinct new-product IDs
	 */
	public function __construct(Connection $connection, RecommendationConfig $config, array $productIds) {
		$this->config = $config;
		$this->ratings = new SubjectRatings($connection);
		$this->eligibilityFilter = new EligibilityFilter($config);
		$this->productIds = $productIds;
	}

	/**
	 * Report whether this source answers for a subject kind. Product subjects have no new-product list.
	 * @param SubjectKind $kind Subject kind
	 * @return bool True for member and visitor subjects
	 */
	public function supports(SubjectKind $kind): bool {
		return $kind !== SubjectKind::Product;
	}

	/**
	 * Return new products the subject has not seen, in list order.
	 * Without eligibility, the limit counts list positions, so seen products use it up. With eligibility, the limit
	 * counts eligible results and the whole unseen list is checked in batches.
	 * @param Subject $subject Member or visitor subject
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $limit Number of list positions without eligibility, or eligible results with it; zero for all
	 * @param SourceSettings $settings Source settings, unused
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> New products in list order
	 * @throws \InvalidArgumentException When the subject is a product
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $limit,
		SourceSettings $settings, ?int $category = null): array {
		$this->assertSupported($subject);
		$seen = $this->ratings->seen($subject, $this->config->resolveCategory($category));

		if ($eligibility !== null) {
			return $this->eligibilityFilter->firstEligible($eligibility, $this->unseen($seen, $this->productIds), $limit,
				fn(RecommendationResult $row): int => $row->productId);
		}

		return $this->unseen($seen, array_slice($this->productIds, 0, $limit === 0 ? null : $limit));
	}

	/**
	 * Build results for the listed products the subject has not seen, keeping their order.
	 * @param array<int, float> $seen Seen product IDs as keys
	 * @param array<int, int> $productIds Product IDs in list order
	 * @return array<int, RecommendationResult> Unseen products in list order
	 */
	private function unseen(array $seen, array $productIds): array {
		$results = [];

		foreach ($productIds as $id) {
			if (!array_key_exists($id, $seen)) {
				$results[] = $this->result($id);
			}
		}

		return $results;
	}

	/**
	 * Score the given products that are on the new-product list, without depth or eligibility.
	 * @param Subject $subject Member or visitor subject
	 * @param array<int, int> $productIds Product IDs to score
	 * @param SourceSettings $settings Source settings, unused
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Listed products, in no particular order
	 * @throws \InvalidArgumentException When the subject is a product
	 */
	public function scores(Subject $subject, array $productIds, SourceSettings $settings, ?int $category = null): array {
		$this->assertSupported($subject);

		return array_map(fn(int $id): RecommendationResult => $this->result($id),
			array_values(array_intersect($productIds, $this->productIds)));
	}

	/**
	 * Build the result for one listed product.
	 * @param int $id Product ID
	 * @return RecommendationResult
	 */
	private function result(int $id): RecommendationResult {
		return new RecommendationResult($id, 0.0, RecommendationSource::NewProducts, []);
	}

	/**
	 * Reject subjects this source does not support.
	 * @param Subject $subject Subject to check
	 * @return void
	 * @throws \InvalidArgumentException When the subject kind is not supported
	 */
	private function assertSupported(Subject $subject): void {
		if (!$this->supports($subject->kind)) {
			throw new \InvalidArgumentException("New products do not support {$subject->kind->value} subjects.");
		}
	}
}
