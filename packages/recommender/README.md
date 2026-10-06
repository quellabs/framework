# quellabs/recommender

Collaborative filtering recommendations for PHP 8.2+, built on the CakePHP 5 database layer. Member ratings
become "people who liked A also liked B" suggestions and rating predictions for unrated items, with no
machine-learning infrastructure required.

- **Item-based collaborative filtering** for members and anonymous visitors
- **Slope One** rating prediction
- **Optional reconciliation** of several sources, filtered by your own catalogue eligibility
- **Opt-in evaluation** of displayed recommendations and a click model

Based on the [Vogoo PHP recommendation engine](http://www.vogoo.net/) (2007–2008) by Stéphane Droux, modernised for PHP
8.2+ and the Quellabs ecosystem.

## Requirements

- PHP 8.2+
- `cakephp/database` ^5.0
- MySQL

## Installation

```bash
composer require quellabs/recommender
```

Then set up the database:

```bash
sculpt recommender:init        # publish config/recommender.php
sculpt recommender:init-db     # create vogoo_ratings and vogoo_links
```

If you already have ratings, rebuild the link tables from them:

```bash
sculpt recommender:rebuild-links
```

## Quick start

```php
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\ItemRecommender;
use Quellabs\Recommender\RecommendationEngine;

$config      = new RecommendationConfig(directLinks: true);
$engine      = new RecommendationEngine($connection, $config);
$recommender = new ItemRecommender($connection, $config);

$engine->setRating(memberId: 1, productId: 101, rating: 0.9);
$engine->setRating(memberId: 2, productId: 101, rating: 0.8);
$engine->setRating(memberId: 2, productId: 102, rating: 0.7);

$recommendations = $recommender->memberRecommendations(memberId: 1, limit: 5);
echo $recommendations[0]->productId; // 102
```

Ratings run from 0.0 to 1.0, and -1.0 marks "not interested".

## API at a glance

| Task | Method | Returns |
|------|--------|---------|
| Rate a product | `RecommendationEngine::setRating()` | `void`, throws on invalid input |
| Recommendations for a member | `ItemRecommender::memberRecommendations()` | `RecommendationResult[]` |
| Recommendations for a visitor | `ItemRecommender::visitorRecommendations()` | `RecommendationResult[]` |
| Predicted rating for one product | `ItemRecommender::memberPrediction()` | `PredictionResult\|null` |
| Predicted ratings for all unrated products | `ItemRecommender::memberPredictions()` | `PredictionResult[]` |
| Displayed slate for a member, filtered by catalogue eligibility | `RecommendationReconciler::memberSlate()` | `RecommendationList` |
| Displayed slate for a visitor, filtered by catalogue eligibility | `RecommendationReconciler::visitorSlate()` | `RecommendationList` |
| Full bounded eligible pool, not cut to `limit` | `RecommendationReconciler::memberCandidatePool()` | `RecommendationList` |

Visitor variants take a `VisitorContext` in place of the member ID.

### Limits

- `limit` is the maximum number of results. `0` means all results. `ItemRecommender` and `Statistics` methods default to `10`.
- With an `EligibilityProvider`, the recommender fetches deeper candidates until `limit` eligible results are found
  or the candidate depth cap (`max_candidate_depth`, default 2000) is reached. It can return fewer results than
  `limit` without an error. Check the count when the list must be full.
- `limit = 0` with an eligibility provider checks every candidate, which costs more on large catalogues.
- `RecommendationReconciler` `limit` is the size of the displayed slate, from 1 to 100. Zero is rejected, because a slate
  is always bounded.

### Missing values and eligibility

- `memberRating()` returns `null` when the member has no rating for the product. `memberAverageRating()` and
  `productAverageRating()` return `null` when there are no ratings. A `0.0` result is a real rating.
- Rating accessors return objects. `memberRating()` returns a `Rating`, `memberRatings()` and `productRatings()` return
  `Rating[]`, and `VisitorContext::ratings()` returns `VisitorRating[]`. Read their properties, such as `->rating`.
- Pass `null` for "no eligibility filter". An `ArrayEligibilityProvider` built from an empty list accepts no candidates,
  so the call returns no results.

### Scores

| Method | `score` meaning |
|--------|-----------------|
| `linkedProducts()` | Liked count of the co-occurrence link |
| `slopeProducts()` | Average Slope One difference, which can be negative |
| `memberRecommendations()`, `visitorRecommendations()` | Sum of liked count times (rating minus threshold). Fallback items use the average rating |
| `memberPredictions()`, `visitorPredictions()` | Use `PredictionResult::$predictedRating` |
| `memberReasons()`, `visitorReasons()` | Liked count of the link to the given product |

## Documentation

The full API, configuration reference, reconciliation and evaluation workflows are documented in the Recommender
page of the Canvas documentation.

## Upgrading

### Breaking API changes

Rename calls as shown. Methods not listed are unchanged.

| Previous | Now |
|----------|-----|
| `ItemRecommender::getLinkedItems()` | `ItemRecommender::linkedProducts()` |
| `ItemRecommender::getSlopeItems()` | `ItemRecommender::slopeProducts()` |
| `ItemRecommender::memberGetRecommendedItems()` | `ItemRecommender::memberRecommendations()` |
| `ItemRecommender::visitorGetRecommendedItems()` | `ItemRecommender::visitorRecommendations()` |
| `ItemRecommender::memberRecommendationsDetailed()` | `ItemRecommender::memberRecommendations()` |
| `ItemRecommender::visitorRecommendationsDetailed()` | `ItemRecommender::visitorRecommendations()` |
| `ItemRecommender::memberGetReasons()` | `ItemRecommender::memberReasons()` |
| `ItemRecommender::visitorGetReasons()` | `ItemRecommender::visitorReasons()` |
| `ItemRecommender::memberPredict()` | `ItemRecommender::memberPrediction()` |
| `ItemRecommender::memberPredictAll()` | `ItemRecommender::memberPredictions()` |
| `ItemRecommender::visitorPredict()` | `ItemRecommender::visitorPrediction()` |
| `ItemRecommender::visitorPredictAll()` | `ItemRecommender::visitorPredictions()` |
| `RecommendationEngine::getRating()` | `RecommendationEngine::memberRating()` |
| `RecommendationEngine::memberPredictDetailed()` and the other `*Detailed()` predictions on the engine | `ItemRecommender::memberPrediction()` and its visitor and list variants |
| `RecommendationEngine::automaticRating($m, $p, true)` | `RecommendationEngine::recordPurchase($m, $p)` |
| `RecommendationEngine::automaticRating($m, $p, false)` | `RecommendationEngine::recordClick($m, $p)` |
| `VisitorContext::getRatings()` | `VisitorContext::ratings()` |
| `VisitorContext::getRatedProductIds()` | `VisitorContext::ratedProductIds()` |
| `VisitorContext::removeRating()` | `VisitorContext::deleteRating()` |
| `RecommendationReconciler::rankCandidatesMember()` | `RecommendationReconciler::memberCandidatePool()` |
| `RecommendationReconciler::rankCandidatesVisitor()` | `RecommendationReconciler::visitorCandidatePool()` |
| `RecommendationReconciler::candidatePoolMember()` | `RecommendationReconciler::memberCandidatePool()` |
| `RecommendationReconciler::candidatePoolVisitor()` | `RecommendationReconciler::visitorCandidatePool()` |
| `RecommendationReconciler::recommendMember()` | `RecommendationReconciler::memberSlate()` |
| `RecommendationReconciler::recommendVisitor()` | `RecommendationReconciler::visitorSlate()` |
| `EvaluationRecorder::deleteMemberHistory()` | `EvaluationRecorder::deleteMemberEvaluations()` |
| `RecommendationList::selectDisplayedIds()` | `RecommendationList::selectDisplayedProducts()` |
| `RecommendationEngine::deleteMember()` for full member erasure | `RecommendationEngine::deleteMemberData()` |

Prediction methods that were renamed also changed return type. Read `->predictedRating` and `->supportCount` on the
new `PredictionResult` objects:

| Previous | Previous return | Now return |
|----------|-----------------|------------|
| `ItemRecommender::memberPredict()` | `float\|null` | `PredictionResult\|null` |
| `ItemRecommender::visitorPredict()` | `float\|null` | `PredictionResult\|null` |
| `ItemRecommender::memberPredictAll()` | `ProductRating[]` | `PredictionResult[]` |
| `ItemRecommender::visitorPredictAll()` | `ProductRating[]` | `PredictionResult[]` |

Argument changes. Positional calls must move their arguments. Named calls only need the renamed names.

| Method | Now |
|--------|-----|
| `ItemRecommender::slopeProducts()` | `(productId, eligibility, limit, minSupport, category)` |
| `ItemRecommender::memberRecommendations()` | `(memberId, eligibility, limit, minHistory, topRatedMinRatings, category)` |
| `ItemRecommender::visitorRecommendations()` | `(visitor, eligibility, limit, minHistory, topRatedMinRatings, category)` |
| `RecommendationEngine::memberNumRatings()` | `(memberId, RatingKind $kind, category)` |
| `RecommendationEngine::memberRatings()` | `(memberId, RatingKind $kind, ?RatingOrder $order, category)` |
| `RecommendationEngine::productRatings()` | `(productId, ?RatingOrder $order, category)` |
| `RecommendationEngine::memberRating()` | `(memberId, productId, RatingKind $kind, category)` |
| `Statistics::topRatedProducts()` | `(limit, topRatedMinRatings, category)` |

The eligibility argument is second, and `category` is last, in every method that takes them. The
`minRatings` argument of `memberRecommendations()` and `visitorRecommendations()` is now `topRatedMinRatings`, and
the `minRatings` argument of `Statistics::topRatedProducts()` has the same new name.

Replace the old boolean flags with the enums:

| Old flags | New |
|-----------|-----|
| `realRatings: true` (default), `notInterested: false` | `RatingKind::Genuine` (default) |
| `realRatings: true, notInterested: true` | `RatingKind::All` |
| `realRatings: false` | `RatingKind::NotInterested` |
| `orderByDate: true, ascending: true` | `RatingOrder::DateAscending` |
| `orderByDate: true, ascending: false` | `RatingOrder::DateDescending` |
| `orderByRating: true, ascending: true` | `RatingOrder::RatingAscending` |
| `orderByRating: true, ascending: false` | `RatingOrder::RatingDescending` |

Return and type changes:

| Member | Previous | Now |
|--------|----------|-----|
| `RecommendationEngine::memberRating()` | `array`, empty when absent | `Rating\|null`, `null` when absent |
| `RecommendationEngine::memberAverageRating()`, `productAverageRating()` | `float`, `0.0` when absent | `float\|null`, `null` when absent |
| `ItemRecommender::memberReasons()`, `visitorReasons()` | `int[]` of product IDs | `RecommendationResult[]`, score is the link's liked count |
| `RecommendationEngine::memberRatings()`, `productRatings()` | Raw database values | `Rating[]`, with `memberId`, `productId`, `rating` as `float` and `timestamp` as `string` |
| `VisitorContext::ratings()` | `array` rows with `product_id`, `rating` and `category` | `VisitorRating[]`, with `productId` and `rating`. Filter by category with the argument |
| `RecommendationResult::$strategy` | `string` such as `'item_links'` | `RecommendationResult::$source`, a `RecommendationSource` enum case |
| `RecommendationResult::$itemId`, `PredictionResult::$itemId`, `ReconciledRecommendation::$itemId` | `$itemId` | `$productId`, same `int` type |
| `RecommendationResult::$contributingItemIds`, `SourceEvidence::$contributingItemIds` | `$contributingItemIds` | `$contributingProductIds`, same `int[]` type |
| `RecommendationList::$scoreKind` | `string` such as `'rank_fusion'` | `ScoreKind` enum case |
| `ReconciledRecommendation::$featureSnapshot`, `$searchedDepths`, `$sourceLogOddsContributions` | Properties on the item | Properties on `$item->diagnostics` (`ReconciliationDiagnostics`) |
| `new RecommendationList(...)` | Public constructor | Private. Use `RecommendationList::ranked()` or `fromDisplayedItems()` |
| `ReconciliationRequest` tuning properties such as `$minSlopeSupport` | Properties on the request | `$request->tuning` (`ReconciliationTuning`). `minSlopeSupport` is now `minSupport` |

Configuration accessors drop the `get` prefix. Booleans keep `is`:

| Previous | Now |
|----------|-----|
| `RecommendationConfig::getCategory()` | `RecommendationConfig::category()` |
| `RecommendationConfig::getThresholdNrCommonRatings()` | `RecommendationConfig::thresholdNrCommonRatings()` |
| `RecommendationConfig::getThresholdMult()` | `RecommendationConfig::thresholdMult()` |
| `RecommendationConfig::getThresholdRating()` | `RecommendationConfig::thresholdRating()` |
| `RecommendationConfig::getCost()` | `RecommendationConfig::cost()` |
| `RecommendationConfig::getMaxCandidateDepth()` | `RecommendationConfig::maxCandidateDepth()` |
| `RecommendationConfig::getMaxBackfillRounds()` | `RecommendationConfig::maxBackfillRounds()` |
| `RecommendationConfig::getMaxEligibilityBatchSize()` | `RecommendationConfig::maxEligibilityBatchSize()` |
| `RecommendationConfig::getNotInterested()` | `RecommendationConfig::NOT_INTERESTED` |

Other changes:

- `ReconciliationRequest::validateKey()` and `ReconciliationRequest::distinctIds()` are no longer public. The
  `ReconciliationRequest::sourceMask()` method is removed; use `RecommendationSource::mask($request->sources)`.
  `RecommendationList::sourceMask()` is unchanged.
- `EligibilityProvider` and `ArrayEligibilityProvider` moved from `Quellabs\Recommender\Reconciliation` to
  `Quellabs\Recommender`. Update the imports.
- The `array $filter` parameter of the `ItemRecommender` recommendation, prediction and link methods is now
  `?EligibilityProvider $eligibility`. Pass `null` where the old call passed `[]`, because an empty array meant
  "no filter". Wrap a non-empty ID list in `ArrayEligibilityProvider`. An `ArrayEligibilityProvider` built from an
  empty list accepts no candidates, so it does not replace an empty filter.
- `RecommendationConfig` no longer accepts `notInterested` or the `not_interested` config key. The sentinel is
  fixed at `RecommendationConfig::NOT_INTERESTED` (-1.0). Remove the key from `config/recommender.php`.
- `RecommendationConfig` takes `maxCandidateDepth`, `maxBackfillRounds` and `maxEligibilityBatchSize` directly.
  `ReconciliationLimits` is removed. The `fromArray()` keys are unchanged.
- `ReconciliationTuning` validates its own values, with the same messages as before.
- `memberRecommendations()` and `visitorRecommendations()` return `RecommendationResult[]`. They previously
  returned bare product IDs.
- `RecommendationEngine::setRating()`, `recordPurchase()`, `recordClick()` and `setNotInterested()` return `void`.
  They throw on invalid input instead of returning `false`.
- `Statistics::mostRatedProducts()` and `topRatedProducts()` return `ProductCount[]` and `ProductAverage[]` instead
  of arrays.
- Implementation classes moved to `Quellabs\Recommender\Internal\Model`, `Internal\Persistence` and
  `Internal\Links`. The Canvas DI provider is now `Quellabs\Recommender\Integration\ServiceProvider`.
  Rebuild Composer discovery metadata after upgrading.

- `ItemRecommender` methods that take `limit` default to `10`, not `0`. Pass `limit: 0` to get all results.
- `ItemRecommender`, `RecommendationEngine` and `RecommendationReconciler` methods throw `InvalidArgumentException` when a
  member or product ID is outside the unsigned 32-bit range. Read methods now validate their IDs too.
- `RecommendationReconciler::visitorSlate()` rejects a request that includes `RecommendationSource::UserSimilarity`,
  because user similarity needs a persisted member. This is a runtime check, not a compile-time one.

### Database: pair counts

Upgrading from the legacy `cnt` schema requires a migration and a rebuild.

1. Pause rating writes and back up `vogoo_ratings` and `vogoo_links` together.
2. Run [`migrations/2026-10-independent-pair-counts.sql`](migrations/2026-10-independent-pair-counts.sql) once. It
   deletes all rows in `vogoo_links`, so recommendations are empty until step 3 completes. It fails if run again.
3. Run `sculpt recommender:rebuild-links`.
4. Verify recommendations, then resume rating writes.

Do not use `recommender:init-db --force` for an upgrade. It drops ratings.

### Database: evaluation tables

Optional. Run `sculpt recommender:init-evaluation-db`, or apply
[`migrations/2026-10-evaluation-tables.sql`](migrations/2026-10-evaluation-tables.sql) directly. It creates five
tables and does not modify existing tables. The SQL uses `CREATE TABLE IF NOT EXISTS`, so running it again is safe.

Applications that handle full member deletion must call `RecommendationEngine::deleteMemberData()` with their
`EvaluationRecorder`. It erases the member's ratings in every category and their evaluation history. Pass `null` when the
evaluation tables are not installed.

## License

MIT
