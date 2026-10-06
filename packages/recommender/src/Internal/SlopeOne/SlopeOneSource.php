<?php

namespace Quellabs\Recommender\Internal\SlopeOne;

use Cake\Database\Connection;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Identifier;
use Quellabs\Recommender\Internal\Links\LinkCandidates;
use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
use Quellabs\Recommender\Internal\Query\Results;
use Quellabs\Recommender\RecommendationResult;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;
use Quellabs\Recommender\VisitorRating;

/**
 * Slope One predictions and rankings from the diff_slope and slope_count columns of vogoo_links.
 *
 * Methods throw on database failure.
 *
 * @phpstan-type ProductRating array{product_id: int, rating: float}
 * @phpstan-type ProductDiff array{product_id: int, diff: float}
 */
readonly class SlopeOneSource implements CandidateSource {

	/** @var Connection Database connection */
	private Connection $connection;

	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;

	/** @var TemporaryTable Temporary tables for candidate sets and rating inputs */
	private TemporaryTable $temporary;

	/** @var EligibilityFilter Applies eligibility providers with bounded backfill */
	private EligibilityFilter $eligibilityFilter;

	/** @var LinkCandidates Member and visitor candidates from the subject's ratings */
	private LinkCandidates $linkCandidates;

	/**
	 * Build the Slope One source.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
		$this->temporary = new TemporaryTable($connection);
		$this->eligibilityFilter = new EligibilityFilter($config);
		$this->linkCandidates = new LinkCandidates($connection);
	}

	/**
	 * Report whether this source answers for a subject kind.
	 * @param SubjectKind $kind Subject kind
	 * @return bool True for every subject kind
	 */
	public function supports(SubjectKind $kind): bool {
		return true;
	}

	/**
	 * Return the products with the best Slope One prediction for a subject, best first.
	 * Product subjects rank by the average diff to the product, which can be negative. Member and visitor subjects
	 * rank by the predicted rating from their genuine ratings, bounded to [0, 1].
	 * @param Subject $subject Subject the candidates are for
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param SourceSettings $settings Source settings; minimum support applies to the pair count
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Products with their Slope One score and support
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $limit,
		SourceSettings $settings, ?int $category = null): array {
		$resolved = $this->config->resolveCategory($category);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			fn(int $depth): array => match ($subject->kind) {
				SubjectKind::Product => $this->productRows($subject->id ?? throw new \LogicException('A product subject always has an ID.'),
					$settings->minSupport, $depth, $category),
				SubjectKind::Member, SubjectKind::Visitor => $this->ratedCandidates($subject, $resolved, $depth, $settings, null),
			},
			fn(RecommendationResult $row): int => $row->productId);
	}

	/**
	 * Score the given products for a subject by Slope One prediction, without depth or eligibility.
	 * @param Subject $subject Subject the scores are for
	 * @param array<int, int> $productIds Product IDs to score
	 * @param SourceSettings $settings Source settings; minimum support applies to the pair count
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Scored products, in no particular order
	 */
	public function scores(Subject $subject, array $productIds, SourceSettings $settings, ?int $category = null): array {
		if ($subject->kind === SubjectKind::Product) {
			return array_values(array_filter($this->candidates($subject, null, 0, $settings, $category),
				fn(RecommendationResult $row): bool => in_array($row->productId, $productIds, true)));
		}

		return $this->ratedCandidates($subject, $this->config->resolveCategory($category), 0, $settings, $productIds);
	}

	/**
	 * Return the products predicted from a member's or visitor's genuine ratings that they have not seen.
	 * @param Subject $subject Member or visitor subject
	 * @param int $category Resolved category
	 * @param int $depth Number of top candidates, or zero for all
	 * @param SourceSettings $settings Source settings; minimum support applies to the pair count
	 * @param array<int, int>|null $productIds Restricts the products to these, or null for all
	 * @return array<int, RecommendationResult>
	 */
	private function ratedCandidates(Subject $subject, int $category, int $depth, SourceSettings $settings, ?array $productIds): array {
		return $this->linkCandidates->candidates($subject, $category, $depth, RecommendationSource::SlopeOne,
			fn(string $join, string $restriction, ?int $limit): array => $this->candidateRows($join, $restriction, [], $category, $settings->minSupport, $limit),
			$productIds);
	}

	/**
	 * Return the products closest to a product by average Slope One diff, as results.
	 * @param int $productId Seed product ID
	 * @param int $minSupport Minimum co-occurrence count to include a pair
	 * @param int $depth Number of top candidates, or zero for all
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult>
	 */
	private function productRows(int $productId, int $minSupport, int $depth, ?int $category): array {
		return array_map(fn(array $diff): RecommendationResult => new RecommendationResult(
			$diff['product_id'], $diff['diff'], RecommendationSource::SlopeOne, []),
			$this->slopeDiffs($productId, $minSupport, $depth, $category));
	}

	/**
	 * Return items sorted by their average Slope One diff relative to the given product, best match first.
	 * @param int $productId The product ID
	 * @param int $minSupport Minimum co-occurrence count to include a pair
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, ProductDiff>
	 */
	private function slopeDiffs(int $productId, int $minSupport = 1, int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$limit = max(0, $limit);
		$result = [];

		foreach ($this->slopeItemRows($productId, max(1, $minSupport), $resolvedCategory, $limit) as $row) {
			if (
				!is_array($row) ||
				!isset($row['item_id2'], $row['avg_diff']) ||
				!is_numeric($row['item_id2']) ||
				!is_numeric($row['avg_diff'])
			) {
				continue;
			}

			$result[] = ['product_id' => (int)$row['item_id2'], 'diff' => (float)$row['avg_diff']];
		}

		return Results::limit($result, $limit);
	}

	/**
	 * Return the linked candidates of a product ordered by average Slope One diff, best first.
	 * @param int $productId The product ID
	 * @param int $minSupport Minimum co-occurrence count, at least one
	 * @param int $category Already-resolved category
	 * @param int $limit Maximum results, or zero for all
	 * @return array<int, array<string, mixed>> Rows with item_id2 and avg_diff
	 */
	private function slopeItemRows(int $productId, int $minSupport, int $category, int $limit): array {
		$sql = '
			SELECT
				`item_id2`,
				(`diff_slope` / `slope_count`) AS avg_diff
			FROM `vogoo_links`
			WHERE `item_id1` = :product_id AND
			      `category` = :category AND
			      `slope_count` >= :min_support
		';

		$params = [
			'product_id' => $productId,
			'category'   => $category,
			'min_support' => $minSupport,
		];
		$sql .= ' ORDER BY avg_diff DESC, `item_id2` ASC';

		$sql .= Results::limitSql($limit);

		return $this->connection->execute($sql, $params)->fetchAll('assoc');
	}

	/**
	 * Score candidates from the rated items they are linked to, best prediction first.
	 * This is the one Slope One candidate query. Candidates and predictions both use it.
	 * @param string $ratingJoin Join from vogoo_links to the rating source, aliased r on l.item_id1
	 * @param string $restriction Extra condition starting with AND and referring to l.item_id2, or empty
	 * @param array<string, int|float|string> $params Parameters referenced by the join and restriction
	 * @param int $category Already-resolved category
	 * @param int $minSupport Minimum summed pair support, at least one
	 * @param int|null $limit Maximum rows, or null for all
	 * @return array<int, array<string, mixed>> Rows with id, support_count and score
	 */
	private function candidateRows(string $ratingJoin, string $restriction, array $params, int $category, int $minSupport, ?int $limit): array {
		$sql = "
			SELECT
				l.item_id2 AS id,
				SUM(l.slope_count) AS support_count,
				LEAST(1.0, GREATEST(0.0, SUM(r.rating * l.slope_count + l.diff_slope) / SUM(l.slope_count))) AS score
			FROM vogoo_links l {$ratingJoin}
			WHERE l.category = :category AND
				l.slope_count > 0 {$restriction}
			GROUP BY l.item_id2
			HAVING support_count >= :min_support
			ORDER BY score DESC, support_count DESC, id ASC";
		$sql .= $limit === null ? '' : ' LIMIT ' . $limit;

		return $this->connection->execute($sql, $params + ['category' => $category, 'min_support' => $minSupport])->fetchAll('assoc');
	}

	/**
	 * Predict one rating for a member or visitor with directed-pair support, whether or not the subject has seen the product.
	 * @param Subject $subject Member or visitor subject
	 * @param int $product Product ID to predict
	 * @param int $minSupport Minimum summed pair support, at least 1
	 * @param int|null $category Category override
	 * @return RecommendationResult|null Prediction as the score, or null when no linked rating gives support
	 * @throws \InvalidArgumentException When the subject is a product, or the product or minimum support is invalid
	 */
	public function predict(Subject $subject, int $product, int $minSupport = 1, ?int $category = null): ?RecommendationResult {
		if ($subject->kind === SubjectKind::Product) {
			throw new \InvalidArgumentException('Slope One predictions need a member or visitor subject.');
		}

		Identifier::assertId($product, 'Product ID');
		$this->validateSupport($minSupport);
		$resolvedCategory = $this->config->resolveCategory($category);

		if ($subject->kind === SubjectKind::Member) {
			return $this->firstPrediction($this->candidateRows(
				$this->memberRatingJoin(), 'AND l.item_id2 = :product',
				['member' => $subject->id ?? throw new \LogicException('A member subject always has an ID.'), 'product' => $product],
				$resolvedCategory, $minSupport, 1));
		}

		$visitor = $subject->visitor ?? throw new \LogicException('A visitor subject always has a visitor context.');
		$ratings = $this->collectGenuineRatings($visitor->ratings($resolvedCategory));

		if ($ratings === []) {
			return null;
		}

		return $this->temporary->withRatingTable('vogoo_visitor_prediction_input_', $ratings,
			fn(string $table) => $this->firstPrediction($this->candidateRows(
				"JOIN {$table} r ON r.product_id = l.item_id1", 'AND l.item_id2 = :product', ['product' => $product],
				$resolvedCategory, $minSupport, 1)));
	}

	/**
	 * Return the rating join for the persisted member's own ratings.
	 * @return string Join from vogoo_links to the member's ratings, aliased r on l.item_id1
	 */
	private function memberRatingJoin(): string {
		return 'JOIN vogoo_ratings r ON r.product_id = l.item_id1 AND
			r.category = l.category AND
			r.member_id = :member AND
			r.rating >= 0.0';
	}

	/**
	 * Return the first prediction of a candidate query, or null when there is none.
	 * @param array<int, array<string, mixed>> $rows Rows from candidateRows()
	 * @return RecommendationResult|null
	 */
	private function firstPrediction(array $rows): ?RecommendationResult {
		return $rows === [] ? null : $this->predictionFromRow($rows[0]);
	}

	/**
	 * Build a prediction from one candidate row.
	 * @param array<string, mixed> $row Row with id, support_count and score
	 * @return RecommendationResult
	 */
	private function predictionFromRow(array $row): RecommendationResult {
		if (!is_numeric($row['id'] ?? null) || !is_numeric($row['score'] ?? null) || !is_numeric($row['support_count'] ?? null)) {
			throw new \UnexpectedValueException('Slope One row must contain numeric id, score and support_count values.');
		}

		return new RecommendationResult((int)$row['id'], (float)$row['score'], RecommendationSource::SlopeOne, [], (int)$row['support_count']);
	}

	/**
	 * Validate that the minimum pair support is positive.
	 * @param int $minSupport Minimum support
	 * @return void
	 * @throws \InvalidArgumentException When the minimum support is not positive
	 */
	private function validateSupport(int $minSupport): void {
		if ($minSupport < 1) {
			throw new \InvalidArgumentException("Minimum support must be positive, got {$minSupport}.");
		}
	}

	/**
	 * Build a product-to-rating map of genuine ratings (>= 0.0, excluding not interested) for Slope One input.
	 * @param array<int, VisitorRating> $ratings Visitor ratings
	 * @return array<int, float> Map of product_id to rating
	 */
	private function collectGenuineRatings(array $ratings): array {
		$products = [];

		foreach ($ratings as $entry) {
			if ($entry->rating >= 0.0) {
				$products[$entry->productId] = $entry->rating;
			}
		}

		return $products;
	}
}
