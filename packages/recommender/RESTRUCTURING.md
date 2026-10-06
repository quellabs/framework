# Recommender restructuring plan

The package has not been released. Breaking changes to the public API are allowed, and removed methods are removed without a deprecation period.

## Decisions

1. **Sources are standalone.** Each source takes an optional `EligibilityProvider` and can be called on its own. The combiner is not required to use any source.
2. **Click models and evaluation live in a separate package.** They have their own tables, Sculpt commands and activation lifecycle. The combiner does not depend on them.
3. **Backfill stays.** The depth-and-backfill loop lives in the shared eligibility helper, and every source uses it.
4. **One subject type.** A small `Subject` abstraction replaces the parallel member and visitor methods.
5. **Filtering happens in sources, including under the combiner.** The combiner passes its provider to each source and does not filter. Backfill needs the source's depth loop, so the combiner cannot filter on a source's behalf.

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
- **Item and rating engines.** `ItemRecommender` keeps the item-to-item lookups (`linkedProducts()`, `slopeProducts()`) as entry points for the item sources. It contains no ranking of its own. `RecommendationEngine` keeps rating storage.
- **Evaluation and click models.** Separate package. It consumes the combiner's output (impressions) and rating-independent outcome events. The combiner has no dependency on it. The click-model scorer is the only path by which outcomes affect ranking.

## Open

- **Predictions and reasons.** `ItemRecommender` exposes `memberPredictions()` and `visitorPredictions()` (predicted ratings, `PredictionResult`) and `memberReasons()` and `visitorReasons()` (the "why this" lists). They do not fit the combiner's ranked-candidate output. Decide whether predictions become a separate entry point over the Slope One source, and whether reasons become a method on the item sources, before Phase 1 removes the ranking methods.

## Phases

Each phase is a separate PR. Each phase leaves the Recommender test suite green.

### Phase 0: parity baseline

No production code changes.

- [x] Build fixed fixtures covering: member and visitor subjects, cold start, eligibility on and off, each source alone.
- [x] Record the current output of every recommendation path for those fixtures.
- [x] Store the snapshots in the test suite so later phases are compared against them.

### Phase 1: one ranking path

- [ ] Resolve the Open item on predictions and reasons.
- [ ] Remove `ItemRecommender::memberRecommendations()` and `visitorRecommendations()`.
- [ ] Move `fallbackResults()` into `TopRatedSource`.
- [ ] Route recommendation calls through the combiner.
- [ ] Remove the README and test references to the removed methods.

### Phase 2: extract sources

- [ ] Move each candidate-generation method out of `RecommendationReconciler` into its source class.
- [ ] Remove the hidden construction of `UserSimilarity` and `RecommendationEngine` inside the reconciler. Inject them instead.
- [ ] Keep `CandidateRoundState` as the bounded-depth state that sources read from.
- [ ] Declare the supported subjects on each source.

### Phase 3: filtering in sources

- [ ] Make each source filter through the shared eligibility helper, with backfill.
- [ ] Make the combiner pass its provider to every source.
- [ ] Remove the per-method `withEligibility()` calls from `ItemRecommender` and the batching logic from the reconciler.

### Phase 4: shrink the combiner

- [ ] Reduce `RecommendationReconciler` to fusion, source orchestration and evidence.
- [ ] Move `ReconciliationDiagnostics` out of the normal result path.
- [ ] Add the scorer interface, with rank fusion as the default implementation.

### Phase 5: extract evaluation and click models

- [ ] Move `ClickModelTrainer`, `ClickModelFitter`, `ActiveModel`, `EvaluationRecorder`, the Evaluation namespace, the evaluation migrations, and the `TrainClickModel`, `ActivateClickModel`, `InitEvaluation` and `PruneEvaluation` Sculpt commands into the separate package.
- [ ] Remove calibrated fields from `RecommendationList`. Move `ScoreKind::ClickProbability` with the click-model code.
- [ ] Remove the evaluation hooks from `RecommendationEngine`. `deleteMemberData()` no longer takes a recorder.
- [ ] Connect the click-model scorer to the combiner through the scorer interface.

### Phase 6: subject and public surface

- [ ] Introduce `Subject` for member and visitor, and collapse the parallel methods onto it.
- [ ] Remove types that have only one caller. Shrink `ReconciliationTuning` to the settings callers actually set.
- [ ] Leave `MemberId` and `ProductId` where they are, in the two-ID methods only.

## Risks

- **Ranking drift.** Fusion, backfill and cold start interact. The Phase 0 snapshots are the check. Any unexplained difference blocks the phase.
- **Hidden callers.** `Integration/ServiceProvider`, the Sculpt commands and Canvas applications reference these classes. Each phase greps for the names it removes before merging.
- **Test churn.** `ItemRecommenderTest` and `ReconciliationTest` change with the code they cover. Phase 0 keeps their intent intact.
