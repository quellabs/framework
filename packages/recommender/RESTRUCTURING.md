# Recommender restructuring plan

The package has not been released. Breaking changes to the public API are allowed, and removed methods are removed without a deprecation period.

## Decisions

1. **Sources are standalone.** Each source takes an optional `EligibilityProvider` and can be called on its own. The combiner is not required to use any source.
2. **Click models and evaluation live in a separate namespace.** They have their own tables, Sculpt commands and activation lifecycle. They stay in `packages/recommender`. The combiner does not import from them, and a test enforces this.
3. **Backfill stays.** The depth-and-backfill loop lives in the shared eligibility helper, and every source uses it.
4. **One subject type.** A small `Subject` abstraction replaces the parallel member and visitor methods.
5. **Filtering happens in sources, including under the combiner.** The combiner passes its provider to each source and does not filter. Backfill needs the source's depth loop, so the combiner cannot filter on a source's behalf.
6. **Subject covers member, visitor and product.** `Subject` is one class with a kind field and an identifier or visitor context. A product subject is the seed for item-to-item queries. Replaces `ItemRecommender`, whose item-to-item methods become product-subject queries on `ItemLinks` and `SlopeOne`.
7. **Unsupported requests are rejected.** A request that asks a source for a subject kind it does not support fails with an exception. It is not skipped silently.
8. **Single-product lookups stay, as source methods.** `SlopeOne::predict(subject, product)` returns the predicted rating and support for one product. `ItemLinks::reasons(subject, product, limit)` returns the subject's liked items that link to the product. Each answers only for the product named. Neither filters nor backfills, because the caller owns eligibility for a product it has chosen.
9. **Source-level candidates are public.** Each source's `candidates(subject, provider, limit)` is part of the public API, so a caller can use one source alone. The combiner's fused list is the second public recommendation call. Both return ranked, filtered products.

10. **Depth is the caller's, backfill is the caller's.** `CandidateSource::candidates()` takes a depth, filters the top depth candidates in one provider round, and never fetches deeper. Callers that need more eligible results deepen the request. Sources backfill when given a provider. The reconciler gives sources no provider and keeps its round loop (see Phase 3).
11. **One result type.** `RecommendationResult` carries an optional `supportCount`, null for sources that do not count support. `PredictionResult` is removed. Slope One predictions are `RecommendationResult`s whose score is the predicted rating.

## Terms

- **Rating event.** A write to the ratings store through `RecommendationEngine`: `recordClick()`, `recordPurchase()`, `setNotInterested()`. Rating events are input to the sources.
- **Outcome.** An evaluation event on a displayed item: `OutcomeType::Click` or `OutcomeType::Purchase`, logged by `EvaluationRecorder` against an impression.
- **Impression.** The record of a list as it was shown, including its order. Outcomes are attached to impressions.

"Click" in the code names a rating event. The evaluation side uses "outcome" and does not use "click" as a term of its own.

## Target shape

- **Provider.** `EligibilityProvider::filterEligible(ids): ids`. Already exists.
- **Source.** `candidates(subject, provider, limit)`. Returns eligible products with rank and score, and only for subjects the source supports. Each source declares its supported subjects. `UserSimilaritySource` is member-only. Filtering goes through the shared helper with backfill.
- **Combiner.** `recommend(subject, sources, provider, limit, scorer)`. Runs the sources, passes the same provider to each, and returns the fused list with per-source evidence. It does not filter.
- **Scorer.** Takes the per-source evidence for each product and returns a score. Rank fusion is the default. The click-model scorer implements the same interface and is trained from outcomes.
- **Item and rating engines.** The item-to-item lookups, predictions and reasons are methods on the candidate sources in `Quellabs\Recommender\Sources`. `ItemRecommender` is removed. `RecommendationEngine` keeps rating storage.
- **Evaluation and click models.** Separate namespace (`Quellabs\Recommender\Evaluation` and `Internal\Model`), same package. It consumes the combiner's output (impressions) and rating-independent outcome events. The combiner has no dependency on it. The click-model scorer is the only path by which outcomes affect ranking.

## Open

None.

## Phases

Each phase is a separate PR. Each phase leaves the Recommender test suite green.

### Phase 0: parity baseline

No production code changes.

- [x] Build fixed fixtures covering: member and visitor subjects, cold start, eligibility on and off, each source alone.
- [x] Record the current output of every recommendation path for those fixtures.
- [x] Store the snapshots in the test suite so later phases are compared against them.

### Phase 1: one ranking path

- [x] Resolve the Open item on predictions and reasons. Decision 8: single-product lookups stay as source methods.
- [x] Remove `ItemRecommender::memberRecommendations()` and `visitorRecommendations()`.
- [x] Replace `fallbackResults()` with the cold-start rule in the combiner. The top-rated fallback now runs as the combiner's `TopRated` source. `TopRatedSource` as a class is Phase 2.
- [x] Route recommendation calls through the combiner. Member and visitor slates use `RecommendationReconciler` with an item-links request.
- [x] Remove the README and test references to the removed methods.

Decisions made in Phase 1:

- Scores. Slates return the fused `rankingScore`. The liked-count score is `evidence[]->rawScore`. Order for warm subjects is unchanged, checked against the Phase 0 baseline.
- Cold start. A subject with fewer than `minHistory` non-negative ratings gets only the `TopRated` source. This rule removes `NewProducts` for cold subjects too. It is the approved wording, not a separate choice.
- `ColdStartPolicy` is removed. `minHistory` moves to `ReconciliationTuning`, which already held `topRatedMinRatings`, so the duplicate knob is gone.
- Parity. Baseline cases for warm subjects keep their item order. Baseline slates for cold subjects change, as expected from the cold-start rule. The baseline was re-recorded for those cases.

