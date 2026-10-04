<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;

/** Combines explicitly selected candidate generators using reciprocal ranks. */
readonly class RecommendationReconciler {
    /** @param Connection $connection Ratings database
     * @param RecommendationConfig $config Recommender settings
     */
    public function __construct(private Connection $connection, private RecommendationConfig $config) {}

    /** @param int $memberId Member ID
     * @param ReconciliationRequest $request Candidate request
     * @return RecommendationList Top requested results
     */
    public function recommendMember(int $memberId, ReconciliationRequest $request): RecommendationList {
        return $this->firstPage($this->rankCandidatesMember($memberId, $request), $request->limit);
    }

    /** @param VisitorContext $visitor Visitor ratings
     * @param ReconciliationRequest $request Candidate request
     * @return RecommendationList Top requested results
     */
    public function recommendVisitor(VisitorContext $visitor, ReconciliationRequest $request): RecommendationList {
        return $this->firstPage($this->rankCandidatesVisitor($visitor, $request), $request->limit);
    }

    /** @param int $memberId Member ID
     * @param ReconciliationRequest $request Candidate request
     * @return RecommendationList Full bounded eligible pool
     */
    public function rankCandidatesMember(int $memberId, ReconciliationRequest $request): RecommendationList {
        if ($memberId < 0 || $memberId > 4294967295) {
            throw new \InvalidArgumentException('Member ID must be an unsigned 32-bit integer.');
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

    /** @param VisitorContext $visitor Visitor ratings
     * @param ReconciliationRequest $request Candidate request
     * @return RecommendationList Full bounded eligible pool
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

    /** @param RecommendationList $list Full pool
     * @param int $limit Requested maximum
     * @return RecommendationList Limited list
     */
    private function firstPage(RecommendationList $list, int $limit): RecommendationList {
        return new RecommendationList($list->category, $list->placement, $list->sources, $list->contextKey,
            $list->scoreKind, $list->modelId, array_slice($list->items, 0, $limit), $limit);
    }

    /** @param ReconciliationRequest $request Request
     * @param int $category Resolved category
     * @param array<int, float> $ratings Seen ratings
     * @param int|null $memberId Member or visitor
     * @return RecommendationList Full bounded rank-fusion pool
     */
    private function rank(ReconciliationRequest $request, int $category, array $ratings, ?int $memberId): RecommendationList {
        $depthCap = $request->maxCandidateDepth ?? $this->config->getMaxCandidateDepth();
        $roundCap = $request->maxBackfillRounds ?? $this->config->getMaxBackfillRounds();
        $batchSize = max(1, $request->maxEligibilityBatchSize ?? $this->config->getMaxEligibilityBatchSize());
        $initialDepth = max(50, 5 * $request->limit);
        /** @var array<string, int> $depths */
        $depths = [];
        /** @var array<string, array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>> $previous */
        $previous = [];
        foreach ($request->sources as $source) {
            $depths[$source->value] = min($initialDepth, $depthCap);
            $previous[$source->value] = [];
        }
        $eligible = [];
        $eligibleCount = 0;
        $submitted = [];
        $additional = array_values(array_filter($request->additionalCandidateIds,
            fn($id) => !array_key_exists($id, $ratings)));
        for ($round = 0; ; $round++) {
            $newIds = $round === 0 ? $additional : [];
            foreach ($request->sources as $source) {
                $key = $source->value;
                if ($round > 0 && $depths[$key] <= count($previous[$key])) {
                    continue;
                }
                $current = $this->generate($source, $memberId, $ratings, $category, $depths[$key], $request);
                $old = $previous[$key];
                if (array_slice(array_column($current, 'id'), 0, count($old)) !== array_column($old, 'id')) {
                    throw new \RuntimeException('Candidate order changed during depth backfill.');
                }
                foreach (array_slice($current, count($old)) as $row) {
                    if (!isset($submitted[$row['id']])) {
                        $newIds[] = $row['id'];
                    }
                }
                $previous[$key] = $current;
            }
            $batch = [];
            foreach ($newIds as $id) {
                if (!isset($submitted[$id])) {
                    $submitted[$id] = true;
                    $batch[] = $id;
                }
            }
            foreach (array_chunk($batch, $batchSize) as $chunk) {
                $response = $request->eligibility->filterEligible($chunk);
                $this->validateEligibilityResponse($chunk, $response);
                foreach ($response as $id) {
                    $eligible[$id] = true;
                    $eligibleCount++;
                }
            }
            if ($eligibleCount >= $request->limit || $round >= $roundCap) {
                break;
            }
            $growing = false;
            foreach ($request->sources as $source) {
                $key = $source->value;
                if ($depths[$key] < $depthCap && count($previous[$key]) >= $depths[$key]) {
                    $depths[$key] = min($depthCap, 2 * $depths[$key]);
                    $growing = true;
                }
            }
            if (!$growing) {
                break;
            }
        }

        /** @var array<int, array<int, SourceEvidence>> $signals */
        $signals = [];
        foreach ($request->sources as $source) {
            $rank = 0;
            foreach ($previous[$source->value] as $row) {
                $id = $row['id'];
                if (!isset($eligible[$id])) {
                    continue;
                }
                $rank++;
                $signals[$id][] = new SourceEvidence($source, $row['score'], $rank,
                    $row['count'], $row['contributors']);
            }
        }
        $auditSignals = $this->auditSignals(array_keys($eligible), $signals, $request,
            $ratings, $memberId, $category);
        $activeModel = $this->activeModel($category, $request);
        $items = [];
        foreach (array_keys($eligible) as $id) {
            $evidence = array_merge($signals[$id] ?? [], $auditSignals[$id] ?? []);
            $score = 0.0;
            $features = [];
            foreach ($request->sources as $source) {
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
                [$modelId, $model] = $activeModel;
                $score = $model->probability($features, 1);
                $contributions = $model->sourceContributions($features);
                $evidence = array_map(fn($signal) => new SourceEvidence($signal->source,
                    $signal->rawScore, $signal->sourceRank, $signal->supportCount,
                    $signal->contributingItemIds, $contributions[$signal->source->value] ?? 0.0), $evidence);
            }
            $items[] = new ReconciledRecommendation($id, $score, $evidence, $features, $contributions, $depths);
        }
        usort($items, fn($a, $b) => ($b->rankingScore <=> $a->rankingScore) ?: ($a->itemId <=> $b->itemId));
        return new RecommendationList($category, $request->placement, $request->sources, $request->contextKey,
            $activeModel === null ? 'rank_fusion' : 'click_probability', $activeModel[0] ?? null,
            $items, $request->limit);
    }

    /** @param int $category Resolved category
     * @param ReconciliationRequest $request Model partition key
     * @return array{0:string,1:ClickModel}|null Active model when optional tables exist
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
            throw new \UnexpectedValueException('Active click model feature schema is incompatible.');
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
            throw new \UnexpectedValueException('Active click model feature names do not match the request.');
        }
        return [strtolower((string)$row['model_id']), $model];
    }

    /** @param array<int, int> $submitted Submitted chunk
     * @param array<mixed> $response Provider response
     * @return void
     */
    private function validateEligibilityResponse(array $submitted, array $response): void {
        $cursor = 0;
        foreach ($response as $id) {
            if (!is_int($id)) {
                throw new \UnexpectedValueException('Eligibility response contains a noninteger ID.');
            }
            while ($cursor < count($submitted) && $submitted[$cursor] !== $id) {
                $cursor++;
            }
            if ($cursor === count($submitted)) {
                throw new \UnexpectedValueException('Eligibility response is not an ordered subset.');
            }
            $cursor++;
        }
    }

    /** @param array<int, int> $eligibleIds Eligible pool IDs
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
            $missing = [];
            foreach ($eligibleIds as $id) {
                $hasNomination = false;
                foreach ($nominatedSignals[$id] ?? [] as $signal) {
                    if ($signal->source === $source) {
                        $hasNomination = true;
                        break;
                    }
                }
                if (!$hasNomination) {
                    $missing[] = $id;
                }
            }
            if ($missing === []) {
                continue;
            }
            if ($source === RecommendationSource::NewProducts) {
                $newProducts = array_fill_keys($request->newProductIds, true);
                foreach ($missing as $id) {
                    if (isset($newProducts[$id])) {
                        $audit[$id][] = new SourceEvidence($source);
                    }
                }
                continue;
            }
            if ($source === RecommendationSource::UserSimilarity) {
                if ($memberId === null) {
                    continue;
                }
                $similarity = new UserSimilarity($this->connection, $this->config,
                    new RecommendationEngine($this->connection, $this->config));
                $rows = $similarity->memberRecommendationsScored($memberId,
                    $request->minNeighbourSimilarity, $request->maxNeighbours,
                    count($missing), $category, $missing);
                foreach ($rows as $row) {
                    $audit[$row['itemId']][] = new SourceEvidence($source, $row['score']);
                }
                continue;
            }
            if ($source === RecommendationSource::TopRated) {
                $rows = $this->withCandidateTable($missing, fn($table) => $this->connection->execute(
                    "SELECT r.product_id AS id, AVG(r.rating) AS score, COUNT(*) AS support_count
                    FROM vogoo_ratings r JOIN {$table} candidates ON candidates.product_id = r.product_id
                    WHERE r.category = :category AND r.rating >= 0
                    GROUP BY r.product_id HAVING support_count >= :minimum",
                    ['category' => $category, 'minimum' => $request->topRatedMinRatings])->fetchAll('assoc'));
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

    /** @param RecommendationSource $source Item-links or Slope One
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
            return $this->withCandidateTable($candidateIds, function ($candidateTable) use ($source,
                $ratingsTable, $category, $request): array {
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

    /** @template T
     * @param array<int, int> $candidateIds Distinct candidate IDs
     * @param callable(string): T $operation Query receiving temporary table name
     * @return T Query result
     */
    private function withCandidateTable(array $candidateIds, callable $operation): mixed {
        $table = 'recommender_audit_candidates_' . bin2hex(random_bytes(6));
        $this->connection->execute("CREATE TEMPORARY TABLE {$table} (product_id INT UNSIGNED PRIMARY KEY)");
        try {
            foreach (array_chunk($candidateIds, 500) as $batch) {
                $holders = implode(',', array_fill(0, count($batch), '(?)'));
                $this->connection->execute("INSERT INTO {$table} (product_id) VALUES {$holders}", $batch);
            }
            return $operation($table);
        } finally {
            $this->connection->execute("DROP TEMPORARY TABLE {$table}");
        }
    }

    /** @param RecommendationSource $source Candidate generator
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
            $rows = [];
            foreach (array_slice($request->newProductIds, 0, $depth) as $id) {
                if (!array_key_exists($id, $ratings)) {
                    $rows[] = ['id' => $id, 'score' => null, 'count' => null, 'contributors' => []];
                }
            }
            return $rows;
        }
        if ($source === RecommendationSource::TopRated) {
            $rows = $this->withSeenTable($ratings, fn($seenTable) => $this->connection->execute(
                "SELECT r.product_id AS id, AVG(r.rating) AS score, COUNT(*) AS support_count
                FROM vogoo_ratings r WHERE r.category = :category AND r.rating >= 0
                AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = r.product_id)
                GROUP BY r.product_id HAVING support_count >= :minimum
                ORDER BY score DESC, id ASC LIMIT {$depth}",
                ['category' => $category, 'minimum' => $request->topRatedMinRatings])->fetchAll('assoc'));
            if (!is_array($rows)) {
                throw new \UnexpectedValueException('Invalid top-rated query result.');
            }
            return $this->normalizeRows($rows, $ratings);
        }
        if ($source === RecommendationSource::UserSimilarity) {
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
        $genuine = array_filter($ratings, fn($rating) => $rating >= 0.0);
        if ($genuine === []) {
            return [];
        }
        return $this->generateFromRatings($source, $memberId, $genuine, $ratings, $category, $depth, $request);
    }

    /** @param RecommendationSource $source Item-link or Slope One source
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

    /** @template T
     * @param array<int, float> $seen Previously rated or rejected IDs
     * @param callable(string): T $operation Source query receiving temporary table name
     * @return T Query result
     */
    private function withSeenTable(array $seen, callable $operation): mixed {
        $table = 'recommender_seen_' . bin2hex(random_bytes(6));
        $this->connection->execute("CREATE TEMPORARY TABLE {$table} (product_id INT UNSIGNED PRIMARY KEY)");
        try {
            foreach (array_chunk(array_keys($seen), 500) as $batch) {
                $holders = implode(',', array_fill(0, count($batch), '(?)'));
                $this->connection->execute("INSERT INTO {$table} (product_id) VALUES {$holders}", $batch);
            }
            return $operation($table);
        } finally {
            $this->connection->execute("DROP TEMPORARY TABLE {$table}");
        }
    }

    /** @param array<mixed> $rows SQL rows
     * @param array<int, float> $seen Previously rated and rejected IDs
     * @return array<int, array{id:int,score:float|null,count:int|null,contributors:array<int,int>}>
     */
    private function normalizeRows(array $rows, array $seen): array {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id'])
                || (isset($row['score']) && !is_numeric($row['score']))
                || (isset($row['support_count']) && !is_numeric($row['support_count']))) {
                throw new \UnexpectedValueException('Invalid source candidate row.');
            }
            $id = (int)$row['id'];
            if (array_key_exists($id, $seen)) {
                continue;
            }
            $contributors = [];
            if (isset($row['contributors'])) {
                if (!is_string($row['contributors'])) {
                    throw new \UnexpectedValueException('Invalid source contributors.');
                }
                $decoded = json_decode($row['contributors'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \UnexpectedValueException('Invalid source contributors.');
                }
                foreach ($decoded as $contributor) {
                    if (!is_int($contributor) && (!is_string($contributor) || !ctype_digit($contributor))) {
                        throw new \UnexpectedValueException('Invalid source contributor ID.');
                    }
                    $contributors[] = (int)$contributor;
                }
            }
            $result[] = ['id' => $id, 'score' => isset($row['score']) ? (float)$row['score'] : null,
                'count' => isset($row['support_count']) ? (int)$row['support_count'] : null,
                'contributors' => $contributors];
        }
        return $result;
    }
}
