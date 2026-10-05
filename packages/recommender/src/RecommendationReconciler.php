<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\Internal\Model\ClickModel;
use Quellabs\Recommender\Internal\Reconciliation\CandidateRoundState;

/** Combines explicitly selected candidate generators using reciprocal ranks. */
readonly class RecommendationReconciler {
	
	/** @var Connection Ratings database connection */
	private Connection $connection;
	
	/** @var RecommendationConfig Recommender settings */
	private RecommendationConfig $config;
	
	/**
	 * Build the reconciler.
	 * @param Connection $connection Ratings database connection
	 * @param RecommendationConfig $config Recommender settings
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
	}
	
	/**
	 * Return the top requested results for a persisted member.
	 * @param int $memberId Member ID
	 * @param ReconciliationRequest $request Candidate request
	 * @return RecommendationList Top requested results
	 */
	public function recommendMember(int $memberId, ReconciliationRequest $request): RecommendationList {
		return $this->firstPage($this->rankCandidatesMember($memberId, $request), $request->limit);
	}
	
	/**
	 * Return the top requested results for an anonymous visitor.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param ReconciliationRequest $request Candidate request
	 * @return RecommendationList Top requested results
	 */
	public function recommendVisitor(VisitorContext $visitor, ReconciliationRequest $request): RecommendationList {
		return $this->firstPage($this->rankCandidatesVisitor($visitor, $request), $request->limit);
	}
	
	/**
	 * Return the full bounded eligible pool for a persisted member.
	 * @param int $memberId Member ID
	 * @param ReconciliationRequest $request Candidate request
	 * @return RecommendationList Full bounded eligible pool
	 * @throws \InvalidArgumentException When the member ID is not an unsigned 32-bit integer
	 */
	public function rankCandidatesMember(int $memberId, ReconciliationRequest $request): RecommendationList {
		if ($memberId < 0 || $memberId > 4294967295) {
			throw new \InvalidArgumentException("Member ID must be an unsigned 32-bit integer, got {$memberId}.");
		}
		
		$category = $this->config->resolveCategory($request->category);
		$rows = $this->connection->execute('SELECT product_id, rating FROM vogoo_ratings
            WHERE member_id = :member AND category = :category',
			['member' => $memberId, 'category' => $category])->fetchAll('assoc');
			
		$ratings = [];
		
		foreach ($rows as $row) {
			$ratings[(int)$row['product_id']] = (float)$row['rating'];
		}
		
		return $this->rank($request, $category, $ratings, $memberId);
	}
	
	/**
	 * Return the full bounded eligible pool for an anonymous visitor.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param ReconciliationRequest $request Candidate request
	 * @return RecommendationList Full bounded eligible pool
	 * @throws \InvalidArgumentException When the request includes the user-similarity source
	 */
	public function rankCandidatesVisitor(VisitorContext $visitor, ReconciliationRequest $request): RecommendationList {
		if (in_array(RecommendationSource::UserSimilarity, $request->sources, true)) {
			throw new \InvalidArgumentException('User similarity requires a persisted member.');
		}
		
		$category = $this->config->resolveCategory($request->category);
		$ratings = [];
		
		foreach ($visitor->getRatings($category) as $row) {
			$ratings[$row['product_id']] = $row['rating'];
		}
		
		return $this->rank($request, $category, $ratings, null);
	}
	
	/**
	 * Return the first page of a list, keeping its metadata.
	 * @param RecommendationList $list Full pool
	 * @param int $limit Requested maximum
	 * @return RecommendationList Limited list
	 */
	private function firstPage(RecommendationList $list, int $limit): RecommendationList {
		return new RecommendationList($list->category, $list->placement, $list->sources, $list->contextKey,
			$list->scoreKind, $list->modelId, array_slice($list->items, 0, $limit), $limit);
	}
	
	/**
	 * Run the bounded candidate rounds and rank every eligible candidate by reciprocal-rank fusion or the active model.
	 * @param ReconciliationRequest $request Request
	 * @param int $category Resolved category
	 * @param array<int, float> $ratings Seen ratings
	 * @param int|null $memberId Member or visitor
	 * @return RecommendationList Full bounded rank-fusion pool
	 * @throws \RuntimeException When a source changes its candidate order during depth backfill
	 */
	private function rank(ReconciliationRequest $request, int $category, array $ratings, ?int $memberId): RecommendationList {
		$depthCap = $request->maxCandidateDepth ?? $this->config->getMaxCandidateDepth();
		$roundCap = $request->maxBackfillRounds ?? $this->config->getMaxBackfillRounds();
		$batchSize = max(1, $request->maxEligibilityBatchSize ?? $this->config->getMaxEligibilityBatchSize());
		$state = new CandidateRoundState($request->sources, min(max(50, 5 * $request->limit), $depthCap), $depthCap);
		
		$this->collectCandidateRounds($request, $state, $category, $ratings, $memberId, $roundCap, $batchSize);
		
		$signals = $this->collectSignals($request, $state);
		$auditSignals = $this->auditSignals($state->eligibleIds(), $signals, $request, $ratings, $memberId, $category);
		$activeModel = $this->activeModel($category, $request);
		$items = [];
		
		foreach ($state->eligibleIds() as $id) {
			$evidence = array_merge($signals[$id] ?? [], $auditSignals[$id] ?? []);
			$items[] = $this->buildRankedItem($id, $evidence, $request->sources, $state->depths(), $activeModel);
		}
		
		usort($items, fn($a, $b) => ($b->rankingScore <=> $a->rankingScore) ?: ($a->itemId <=> $b->itemId));
		
		return new RecommendationList($category, $request->placement, $request->sources, $request->contextKey,
			$activeModel === null ? 'rank_fusion' : 'click_probability', $activeModel[0] ?? null,
			$items, $request->limit);
	}
	
	/**
	 * Query the sources round by round, sending new candidates to eligibility until enough are found or the caps are reached.
	 * @param ReconciliationRequest $request Request
	 * @param CandidateRoundState $state Round bookkeeping, updated in place
	 * @param int $category Resolved category
	 * @param array<int, float> $ratings Seen ratings
	 * @param int|null $memberId Member or visitor
	 * @param int $roundCap Maximum number of rounds
	 * @param int<1, max> $batchSize Maximum IDs per eligibility call
	 * @return void
	 * @throws \RuntimeException When a source changes its candidate order during depth backfill
	 */
	private function collectCandidateRounds(ReconciliationRequest $request, CandidateRoundState $state, int $category,
		array $ratings, ?int $memberId, int $roundCap, int $batchSize): void {
		$additional = array_values(array_filter($request->additionalCandidateIds,
			fn($id) => !array_key_exists($id, $ratings)));
			
		for ($round = 0; ; $round++) {
			$newIds = $round === 0 ? $additional : [];
			
			foreach ($request->sources as $source) {
				$key = $source->value;
				
				if ($round > 0 && $state->depth($key) <= $state->nominationCount($key)) {
					continue;
				}
				
				$current = $this->generate($source, $memberId, $ratings, $category, $state->depth($key), $request);
				$old = $state->nominations($key);
				
				if (array_slice(array_column($current, 'id'), 0, count($old)) !== array_column($old, 'id')) {
					throw new \RuntimeException("Candidate order changed during depth backfill for source '{$key}'.");
				}
				
				foreach (array_slice($current, count($old)) as $row) {
					if (!$state->isSubmitted($row['id'])) {
						$newIds[] = $row['id'];
					}
				}
				
				$state->setNominations($key, $current);
			}
			
			foreach (array_chunk($state->claimUnsubmitted($newIds), $batchSize) as $chunk) {
				foreach ($this->filterEligibleBatch($request->eligibility, $chunk) as $id) {
					$state->markEligible($id);
				}
			}
			
			if ($state->eligibleCount() >= $request->limit || $round >= $roundCap) {
				break;
			}
			
			if (!$state->growDepths()) {
				break;
			}
		}
	}
	
	/**
	 * Check eligibility for one batch of candidate IDs and validate the provider's answer.
	 * @param EligibilityProvider $eligibility Application eligibility check
	 * @param array<int, int> $batch Candidate IDs to check
	 * @return array<int, int> Eligible IDs in candidate order
	 * @throws \UnexpectedValueException When the provider answer is not an ordered subset of the batch
	 */
	private function filterEligibleBatch(EligibilityProvider $eligibility, array $batch): array {
		$response = $eligibility->filterEligible($batch);
		$this->validateEligibilityResponse($batch, $response);
		return $response;
	}
	
	/**
	 * Record the rank of each eligible nominated candidate per source.
	 * @param ReconciliationRequest $request Enabled sources
	 * @param CandidateRoundState $state Round bookkeeping holding the nominations and eligible IDs
	 * @return array<int, array<int, SourceEvidence>> Ranked evidence per candidate ID
	 */
	private function collectSignals(ReconciliationRequest $request, CandidateRoundState $state): array {
		$signals = [];
		
		foreach ($request->sources as $source) {
			$rank = 0;
			
			foreach ($state->nominations($source->value) as $row) {
				$id = $row['id'];
				
				if (!$state->isEligible($id)) {
					continue;
				}
				
				$rank++;
				$signals[$id][] = new SourceEvidence($source, $row['score'], $rank, $row['count'], $row['contributors']);
			}
		}
		
		return $signals;
	}
	
	/**
	 * Build one ranked item from its evidence, using the active click model when one applies.
	 * @param int $id Candidate ID
	 * @param array<int, SourceEvidence> $evidence Source signals for the candidate
	 * @param array<int, RecommendationSource> $sources Enabled sources
	 * @param array<string, int> $depths Searched depth per source
	 * @param array{0: string, 1: ClickModel}|null $activeModel Active model token and model, when calibrated
	 * @return ReconciledRecommendation Ranked candidate
	 */
	private function buildRankedItem(int $id, array $evidence, array $sources, array $depths, ?array $activeModel): ReconciledRecommendation {
		$score = 0.0;
		$features = [];
		
		foreach ($sources as $source) {
			$prefix = $source->value . '.';
			$features[$prefix . 'log_depth_searched'] = log($depths[$source->value]);
			$features[$prefix . 'present'] = 0.0;
			$features[$prefix . 'reciprocal_rank'] = 0.0;
			$features[$prefix . 'score'] = 0.0;
			$features[$prefix . 'count'] = 0.0;
		}
		
		foreach ($evidence as $signal) {
			if ($signal->sourceRank === null) {
				continue;
			}
			
			$prefix = $signal->source->value . '.';
			$reciprocal = 1 / (60 + $signal->sourceRank);
			$score += $reciprocal;
			$features[$prefix . 'present'] = 1.0;
			$features[$prefix . 'reciprocal_rank'] = $reciprocal;
			$features[$prefix . 'score'] = $signal->source === RecommendationSource::ItemLinks
				? log1p(max(0.0, $signal->rawScore ?? 0.0)) : ($signal->rawScore ?? 0.0);
			$features[$prefix . 'count'] = log1p($signal->supportCount ?? 0);
		}
		
		$contributions = [];
		
		if ($activeModel !== null) {
			[, $model] = $activeModel;
			$score = $model->probability($features, 1);
			$contributions = $model->sourceContributions($features);
			$evidence = array_map(fn($signal) => new SourceEvidence($signal->source,
				$signal->rawScore, $signal->sourceRank, $signal->supportCount,
				$signal->contributingItemIds, $contributions[$signal->source->value] ?? 0.0), $evidence);
		}
		
		return new ReconciledRecommendation($id, $score, $evidence, $features, $contributions, $depths);
	}
	
	/**
	 * Return the active click model for the request partition, when the optional model tables exist.
	 * @param int $category Resolved category
	 * @param ReconciliationRequest $request Model partition key
	 * @return array{0: string, 1: ClickModel}|null Active model token and model, or null when none is active
	 * @throws \UnexpectedValueException When the model schema or feature names do not match the request
	 */
	private function activeModel(int $category, ReconciliationRequest $request): ?array {
		$exists = $this->connection->execute('SELECT COUNT(*) AS total FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = \'recommender_models\'')->fetchAssoc();
		
		if ((int)$exists['total'] === 0) {
			return null;
		}
		
		$row = $this->connection->execute('SELECT HEX(id) AS model_id, feature_schema_version, artifact
            FROM recommender_models WHERE objective = \'click\' AND category = ? AND placement = ?
                AND source_mask = ? AND context_key = ? AND status = \'active\'',
			[$category, $request->placement, $request->sourceMask(), $request->contextKey ?? ''])->fetchAssoc();
			
		if (!$row) {
			return null;
		}
		
		if ((int)$row['feature_schema_version'] !== 1) {
			throw new \UnexpectedValueException("Active click model feature schema version {$row['feature_schema_version']} is incompatible; expected 1.");
		}
		
		$model = ClickModel::fromJson((string)$row['artifact']);
		$expected = ['log_position'];
		
		foreach ($request->sources as $source) {
			foreach (['log_depth_searched', 'present', 'reciprocal_rank', 'score', 'count'] as $name) {
				$expected[] = $source->value . '.' . $name;
			}
		}
		
		sort($expected);
		
		if ($model->featureNames() !== $expected) {
			throw new \UnexpectedValueException("Active click model {$row['model_id']} feature names do not match the request sources.");
		}
		
		return [strtolower((string)$row['model_id']), $model];
	}
	
	/**
	 * Check that the eligibility answer is an ordered subset of the submitted batch.
	 * @param array<int, int> $submitted Submitted batch
	 * @param array<mixed> $response Provider response
	 * @return void
	 * @throws \UnexpectedValueException When the response has a non-integer ID or is out of order
	 */
	private function validateEligibilityResponse(array $submitted, array $response): void {
		$cursor = 0;
		
		foreach ($response as $id) {
			if (!is_int($id)) {
				throw new \UnexpectedValueException('Eligibility response contains a non-integer ID: ' . var_export($id, true) . '.');
			}
			
			while ($cursor < count($submitted) && $submitted[$cursor] !== $id) {
				$cursor++;
			}
			
			if ($cursor === count($submitted)) {
				throw new \UnexpectedValueException("Eligibility response ID {$id} is not an ordered subset of the submitted IDs.");
			}
			
			$cursor++;
		}
	}
	
	/**
	 * Find the audit signals for eligible candidates that no bounded source nominated.
	 * @param array<int, int> $eligibleIds Eligible pool IDs
	 * @param array<int, array<int, SourceEvidence>> $nominatedSignals Depth-bounded nominations
	 * @param ReconciliationRequest $request Enabled source settings
	 * @param array<int, float> $ratings Seen ratings
	 * @param int|null $memberId Persisted member or visitor
	 * @param int $category Resolved category
	 * @return array<int, array<int, SourceEvidence>> Additional audit signals without source rank
	 */
	private function auditSignals(array $eligibleIds, array $nominatedSignals,
		ReconciliationRequest $request, array $ratings, ?int $memberId, int $category): array {
		$audit = [];
		
		foreach ($request->sources as $source) {
			$missing = $this->unnominatedIds($eligibleIds, $nominatedSignals, $source);
			
			if ($missing === []) {
				continue;
			}
			
			if ($source === RecommendationSource::NewProducts) {
				$this->auditNewProducts($missing, $request, $audit);
				continue;
			}
			
			if ($source === RecommendationSource::UserSimilarity) {
				if ($memberId === null) {
					continue;
				}
				
				$this->auditUserSimilarity($memberId, $missing, $request, $category, $audit);
				continue;
			}
			
			if ($source === RecommendationSource::TopRated) {
				$rows = $this->auditTopRatedRows($missing, $request, $category);
			} else {
				$genuine = array_filter($ratings, fn($rating) => $rating >= 0.0);
				
				if ($genuine === []) {
					continue;
				}
				
				$rows = $this->auditRatingRows($source, $missing, $genuine, $category, $request);
			}
			
			foreach ($this->normalizeRows($rows, $ratings) as $row) {
				$audit[$row['id']][] = new SourceEvidence($source, $row['score'], null,
					$row['count'], $row['contributors']);
			}
		}
		
		return $audit;
	}
	
	/**
	 * Return the eligible IDs that the given source did not nominate.
	 * @param array<int, int> $eligibleIds Eligible pool IDs
	 * @param array<int, array<int, SourceEvidence>> $nominatedSignals Depth-bounded nominations
	 * @param RecommendationSource $source Source to check
	 * @return array<int, int> IDs without a nomination from this source
	 */
	private function unnominatedIds(array $eligibleIds, array $nominatedSignals, RecommendationSource $source): array {
		$missing = [];
		
		foreach ($eligibleIds as $id) {
			$nominatedSources = array_map(fn($signal) => $signal->source, $nominatedSignals[$id] ?? []);
			
			if (!in_array($source, $nominatedSources, true)) {
				$missing[] = $id;
			}
		}
		
		return $missing;
	}
	
	/**
	 * Add new-product evidence for missing candidates that are listed as new products.
	 * @param array<int, int> $missing Candidate IDs without a nomination
	 * @param ReconciliationRequest $request Request with the new-product list
	 * @param array<int, array<int, SourceEvidence>> $audit Audit signals, updated in place
	 * @return void
	 */
	private function auditNewProducts(array $missing, ReconciliationRequest $request, array &$audit): void {
		$newProducts = array_fill_keys($request->newProductIds, true);
		
		foreach ($missing as $id) {
			if (isset($newProducts[$id])) {
				$audit[$id][] = new SourceEvidence(RecommendationSource::NewProducts);
			}
		}
	}
	
	/**
	 * Add user-similarity evidence for missing candidates scored against the member's neighbours.
	 * @param int $memberId Persisted member
	 * @param array<int, int> $missing Candidate IDs without a nomination
	 * @param ReconciliationRequest $request Request with neighbour settings
	 * @param int $category Resolved category
	 * @param array<int, array<int, SourceEvidence>> $audit Audit signals, updated in place
	 * @return void
	 */
	private function auditUserSimilarity(int $memberId, array $missing, ReconciliationRequest $request,
		int $category, array &$audit): void {
		$similarity = new UserSimilarity($this->connection, $this->config,
			new RecommendationEngine($this->connection, $this->config));
		$rows = $similarity->memberRecommendationsScored($memberId,
			$request->minNeighbourSimilarity, $request->maxNeighbours,
			count($missing), $category, $missing);
			
		foreach ($rows as $row) {
			$audit[$row['itemId']][] = new SourceEvidence(RecommendationSource::UserSimilarity, $row['score']);
		}
	}
	
	/**
	 * Score missing candidates against the top-rated aggregate.
	 * @param array<int, int> $missing Candidate IDs without a nomination
	 * @param ReconciliationRequest $request Request with the top-rated minimum
	 * @param int $category Resolved category
	 * @return array<int, array<string, mixed>> Aggregate candidate rows
	 */
	private function auditTopRatedRows(array $missing, ReconciliationRequest $request, int $category): array {
		return $this->withIdTable('recommender_audit_candidates_', $missing, fn($table) => $this->connection->execute(
			"SELECT r.product_id AS id, AVG(r.rating) AS score, COUNT(*) AS support_count
            FROM vogoo_ratings r JOIN {$table} candidates ON candidates.product_id = r.product_id
            WHERE r.category = :category AND r.rating >= 0
            GROUP BY r.product_id HAVING support_count >= :minimum",
			['category' => $category, 'minimum' => $request->topRatedMinRatings])->fetchAll('assoc'));
	}
	
	/**
	 * Score missing candidates from the seen ratings of item-links or Slope One.
	 * @param RecommendationSource $source Item-links or Slope One
	 * @param array<int, int> $candidateIds Eligible IDs to score
	 * @param array<int, float> $ratings Genuine ratings
	 * @param int $category Resolved category
	 * @param ReconciliationRequest $request Source thresholds
	 * @return array<int, array<string, mixed>> Aggregate candidate rows
	 */
	private function auditRatingRows(RecommendationSource $source, array $candidateIds,
		array $ratings, int $category, ReconciliationRequest $request): array {
		$ratingsTable = 'recommender_audit_ratings_' . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$ratingsTable}
            (product_id INT UNSIGNED PRIMARY KEY, rating DOUBLE NOT NULL)");
		
		try {
			foreach (array_chunk($ratings, 500, true) as $batch) {
				$holders = [];
				$params = [];
				
				foreach ($batch as $id => $rating) {
					$holders[] = '(?, ?)';
					$params[] = $id;
					$params[] = $rating;
				}
				
				$this->connection->execute("INSERT INTO {$ratingsTable} (product_id, rating) VALUES "
					. implode(',', $holders), $params);
			}
			
			return $this->withIdTable('recommender_audit_candidates_', $candidateIds, function ($candidateTable) use (
				$source,
				$ratingsTable, $category, $request
			): array {
				if ($source === RecommendationSource::ItemLinks) {
					$sql = "SELECT l.item_id2 AS id,
                    SUM(l.liked_count * (r.rating - :threshold)) AS score,
                    JSON_ARRAYAGG(r.product_id) AS contributors
                    FROM vogoo_links l JOIN {$ratingsTable} r ON r.product_id = l.item_id1
                    JOIN {$candidateTable} candidates ON candidates.product_id = l.item_id2
                    WHERE l.category = :category AND l.liked_count > 0
                    GROUP BY l.item_id2 HAVING score > 0";
					$params = ['threshold' => $this->config->getThresholdRating(), 'category' => $category];
				} else {
					$sql = "SELECT l.item_id2 AS id, SUM(l.slope_count) AS support_count,
                    LEAST(1.0, GREATEST(0.0,
                        SUM(r.rating * l.slope_count + l.diff_slope) / SUM(l.slope_count))) AS score
                    FROM vogoo_links l JOIN {$ratingsTable} r ON r.product_id = l.item_id1
                    JOIN {$candidateTable} candidates ON candidates.product_id = l.item_id2
                    WHERE l.category = :category AND l.slope_count > 0
                    GROUP BY l.item_id2 HAVING support_count >= :minimum";
					$params = ['category' => $category, 'minimum' => $request->minSlopeSupport];
				}
				
				return $this->connection->execute($sql, $params)->fetchAll('assoc');
			});
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$ratingsTable}");
		}
	}
	
	/**
	 * Load IDs into a temporary table for the duration of one operation.
	 * @template T
	 * @param string $prefix Table name prefix, followed by a random suffix
	 * @param array<int, int> $ids Distinct IDs to load
	 * @param callable(string): T $operation Query receiving the temporary table name
	 * @return T Query result
	 */
	private function withIdTable(string $prefix, array $ids, callable $operation): mixed {
		$table = $prefix . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$table} (product_id INT UNSIGNED PRIMARY KEY)");
		
		try {
			foreach (array_chunk($ids, 500) as $batch) {
				$holders = implode(',', array_fill(0, count($batch), '(?)'));
				$this->connection->execute("INSERT INTO {$table} (product_id) VALUES {$holders}", $batch);
			}
			
			return $operation($table);
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$table}");
		}
	}
	
	/**
	 * Generate the bounded candidates of one source.
	 * @param RecommendationSource $source Candidate generator
	 * @param int|null $memberId Member or visitor
	 * @param array<int, float> $ratings Seen ratings
	 * @param int $category Resolved category
	 * @param int $depth Requested source depth
	 * @param ReconciliationRequest $request Source settings
	 * @return array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>
	 */
	private function generate(RecommendationSource $source, ?int $memberId, array $ratings,
		int $category, int $depth, ReconciliationRequest $request): array {
		if ($source === RecommendationSource::NewProducts) {
			return $this->generateNewProducts($request, $ratings, $depth);
		}
		
		if ($source === RecommendationSource::TopRated) {
			return $this->generateTopRated($ratings, $category, $depth, $request);
		}
		
		if ($source === RecommendationSource::UserSimilarity) {
			return $this->generateUserSimilarity($memberId, $category, $depth, $request);
		}
		
		$genuine = array_filter($ratings, fn($rating) => $rating >= 0.0);
		
		if ($genuine === []) {
			return [];
		}
		
		return $this->generateFromRatings($source, $memberId, $genuine, $ratings, $category, $depth, $request);
	}
	
	/**
	 * Return the first unseen new products, up to the depth.
	 * @param ReconciliationRequest $request Request with the new-product list
	 * @param array<int, float> $ratings Seen ratings
	 * @param int $depth Requested source depth
	 * @return array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>
	 */
	private function generateNewProducts(ReconciliationRequest $request, array $ratings, int $depth): array {
		$rows = [];
		
		foreach (array_slice($request->newProductIds, 0, $depth) as $id) {
			if (!array_key_exists($id, $ratings)) {
				$rows[] = ['id' => $id, 'score' => null, 'count' => null, 'contributors' => []];
			}
		}
		
		return $rows;
	}
	
	/**
	 * Return the top-rated items the member has not seen, up to the depth.
	 * @param array<int, float> $ratings Seen ratings
	 * @param int $category Resolved category
	 * @param int $depth Requested source depth
	 * @param ReconciliationRequest $request Source settings
	 * @return array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>
	 * @throws \UnexpectedValueException When the query does not return an array
	 */
	private function generateTopRated(array $ratings, int $category, int $depth, ReconciliationRequest $request): array {
		$rows = $this->withSeenTable($ratings, fn($seenTable) => $this->connection->execute(
			"SELECT r.product_id AS id, AVG(r.rating) AS score, COUNT(*) AS support_count
            FROM vogoo_ratings r WHERE r.category = :category AND r.rating >= 0
            AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = r.product_id)
            GROUP BY r.product_id HAVING support_count >= :minimum
            ORDER BY score DESC, id ASC LIMIT {$depth}",
			['category' => $category, 'minimum' => $request->topRatedMinRatings])->fetchAll('assoc'));
			
		if (!is_array($rows)) {
			throw new \UnexpectedValueException('Top-rated query did not return an array of rows.');
		}
		
		return $this->normalizeRows($rows, $ratings);
	}
	
	/**
	 * Return the similarity-scored neighbour candidates, up to the depth.
	 * @param int|null $memberId Member, required for user similarity
	 * @param int $category Resolved category
	 * @param int $depth Requested source depth
	 * @param ReconciliationRequest $request Source settings
	 * @return array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>
	 * @throws \InvalidArgumentException When no member is given
	 */
	private function generateUserSimilarity(?int $memberId, int $category, int $depth, ReconciliationRequest $request): array {
		if ($memberId === null) {
			throw new \InvalidArgumentException('User similarity requires a persisted member.');
		}
		
		$similarity = new UserSimilarity($this->connection, $this->config,
			new RecommendationEngine($this->connection, $this->config));
		$rows = $similarity->memberRecommendationsScored($memberId, $request->minNeighbourSimilarity,
			$request->maxNeighbours, $depth, $category);
			
		return array_map(fn($row) => ['id' => $row['itemId'], 'score' => $row['score'],
			'count' => null, 'contributors' => []], $rows);
	}
	
	/**
	 * Generate item-links or Slope One candidates from the member's genuine ratings, up to the depth.
	 * @param RecommendationSource $source Item-link or Slope One source
	 * @param int|null $memberId Member or visitor
	 * @param array<int, float> $genuine Genuine ratings
	 * @param array<int, float> $seen All seen ratings
	 * @param int $category Resolved category
	 * @param int $depth Source depth
	 * @param ReconciliationRequest $request Source settings
	 * @return array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>
	 */
	private function generateFromRatings(RecommendationSource $source, ?int $memberId, array $genuine,
		array $seen, int $category, int $depth, ReconciliationRequest $request): array {
		$table = 'recommender_source_input_' . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$table} (product_id INT UNSIGNED PRIMARY KEY, rating DOUBLE NOT NULL)");
		
		try {
			foreach (array_chunk($genuine, 500, true) as $batch) {
				$params = [];
				$values = [];
				
				foreach ($batch as $id => $rating) {
					$values[] = '(?, ?)';
					$params[] = $id;
					$params[] = $rating;
				}
				
				$this->connection->execute("INSERT INTO {$table} (product_id, rating) VALUES " . implode(',', $values), $params);
			}
			
			return $this->withSeenTable($seen, function ($seenTable) use ($source, $table, $category, $depth, $request, $seen) {
				if ($source === RecommendationSource::ItemLinks) {
					$sql = "SELECT l.item_id2 AS id, SUM(l.liked_count * (i.rating - :threshold)) AS score,
                    JSON_ARRAYAGG(i.product_id) AS contributors
                    FROM vogoo_links l JOIN {$table} i ON i.product_id = l.item_id1
                    WHERE l.category = :category AND l.liked_count > 0
                    AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)
                    GROUP BY l.item_id2 HAVING score > 0 ORDER BY score DESC, id ASC LIMIT {$depth}";
					$params = ['threshold' => $this->config->getThresholdRating(), 'category' => $category];
				} else {
					$sql = "SELECT l.item_id2 AS id, SUM(l.slope_count) AS support_count,
                    LEAST(1.0, GREATEST(0.0,
                        SUM(i.rating * l.slope_count + l.diff_slope) / SUM(l.slope_count))) AS score
                    FROM vogoo_links l JOIN {$table} i ON i.product_id = l.item_id1
                    WHERE l.category = :category AND l.slope_count > 0
                    AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)
                    GROUP BY l.item_id2 HAVING support_count >= :minimum
                    ORDER BY score DESC, support_count DESC, id ASC LIMIT {$depth}";
					$params = ['category' => $category, 'minimum' => $request->minSlopeSupport];
				}
				
				$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
				return $this->normalizeRows($rows, $seen);
			});
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$table}");
		}
	}
	
	/**
	 * Load the seen IDs into a temporary table for the duration of one operation.
	 * @template T
	 * @param array<int, float> $seen Previously rated or rejected IDs
	 * @param callable(string): T $operation Source query receiving the temporary table name
	 * @return T Query result
	 */
	private function withSeenTable(array $seen, callable $operation): mixed {
		return $this->withIdTable('recommender_seen_', array_keys($seen), $operation);
	}
	
	/**
	 * Convert raw SQL rows into candidates, dropping IDs that were already seen.
	 * @param array<mixed> $rows SQL rows
	 * @param array<int, float> $seen Previously rated and rejected IDs
	 * @return array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>
	 * @throws \UnexpectedValueException When a row or its contributors are malformed
	 */
	private function normalizeRows(array $rows, array $seen): array {
		$result = [];
		
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id'])
				|| (isset($row['score']) && !is_numeric($row['score']))
				|| (isset($row['support_count']) && !is_numeric($row['support_count']))) {
				throw new \UnexpectedValueException('Source candidate row must have a numeric id, and numeric score and support_count when present.');
			}
			
			$id = (int)$row['id'];
			
			if (array_key_exists($id, $seen)) {
				continue;
			}
			
			$result[] = [
				'id'           => $id,
				'score'        => isset($row['score']) ? (float)$row['score'] : null,
				'count'        => isset($row['support_count']) ? (int)$row['support_count'] : null,
				'contributors' => $this->decodeContributors($row),
			];
		}
		
		return $result;
	}
	
	/**
	 * Decode the JSON contributor list of a candidate row.
	 * @param array<mixed> $row SQL row, which may hold a JSON "contributors" string
	 * @return array<int, int> Contributing item IDs, empty when the row has none
	 * @throws \UnexpectedValueException When the contributors are not a JSON array of IDs
	 */
	private function decodeContributors(array $row): array {
		if (!isset($row['contributors'])) {
			return [];
		}
		
		if (!is_string($row['contributors'])) {
			throw new \UnexpectedValueException('Source contributors must be a JSON string, got ' . get_debug_type($row['contributors']) . '.');
		}
		
		$decoded = json_decode($row['contributors'], true, 512, JSON_THROW_ON_ERROR);
		
		if (!is_array($decoded)) {
			throw new \UnexpectedValueException('Source contributors must decode to a JSON array.');
		}
		
		$contributors = [];
		
		foreach ($decoded as $contributor) {
			if (!is_int($contributor) && (!is_string($contributor) || !ctype_digit($contributor))) {
				throw new \UnexpectedValueException('Source contributor ID must be an unsigned integer, got ' . var_export($contributor, true) . '.');
			}
			
			$contributors[] = (int)$contributor;
		}
		
		return $contributors;
	}
}