### Phase 2: extract sources

- [x] Remove the hidden construction of `UserSimilarity` and `RecommendationEngine` inside the reconciler. Inject them instead. (Step 2a. `Subject` added.)
- [x] 2b-1: `SlopeOneSource::candidateRows()` is the only Slope One candidate query. The reconciler and both prediction paths call it. Removed the unused `memberPredict`, `memberPredictAll`, `visitorPredict`, `visitorPredictAll` and `rankPredictions`. The single-product lookup now reads the pair from the candidate's side. This relies on the link table storing both directions of every pair with opposite `diff_slope`, which both the rebuild and `LinkUpdater` do. The test fixtures that broke were one-sided and are fixed.
- [x] 2b-2: The ItemLinks candidate query is `Internal/Links/ItemLinksSource::candidateRows()`, and the reconciler calls it. `CandidateRoundState` remains the bounded-depth state that sources read from.
- [x] 2b-3: Add the product subject, predict and reasons, and declare each source's supported subjects. Sources reject unsupported kinds with an exception. Single-product lookups are source methods, as decision 8 says: `SlopeOneSource::predict(Subject, product)` and `ItemLinksSource::reasons(Subject, product)`. Member and visitor list predictions are `candidates()` calls, so the separate all-products queries are gone. `ItemRecommender` delegates to the sources.
- [x] 2b-4: Remove `ItemRecommender`. Done after Phase 3. The sources own the eligibility backfill, and they moved to the public `Quellabs\Recommender\Sources` namespace so the item-to-item lookups, predictions and reasons stay public. The README and release notes map each old call.
- [x] 2c-1: ItemLinks and Slope One accept member and visitor subjects in `candidates()`. Ratings load once per request in `Internal/Query/SubjectRatings`: the reconciler builds its sources per request with `RequestSourcesFactory`, which shares one ratings memo among them, and standalone sources load their own, row conversion lives once in `Internal/Query/CandidateRows`, and the member and visitor path is shared in `Internal/Links/LinkCandidates`. The reconciler calls these sources and no longer has its own copies of those queries.
- [x] 2c-2: Candidate sources for every recommendation kind. `TopRatedSource`, `NewProductsSource` (built per request from the ordered list) and `UserSimilaritySource` (members only) serve candidates and scores. `CandidateSource::scores()` replaces the reconciler's audit queries. The reconciler keeps fusion, orchestration and its round loop. The unused `UserSimilarity::memberRecommendations()` is removed.

Reason for the order: each query exists once from the start. Writing product-subject queries first would mean writing them against copies that are later removed.

### Phase 3: filtering in sources

- [x] Make each source filter through the shared eligibility helper, with backfill. `candidates()` takes a limit. With a provider, it deepens until the limit is met. `NewProductsSource` checks its whole unseen list in batches, because its depth counts list positions and a plain deepening loop would stop early.
- [x] Decided: the combiner does not pass its provider to sources. Depth stays a raw rank, because `log_depth_searched` and click-model inputs depend on it. The combiner's batched check on each round's pooled new candidates is its only eligibility step, and it stays.

### Phase 4: shrink the combiner

- [x] Reduce `RecommendationReconciler` to fusion, source orchestration and evidence. Feature building and scoring moved out; the reconciler selects a scorer and keeps the rounds, the source calls and the evidence.
- [x] Move `ReconciliationDiagnostics` out of the normal result path. `ReconciliationRequest::$diagnostics` (default `false`) attaches them to each item. Impression recording rejects ranked items without them. Decided: opt-in on the request, not a second call, because the impression must record the features from the same run that produced the displayed score.
- [x] Add the scorer interface, with rank fusion as the default implementation. `CandidateScorer` returns a `ScoredCandidate`. `RankFusionScorer` is the default. `ClickModelScorer` implements the same interface and is selected when a calibrated model is active.

### Phase 5: extract evaluation and click models

- [ ] Move `ClickModelTrainer`, `ClickModelFitter`, `ActiveModel`, `EvaluationRecorder`, the Evaluation namespace, the evaluation migrations, and the `TrainClickModel`, `ActivateClickModel`, `InitEvaluation` and `PruneEvaluation` Sculpt commands into their own namespace, with the import-boundary test added.
- [ ] Remove calibrated fields from `RecommendationList`. Move `ScoreKind::ClickProbability` with the click-model code.
- [ ] Remove the evaluation hooks from `RecommendationEngine`. `deleteMemberData()` no longer takes a recorder.
- [x] Connect the click-model scorer to the combiner through the scorer interface. Done in Phase 4 with `ClickModelScorer`; the click-model code itself still moves in this phase.

### Phase 6: subject and public surface

- [ ] Collapse the remaining parallel member and visitor methods onto `Subject`.
- [ ] Remove types that have only one caller. Shrink `ReconciliationTuning` to the settings callers actually set.
- [ ] Leave `MemberId` and `ProductId` where they are, in the two-ID methods only.

## Risks

- **Ranking drift.** Fusion, backfill and cold start interact. The Phase 0 snapshots are the check. Any unexplained difference blocks the phase.
- **Hidden callers.** `Integration/ServiceProvider`, the Sculpt commands and Canvas applications reference these classes. Each phase greps for the names it removes before merging.
- **Test churn.** `ItemRecommenderTest` and `ReconciliationTest` change with the code they cover. Phase 0 keeps their intent intact.
