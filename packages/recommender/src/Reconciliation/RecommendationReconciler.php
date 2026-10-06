<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Model\ActiveModel;
	use Quellabs\Recommender\Internal\Model\ClickModel;
	use Quellabs\Recommender\Internal\Model\ClickModelScorer;
	use Quellabs\Recommender\Internal\Model\SourceFeatures;
	use Quellabs\Recommender\Internal\Reconciliation\CandidateRoundState;
	use Quellabs\Recommender\Internal\Query\SubjectRatings;
	use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;

	use Quellabs\Recommender\Sources\UserSimilaritySource;
	use Quellabs\Recommender\Sources\SlopeOneSource;
	use Quellabs\Recommender\Sources\ItemLinksSource;
	use Quellabs\Recommender\Sources\TopRatedSource;
	use Quellabs\Recommender\Sources\NewProductsSource;

	use Quellabs\Recommender\RecommendationList;
	use Quellabs\Recommender\RecommendationResult;
	use Quellabs\Recommender\ScoreKind;

	use Quellabs\Recommender\RecommendationSource;

	use Quellabs\Recommender\Subject;
	use Quellabs\Recommender\SubjectKind;
	use Quellabs\Recommender\VisitorContext;

	/**
	 * Combines explicitly selected candidate generators using reciprocal ranks.
	 *
	 * @phpstan-import-type CandidateRow from CandidateRoundState
	 */
	readonly class RecommendationReconciler {

		/** @var Connection Ratings database connection */
		private Connection $connection;

		/** @var RecommendationConfig Recommender settings */
		private RecommendationConfig $config;

		/** @var EligibilityFilter Batched eligibility checks */
		private EligibilityFilter $filter;

		/** @var SubjectRatings Seen ratings of member and visitor subjects */
		private SubjectRatings $ratings;

		/** @var UserSimilaritySource User-similarity candidate source */
		private UserSimilaritySource $similarity;

		/** @var SlopeOneSource Slope One candidate source */
		private SlopeOneSource $slopeOne;

		/** @var ItemLinksSource Item-links candidate source */
		private ItemLinksSource $itemLinks;

		/** @var TopRatedSource Top-rated candidate source */
		private TopRatedSource $topRated;
		
		/** @var RankFusionScorer Scorer for lists without an active click model */
		private RankFusionScorer $rankFusion;

		/**
		 * Build the reconciler.
		 * @param Connection $connection Ratings database connection
		 * @param RecommendationConfig $config Recommender settings
		 * @param UserSimilaritySource $similarity User-similarity candidate source
		 * @param SlopeOneSource $slopeOne Slope One candidate source
		 * @param ItemLinksSource $itemLinks Item-links candidate source
		 * @param TopRatedSource $topRated Top-rated candidate source
		 */
		public function __construct(Connection $connection, RecommendationConfig $config, UserSimilaritySource $similarity, SlopeOneSource $slopeOne, ItemLinksSource $itemLinks, TopRatedSource $topRated) {
			$this->connection = $connection;
			$this->config = $config;
			$this->similarity = $similarity;
			$this->slopeOne = $slopeOne;
			$this->itemLinks = $itemLinks;
			$this->topRated = $topRated;
			$this->filter = new EligibilityFilter($config);
			$this->ratings = new SubjectRatings($connection);
			$this->rankFusion = new RankFusionScorer();
		}
		
		/**
		 * Return the displayed slate of up to the requested limit for a persisted member.
		 * @param int $member Member ID
		 * @param ReconciliationRequest $request Candidate request
		 * @return RecommendationList Displayed slate, at most the request limit
		 * @throws \InvalidArgumentException When the member ID is outside the unsigned 32-bit range
		 */
		public function memberSlate(int $member, ReconciliationRequest $request): RecommendationList {
			return $this->firstPage($this->memberCandidatePool($member, $request), $request->limit);
		}
		
		/**
		 * Return the displayed slate of up to the requested limit for an anonymous visitor.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param VisitorReconciliationRequest $request Candidate request for a visitor
		 * @return RecommendationList Displayed slate, at most the request limit
		 */
		public function visitorSlate(VisitorContext $visitor, VisitorReconciliationRequest $request): RecommendationList {
			return $this->firstPage($this->visitorCandidatePool($visitor, $request), $request->request->limit);
		}
		
		/**
		 * Return the full bounded eligible pool for a persisted member.
		 * @param int $member Member ID
		 * @param ReconciliationRequest $request Candidate request
		 * @return RecommendationList Full bounded eligible pool
		 * @throws \InvalidArgumentException When the member ID is not an unsigned 32-bit integer
		 */
		public function memberCandidatePool(int $member, ReconciliationRequest $request): RecommendationList {
			$category = $this->config->resolveCategory($request->category);

			return $this->rank($request, $category, Subject::member($member));
		}
		
		/**
		 * Return the full bounded eligible pool for an anonymous visitor.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param VisitorReconciliationRequest $request Candidate request for a visitor
		 * @return RecommendationList Full bounded eligible pool
		 */
		public function visitorCandidatePool(VisitorContext $visitor, VisitorReconciliationRequest $request): RecommendationList {
			$category = $this->config->resolveCategory($request->request->category);

			return $this->rank($request->request, $category, Subject::visitor($visitor));
		}
		
		/**
		 * Return the first page of a list, keeping its metadata.
		 * @param RecommendationList $list Full pool
		 * @param int $limit Requested maximum
		 * @return RecommendationList Limited list
		 */
		private function firstPage(RecommendationList $list, int $limit): RecommendationList {
			return RecommendationList::ranked(
				$list->category, $list->placement, $list->sources, $list->contextKey,
				$list->scoreKind, $list->modelId, array_slice($list->items, 0, $limit), $limit
			);
		}
		
		/**
		 * Run the bounded candidate rounds and rank every eligible candidate by reciprocal-rank fusion or the active model.
		 * @param ReconciliationRequest $request Request
		 * @param int $category Resolved category
		 * @param Subject $subject Member or visitor
		 * @return RecommendationList Full bounded rank-fusion pool
		 * @throws \RuntimeException When a source changes its candidate order during depth backfill
		 */
		private function rank(ReconciliationRequest $request, int $category, Subject $subject): RecommendationList {
			$ratings = $this->ratings->seen($subject, $category);
			$request = $this->applyColdStart($request, $ratings);
			$depthCap = $request->tuning->maxCandidateDepth ?? $this->config->maxCandidateDepth();
			$roundCap = $request->tuning->maxBackfillRounds ?? $this->config->maxBackfillRounds();
			$batchSize = max(1, $request->tuning->maxEligibilityBatchSize ?? $this->config->maxEligibilityBatchSize());
			$state = new CandidateRoundState($request->sources, min(max(50, 5 * $request->limit), $depthCap), $depthCap);

			$this->collectCandidateRounds($request, $state, $category, $ratings, $subject, $roundCap, $batchSize);

			$signals = $this->collectSignals($request, $state);
			$auditSignals = $this->auditSignals($state->eligibleIds(), $signals, $request, $subject, $category);
			$activeModel = $this->activeModel($category, $request);
			$scorer = $activeModel === null ? $this->rankFusion : new ClickModelScorer($activeModel->model);
			
			$items = [];
			foreach ($state->eligibleIds() as $id) {
				$evidence = array_merge($signals[$id] ?? [], $auditSignals[$id] ?? []);
				$items[] = $this->buildRankedItem($id, $evidence, $request, $state->depths(), $scorer);
			}
			
			usort($items, function ($a, $b): int {
				return ($b->rankingScore <=> $a->rankingScore) ?: ($a->productId <=> $b->productId);
			});
			
			return RecommendationList::ranked(
				$category, $request->placement, $request->sources, $request->contextKey,
				$activeModel === null ? ScoreKind::RankFusion : ScoreKind::ClickProbability, $activeModel?->id,
				$items, $request->limit
			);
		}
		
		/**
		 * Limit a subject below the minimum history to the top-rated source.
		 * @param ReconciliationRequest $request Request as the caller made it
		 * @param array<int, float> $ratings Seen ratings, where negative values are not-interested entries
		 * @return ReconciliationRequest Request with the source set narrowed when the subject is cold
		 */
		private function applyColdStart(ReconciliationRequest $request, array $ratings): ReconciliationRequest {
			$history = count(array_filter($ratings, fn(float $rating): bool => $rating >= 0.0));

			if ($history >= $request->tuning->minHistory) {
				return $request;
			}

			return $request->withSources([RecommendationSource::TopRated]);
		}

		/**
		 * Query the sources round by round, sending new candidates to eligibility until enough are found or the caps are reached.
		 * @param ReconciliationRequest $request Request
		 * @param CandidateRoundState $state Round bookkeeping, updated in place
		 * @param int $category Resolved category
		 * @param array<int, float> $ratings Seen ratings
		 * @param Subject $subject Member or visitor
		 * @param int $roundCap Maximum number of rounds
		 * @param int<1, max> $batchSize Maximum IDs per eligibility call
		 * @return void
		 * @throws \RuntimeException When a source changes its candidate order during depth backfill
		 */
		private function collectCandidateRounds(ReconciliationRequest $request, CandidateRoundState $state, int $category, array $ratings, Subject $subject, int $roundCap, int $batchSize): void {
			$additional = array_values(array_filter($request->additionalCandidateIds,
				function ($id) use ($ratings): bool {
					return !array_key_exists($id, $ratings);
				}));
			
			for ($round = 0; ; $round++) {
				$newIds = $round === 0 ? $additional : [];
				
				foreach ($request->sources as $source) {
					$newIds = array_merge($newIds, $this->nominateSource($source, $round, $request, $state, $category, $subject));
				}
				
				foreach ($this->filter->check($request->eligibility, $state->claimUnsubmitted($newIds), $batchSize) as $id) {
					$state->markEligible($id);
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
		 * Re-query one source at its current depth, record its nominations and return the newly nominated IDs.
		 * @param RecommendationSource $source Source to query
		 * @param int $round Zero-based round; later rounds skip sources whose depth is already exhausted
		 * @param ReconciliationRequest $request Request
		 * @param CandidateRoundState $state Round bookkeeping, updated in place
		 * @param int $category Resolved category
		 * @param Subject $subject Member or visitor
		 * @return array<int, int> Unsubmitted IDs this source newly nominated
		 * @throws \RuntimeException When the source changes its candidate order during depth backfill
		 */
		private function nominateSource(RecommendationSource $source, int $round, ReconciliationRequest $request,
			CandidateRoundState $state, int $category, Subject $subject): array {
			$key = $source->value;

			if ($round > 0 && $state->depth($key) <= $state->nominationCount($key)) {
				return [];
			}

			$current = $this->generate($source, $subject, $category, $state->depth($key), $request);
			$old = $state->nominations($key);
			
			if (array_slice(array_column($current, 'id'), 0, count($old)) !== array_column($old, 'id')) {
				throw new \RuntimeException("Candidate order changed during depth backfill for source '{$key}'.");
			}
			
			$newIds = [];
			
			foreach (array_slice($current, count($old)) as $row) {
				if (!$state->isSubmitted($row['id'])) {
					$newIds[] = $row['id'];
				}
			}
			
			$state->setNominations($key, $current);
			return $newIds;
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
		 * Score a candidate and build it with the features and contributions the scorer produced.
		 * @param int $id Candidate ID
		 * @param array<int, SourceEvidence> $evidence Source signals for the candidate
		 * @param ReconciliationRequest $request Request with the enabled sources and diagnostics flag
		 * @param array<string, int> $depths Searched depth per source
		 * @param CandidateScorer $scorer Scorer that produces the ranking score
		 * @return ReconciledRecommendation Ranked candidate
		 */
		private function buildRankedItem(int $id, array $evidence, ReconciliationRequest $request, array $depths, CandidateScorer $scorer): ReconciledRecommendation {
			$features = $this->features($evidence, $request->sources, $depths);
			$scored = $scorer->score($features, $evidence);
			
			if ($scored->contributions !== null) {
				$evidence = $this->withContributions($evidence, $scored->contributions);
			}
			
			$diagnostics = $request->diagnostics ? new ReconciliationDiagnostics($features, $scored->contributions ?? [], $depths) : null;
			
			return new ReconciledRecommendation($id, $scored->score, $evidence, $diagnostics);
		}
		
		/**
		 * Build the serving-time feature values of a candidate from its ranked source signals.
		 * @param array<int, SourceEvidence> $evidence Source signals for the candidate
		 * @param array<int, RecommendationSource> $sources Enabled sources
		 * @param array<string, int> $depths Searched depth per source
		 * @return array<string, float> Feature values keyed by "source.suffix"
		 */
		private function features(array $evidence, array $sources, array $depths): array {
			$features = $this->baseFeatures($sources, $depths);
			
			foreach ($evidence as $signal) {
				if ($signal->sourceRank === null) {
					continue;
				}
				
				$prefix = $signal->source->value . '.';
				$features[$prefix . 'present'] = 1.0;
				$features[$prefix . 'reciprocal_rank'] = RankFusionScorer::reciprocalRank($signal->sourceRank);
				
				if ($signal->source === RecommendationSource::ItemLinks) {
					$features[$prefix . 'score'] = log1p(max(0.0, $signal->rawScore ?? 0.0));
				} else {
					$features[$prefix . 'score'] = $signal->rawScore ?? 0.0;
				}
				
				$features[$prefix . 'count'] = log1p($signal->supportCount ?? 0);
			}
			
			return $features;
		}
		
		/**
		 * Attach each source's log-odds term from the scorer to the signals of that source.
		 * @param array<int, SourceEvidence> $evidence Source signals for the candidate
		 * @param array<string, float> $contributions Log-odds term per source value
		 * @return array<int, SourceEvidence> Signals carrying their contributions
		 */
		private function withContributions(array $evidence, array $contributions): array {
			return array_map(fn(SourceEvidence $signal): SourceEvidence => new SourceEvidence($signal->source,
				$signal->rawScore, $signal->sourceRank, $signal->supportCount, $signal->contributingProductIds,
				$contributions[$signal->source->value] ?? 0.0), $evidence);
		}
		
		/**
		 * Return the feature defaults for every enabled source: its log depth, and zero for each signal feature.
		 * @param array<int, RecommendationSource> $sources Enabled sources
		 * @param array<string, int> $depths Searched depth per source
		 * @return array<string, float> Feature values keyed by "source.suffix"
		 */
		private function baseFeatures(array $sources, array $depths): array {
			$features = [];
			
			foreach ($sources as $source) {
				$prefix = $source->value . '.';
				
				$features[$prefix . 'log_depth_searched'] = log($depths[$source->value]);
				$features[$prefix . 'present'] = 0.0;
				$features[$prefix . 'reciprocal_rank'] = 0.0;
				$features[$prefix . 'score'] = 0.0;
				$features[$prefix . 'count'] = 0.0;
			}
			
			return $features;
		}
		
		/**
		 * Return the active click model for the request partition, when the optional model tables exist.
		 * @param int $category Resolved category
		 * @param ReconciliationRequest $request Model partition key
		 * @return ActiveModel|null Active model token and model, or null when none is active
		 * @throws \UnexpectedValueException When the model schema or feature names do not match the request
		 */
		private function activeModel(int $category, ReconciliationRequest $request): ?ActiveModel {
			$exists = $this->connection->execute('
				SELECT
					COUNT(*) AS total
				FROM information_schema.tables
				WHERE table_schema = DATABASE() AND
				      table_name = :table_name
			', [
				'table_name' => 'vogoo_models',
			])->fetchAssoc();
			
			if ((int)$exists['total'] === 0) {
				return null;
			}
			
			$row = $this->connection->execute('
				SELECT
					HEX(id) AS model_id,
					feature_schema_version,
					artifact
				FROM vogoo_models
				WHERE objective = :objective AND
				      category = :category AND
				      placement = :placement AND
				      source_mask = :source_mask AND
				      context_key = :context_key AND
				      status = :status
			', [
				'objective'   => 'click',
				'category'    => $category,
				'placement'   => $request->placement,
				'source_mask' => RecommendationSource::mask($request->sources),
				'context_key' => $request->contextKey ?? '',
				'status'      => 'active',
			])->fetchAssoc();
			
			if (!$row) {
				return null;
			}
			
			if ((int)$row['feature_schema_version'] !== 1) {
				throw new \UnexpectedValueException("Active click model feature schema version {$row['feature_schema_version']} is incompatible; expected 1.");
			}
			
			$model = ClickModel::fromJson((string)$row['artifact']);
			$expected = array_merge(['log_position'], SourceFeatures::names($request->sources));
			
			sort($expected);
			
			if ($model->featureNames() !== $expected) {
				throw new \UnexpectedValueException("Active click model {$row['model_id']} feature names do not match the request sources.");
			}
			
			return new ActiveModel(strtolower((string)$row['model_id']), $model);
		}
		
		/**
		 * Find the audit signals for eligible candidates that no bounded source nominated.
		 * @param array<int, int> $eligibleIds Eligible pool IDs
		 * @param array<int, array<int, SourceEvidence>> $nominatedSignals Depth-bounded nominations
		 * @param ReconciliationRequest $request Enabled source settings
		 * @param Subject $subject Member or visitor
		 * @param int $category Resolved category
		 * @return array<int, array<int, SourceEvidence>> Additional audit signals without source rank
		 */
		private function auditSignals(array $eligibleIds, array $nominatedSignals, ReconciliationRequest $request, Subject $subject, int $category): array {
			$audit = [];

			foreach ($request->sources as $source) {
				$missing = $this->unnominatedIds($eligibleIds, $nominatedSignals, $source);

				if ($missing !== []) {
					$this->auditSource($source, $missing, $request, $subject, $category, $audit);
				}
			}
			
			return $audit;
		}
		
		/**
		 * Add the audit signals of one source for the candidates it did not nominate.
		 * @param RecommendationSource $source Source to audit
		 * @param array<int, int> $missing Eligible IDs the source did not nominate
		 * @param ReconciliationRequest $request Enabled source settings
		 * @param Subject $subject Member or visitor
		 * @param int $category Resolved category
		 * @param array<int, array<int, SourceEvidence>> $audit Audit signals by candidate, filled in place
		 * @return void
		 */
		private function auditSource(RecommendationSource $source, array $missing, ReconciliationRequest $request, Subject $subject, int $category, array &$audit): void {
			if ($source === RecommendationSource::UserSimilarity && $subject->kind !== SubjectKind::Member) {
				return;
			}

			foreach ($this->scoredBy($source, $subject, $missing, $request, $category) as $result) {
				$audit[$result->productId][] = new SourceEvidence($source, self::rawScore($result), null,
					$result->supportCount, $result->contributingProductIds);
			}
		}
		
		/**
		 * Score the given candidates with a rated source, scored against the subject.
		 * @param RecommendationSource $source Source to score with; it must have a scores() query
		 * @param Subject $subject Member or visitor
		 * @param array<int, int> $missing Candidate IDs to score
		 * @param ReconciliationRequest $request Source settings
		 * @param int $category Resolved category
		 * @return array<int, \Quellabs\Recommender\RecommendationResult> Scored candidates
		 */
		private function scoredBy(RecommendationSource $source, Subject $subject, array $missing, ReconciliationRequest $request, int $category): array {
			return match ($source) {
				RecommendationSource::UserSimilarity => $this->similarity->scores($subject, $missing, $request->tuning->sources, $category),
				RecommendationSource::NewProducts => $this->newProducts($request)->scores($subject, $missing, $request->tuning->sources, $category),
				RecommendationSource::SlopeOne => $this->slopeOne->scores($subject, $missing, $request->tuning->sources, $category),
				RecommendationSource::ItemLinks => $this->itemLinks->scores($subject, $missing, $request->tuning->sources, $category),
				RecommendationSource::TopRated => $this->topRated->scores($subject, $missing, $request->tuning->sources, $category),
			};
		}
		/**
		 * Build the new-products source for one request's ordered list.
		 * @param ReconciliationRequest $request Request with the new-product list
		 * @return NewProductsSource
		 */
		private function newProducts(ReconciliationRequest $request): NewProductsSource {
			return new NewProductsSource($this->connection, $this->config, $request->newProductIds);
		}

		/**
		 * Return the native score of a result, or null for a new product, which has no score.
		 * @param RecommendationResult $result Candidate result
		 * @return float|null
		 */
		private static function rawScore(RecommendationResult $result): ?float {
			return $result->source === RecommendationSource::NewProducts ? null : $result->score;
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
				$nominatedSources = array_map(function ($signal) {
					return $signal->source;
				}, $nominatedSignals[$id] ?? []);
				
				if (!in_array($source, $nominatedSources, true)) {
					$missing[] = $id;
				}
			}
			
			return $missing;
		}
		
		/**
		 * Generate the bounded candidates of one source.
		 * @param RecommendationSource $source Candidate generator
		 * @param Subject $subject Member or visitor
		 * @param int $category Resolved category
		 * @param int $depth Requested source depth
		 * @param ReconciliationRequest $request Source settings
		 * @return array<int, CandidateRow>
		 */
		private function generate(RecommendationSource $source, Subject $subject, int $category, int $depth, ReconciliationRequest $request): array {
			$settings = $request->tuning->sources;
			$results = match ($source) {
				RecommendationSource::NewProducts => $this->newProducts($request)->candidates($subject, null, $depth, $settings, $category),
				RecommendationSource::TopRated => $this->topRated->candidates($subject, null, $depth, $settings, $category),
				RecommendationSource::UserSimilarity => $this->similarity->candidates($subject, null, $depth, $settings, $category),
				RecommendationSource::SlopeOne => $this->slopeOne->candidates($subject, null, $depth, $settings, $category),
				RecommendationSource::ItemLinks => $this->itemLinks->candidates($subject, null, $depth, $settings, $category),
			};
			
			return array_map(self::asRow(...), $results);
		}
		
		/**
		 * Convert a recommendation result into the candidate row shape the rounds use.
		 * @param RecommendationResult $result Candidate result
		 * @return CandidateRow
		 */
		private static function asRow(RecommendationResult $result): array {
			return [
				'id'           => $result->productId,
				'score'        => self::rawScore($result),
				'count'        => $result->supportCount,
				'contributors' => $result->contributingProductIds,
			];
		}

	}
