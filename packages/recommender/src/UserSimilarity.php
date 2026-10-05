<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;

/**
 * User-based collaborative filtering: member similarity scoring and
 * neighbour-based recommendations.
 *
 * The similarity algorithm is the original Vogoo weighted mean-squared-error
 * approach, which penalises pairs with few common ratings relative to the
 * member's total ratings.
 *
 * Methods throw on database failure.
 */
readonly class UserSimilarity {
	
	/** @var Connection Database connection */
	private Connection $connection;
	
	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;
	
	/** @var RecommendationEngine Engine used for rating lookups */
	private RecommendationEngine $engine;
	
	/**
	 * Build the similarity service.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 * @param RecommendationEngine $engine The recommendation engine used for rating lookups
	 */
	public function __construct(Connection $connection, RecommendationConfig $config, RecommendationEngine $engine) {
		$this->connection = $connection;
		$this->config = $config;
		$this->engine = $engine;
	}
	
	/**
	 * Return a similarity score in [0, 100] between two members.
	 * 0 means no overlap or completely different taste; 100 means identical.
	 * @param int $memberId1 The first member ID
	 * @param int $memberId2 The second member, compared against the first
	 * @param int|null $category Defaults to configured default
	 * @return int Similarity score in [0, 100]
	 */
	public function memberSimilarity(int $memberId1, int $memberId2, ?int $category = null): int {
		$resolvedCategory = $this->config->resolveCategory($category);
		$ownRatingCount = $this->engine->memberNumRatings($memberId1, true, false, $resolvedCategory);
		
		if ($ownRatingCount === 0) {
			return 0;
		}
		
		$row = $this->connection->execute('
			SELECT
				COUNT(r2.`product_id`) AS c2,
				SUM((r2.`rating` - r1.`rating`) * (r2.`rating` - r1.`rating`)) AS s
			FROM `vogoo_ratings` r1
			INNER JOIN `vogoo_ratings` r2 ON r2.`member_id` = :member_id2 AND
			                                 r2.`product_id` = r1.`product_id` AND
			                                 r2.`category` = r1.`category` AND
			                                 r2.`rating` >= 0.0
			WHERE r1.`member_id` = :member_id1 AND
			      r1.`category` = :category AND
			      r1.`rating` >= 0.0
		', [
			'member_id1' => $memberId1,
			'member_id2' => $memberId2,
			'category'   => $resolvedCategory,
		])->fetchAssoc();
		
		$commonCount = (int)$row['c2'];
		
		if ($commonCount === 0) {
			return 0;
		}
		
		return $this->scoreSimilarity($commonCount, (float)$row['s'], $ownRatingCount);
	}
	
	/**
	 * Return neighbours of a member, sorted by similarity descending and filtered by a minimum similarity.
	 * This calculates similarity for every candidate neighbour. For large member sets, pre-compute and cache the lists.
	 * @param int $memberId The member ID
	 * @param int $minSimilarity Minimum score to include (0 to 100)
	 * @param int $limit Maximum number of neighbours (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{member_id: int, similarity: int}>
	 */
	public function getNeighbours(int $memberId, int $minSimilarity = 1, int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$minSimilarity = max(0, min(100, $minSimilarity));
		$limit = max(0, $limit);
		
		$ownRatingCount = $this->engine->memberNumRatings($memberId, true, false, $resolvedCategory);
		
		if ($ownRatingCount === 0) {
			return [];
		}
		
		$rows = $this->connection->execute('
			SELECT r2.`member_id`, COUNT(*) AS common_count,
			       SUM((r2.`rating` - r1.`rating`) * (r2.`rating` - r1.`rating`)) AS squared_diff
			FROM `vogoo_ratings` r1
			INNER JOIN `vogoo_ratings` r2 ON r2.`product_id` = r1.`product_id` AND
			                                 r2.`category` = r1.`category` AND
			                                 r2.`member_id` <> :member_id
			WHERE r1.`member_id` = :member_id2 AND
			      r1.`category` = :category AND
			      r1.`rating` >= 0.0 AND
			      r2.`rating` >= 0.0
			GROUP BY r2.`member_id`
		', [
			'member_id'  => $memberId,
			'member_id2' => $memberId,
			'category'   => $resolvedCategory,
		])->fetchAll('assoc');
		
		$neighbours = [];
		
		foreach ($rows as $row) {
			$otherId = (int)$row['member_id'];
			$similarity = $this->scoreSimilarity((int)$row['common_count'], (float)$row['squared_diff'], $ownRatingCount);
			
			if ($similarity === 0 || $similarity < $minSimilarity) {
				continue;
			}
			
			$neighbours[] = ['member_id' => $otherId, 'similarity' => $similarity];
		}
		
		usort($neighbours, fn($a, $b) => ($b['similarity'] <=> $a['similarity']) ?: ($a['member_id'] <=> $b['member_id']));
		return $limit > 0 ? array_slice($neighbours, 0, $limit) : $neighbours;
	}
	
	/**
	 * Return recommended items for a member based on what similar members have liked, weighted by similarity.
	 * Only returns items the member has not already rated.
	 * @param int $memberId The member ID
	 * @param int $minSimilarity Minimum neighbour similarity to consider
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, int> List of product IDs ordered by score
	 */
	public function memberGetRecommendedItems(int $memberId, int $minSimilarity = 1, array $filter = [], int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$minSimilarity = max(0, min(100, $minSimilarity));
		$limit = max(0, $limit);
		
		$neighbours = $this->getNeighbours($memberId, $minSimilarity, 0, $resolvedCategory);
		
		if ($neighbours === []) {
			return [];
		}
		
		$scores = $this->computeNeighbourScores($memberId, $neighbours, $filter, $resolvedCategory);
		
		if ($scores === []) {
			return [];
		}
		
		uksort($scores, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
		
		$result = array_keys($scores);
		return $limit > 0 ? array_slice($result, 0, $limit) : $result;
	}
	
	/**
	 * Return scored neighbour candidates for the optional reconciler.
	 * @param int $memberId Member ID
	 * @param int $minSimilarity Minimum neighbour similarity
	 * @param int $maxNeighbours Maximum neighbours to use
	 * @param int $limit Maximum candidates
	 * @param int|null $category Category override
	 * @param array<int, int>|null $candidateIds Optional exact candidate batch to score
	 * @return array<int, array{itemId: int, score: float}>
	 */
	public function memberRecommendationsScored(int $memberId, int $minSimilarity,
		int $maxNeighbours, int $limit, ?int $category = null, ?array $candidateIds = null): array {
		if ($candidateIds === []) {
			return [];
		}
		
		$resolvedCategory = $this->config->resolveCategory($category);
		$neighbours = $this->getNeighbours($memberId, $minSimilarity, $maxNeighbours, $resolvedCategory);
		
		if ($neighbours === []) {
			return [];
		}
		
		$neighbourTable = 'recommender_neighbours_' . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$neighbourTable}
			(member_id INT UNSIGNED PRIMARY KEY, similarity INT UNSIGNED NOT NULL)");
			
		try {
			return $this->scoreThroughNeighbourTable($memberId, $neighbours, $neighbourTable, $candidateIds, $resolvedCategory, $limit);
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$neighbourTable}");
		}
	}
	
	/**
	 * Score candidates from the neighbour table, optionally through a temporary candidate table.
	 * @param int $memberId Member receiving recommendations
	 * @param array<int, array{member_id: int, similarity: int}> $neighbours Neighbours to load
	 * @param string $neighbourTable Temporary table that receives the neighbours
	 * @param array<int, int>|null $candidateIds Optional exact candidate batch to score
	 * @param int $category Already-resolved category
	 * @param int $limit Maximum candidates
	 * @return array<int, array{itemId: int, score: float}>
	 */
	private function scoreThroughNeighbourTable(int $memberId, array $neighbours, string $neighbourTable,
		?array $candidateIds, int $category, int $limit): array {
		if ($candidateIds === null) {
			$this->insertNeighbours($neighbourTable, $neighbours);
			return $this->queryNeighbourRecommendations($memberId, $neighbourTable, null, $category, $limit);
		}
		
		$candidateTable = 'recommender_neighbour_candidates_' . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$candidateTable} (product_id INT UNSIGNED PRIMARY KEY)");
		
		try {
			$this->insertCandidates($candidateTable, $candidateIds);
			$this->insertNeighbours($neighbourTable, $neighbours);
			return $this->queryNeighbourRecommendations($memberId, $neighbourTable, $candidateTable, $category, $limit);
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$candidateTable}");
		}
	}
	
	/**
	 * Insert neighbours into a temporary table in batches of 500.
	 * @param string $table Temporary table name
	 * @param array<int, array{member_id: int, similarity: int}> $neighbours Neighbours to insert
	 * @return void
	 */
	private function insertNeighbours(string $table, array $neighbours): void {
		foreach (array_chunk($neighbours, 500) as $batch) {
			$values = [];
			$params = [];
			
			foreach ($batch as $neighbour) {
				$values[] = '(?, ?)';
				$params[] = $neighbour['member_id'];
				$params[] = $neighbour['similarity'];
			}
			
			$this->connection->execute("INSERT INTO {$table} (member_id, similarity) VALUES "
				. implode(',', $values), $params);
		}
	}
	
	/**
	 * Insert distinct candidate product IDs into a temporary table in batches of 500.
	 * @param string $table Temporary table name
	 * @param array<int, int> $candidateIds Candidate product IDs
	 * @return void
	 */
	private function insertCandidates(string $table, array $candidateIds): void {
		foreach (array_chunk(array_values(array_unique($candidateIds)), 500) as $batch) {
			$holders = implode(',', array_fill(0, count($batch), '(?)'));
			$this->connection->execute("INSERT INTO {$table} (product_id) VALUES {$holders}", $batch);
		}
	}
	
	/**
	 * Aggregate the neighbours' ratings into unseen item recommendations.
	 * @param int $memberId Member receiving recommendations
	 * @param string $neighbourTable Temporary table holding the neighbours
	 * @param string|null $candidateTable Temporary table restricting candidates, or null for all items
	 * @param int $category Already-resolved category
	 * @param int $limit Maximum candidates
	 * @return array<int, array{itemId: int, score: float}>
	 */
	private function queryNeighbourRecommendations(int $memberId, string $neighbourTable, ?string $candidateTable,
		int $category, int $limit): array {
		$candidateJoin = $candidateTable === null
			? ''
			: "JOIN {$candidateTable} candidates ON candidates.product_id = r.product_id";
			
		$rows = $this->connection->execute("SELECT r.product_id AS item_id,
			SUM(r.rating * n.similarity) / SUM(n.similarity) AS score
			FROM {$neighbourTable} n JOIN vogoo_ratings r ON r.member_id = n.member_id
			{$candidateJoin}
			WHERE r.category = :category AND r.rating >= :threshold
			AND NOT EXISTS (SELECT 1 FROM vogoo_ratings seen WHERE seen.member_id = :member
				AND seen.category = :seen_category AND seen.product_id = r.product_id)
			GROUP BY r.product_id ORDER BY score DESC, r.product_id ASC LIMIT {$limit}",
			['category' => $category, 'threshold' => $this->config->getThresholdRating(),
			 'member'   => $memberId, 'seen_category' => $category])->fetchAll('assoc');
			
		return array_map(fn($row) => ['itemId' => (int)$row['item_id'], 'score' => (float)$row['score']], $rows);
	}
	
	/**
	 * Convert the raw sum of squared rating differences into a 0 to 100 similarity score.
	 * Applies the Vogoo confidence penalty when the number of common ratings is small relative to the member's total.
	 * @param int $nrCommonRatings Number of products both members rated
	 * @param float $sumSquaredDiff Sum of squared rating differences over those products
	 * @param int $ownRatingCount Total genuine ratings of the first member
	 * @return int Similarity score in [0, 100]
	 */
	private function scoreSimilarity(int $nrCommonRatings, float $sumSquaredDiff, int $ownRatingCount): int {
		$cost = $this->config->getCost();
		$spread = $sumSquaredDiff * $cost * $cost * 20.0;
		$spreadPerCommonRating = $spread / $nrCommonRatings;
		
		if ($spreadPerCommonRating > 100) {
			return 0;
		}
		
		$thresholdNr = $this->config->getThresholdNrCommonRatings();
		$thresholdMult = $this->config->getThresholdMult();
		
		// Enough common ratings: return the direct score
		if ($nrCommonRatings > $thresholdNr || ($nrCommonRatings * $thresholdMult) >= $ownRatingCount) {
			return 100 - (int)$spreadPerCommonRating;
		}
		
		// Fewer common ratings: apply a confidence penalty
		if ($ownRatingCount < ($thresholdNr * $thresholdMult)) {
			$confidenceRatio = ($nrCommonRatings * $thresholdMult) / $ownRatingCount;
		} else {
			$confidenceRatio = ($nrCommonRatings * $thresholdMult) / ($thresholdNr * $thresholdMult);
		}
		
		$squaredConfidence = $confidenceRatio * $confidenceRatio;
		
		return (int)((100.0 - $spreadPerCommonRating) * (0.1 + 0.9 * $squaredConfidence));
	}
	
	/**
	 * Compute similarity-weighted scores for every product liked by the neighbours that the member has not rated.
	 * Each score is the similarity-weighted average of the neighbours' ratings. Runs one query per chunk of neighbours.
	 * @param int $memberId The member receiving recommendations
	 * @param array<int, array{member_id: int, similarity: int}> $neighbours Neighbours with their similarity
	 * @param array<int> $filter When non-empty, only score product IDs in this set
	 * @param int $category Already-resolved category
	 * @return array<int, float> Map of candidate product_id to weighted score
	 */
	private function computeNeighbourScores(int $memberId, array $neighbours, array $filter, int $category): array {
		$threshold = $this->config->getThresholdRating();
		$scores = [];
		$weights = [];
		
		$similarities = array_column($neighbours, 'similarity', 'member_id');
		
		foreach (array_chunk(array_keys($similarities), 500) as $memberIds) {
			$placeholders = implode(',', array_fill(0, count($memberIds), '?'));
			$rows = $this->connection->execute("SELECT r.member_id, r.product_id, r.rating
				FROM vogoo_ratings r WHERE r.member_id IN ({$placeholders})
				AND r.category = ? AND r.rating >= ?
				AND NOT EXISTS (SELECT 1 FROM vogoo_ratings target
					WHERE target.member_id = ? AND target.category = ?
					AND target.product_id = r.product_id)",
				array_merge($memberIds, [$category, $threshold, $memberId, $category])
			)->fetchAll('assoc');
			
			foreach ($rows as $row) {
				$productId = (int)$row['product_id'];
				
				if ($filter !== [] && !in_array($productId, $filter, true)) {
					continue;
				}
				
				$similarity = $similarities[(int)$row['member_id']];
				$scores[$productId] = ($scores[$productId] ?? 0.0) + $similarity * (float)$row['rating'];
				$weights[$productId] = ($weights[$productId] ?? 0) + $similarity;
			}
		}
		
		// Normalise by total weight
		foreach ($scores as $productId => $score) {
			$scores[$productId] = $score / $weights[$productId];
		}
		
		return $scores;
	}
}
