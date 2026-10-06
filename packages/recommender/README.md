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
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;
use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\MemberId;
use Quellabs\Recommender\ProductId;
use Quellabs\Recommender\RecommendationEngine;

$config      = new RecommendationConfig(directLinks: true);
$engine      = new RecommendationEngine($connection, $config);
$scorers     = new ModelScorerResolver($connection);
$reconciler  = new RecommendationReconciler($config, $sourceFactory, $scorers);

$engine->setRating(new MemberId(1), new ProductId(101), 0.9);
$engine->setRating(new MemberId(2), new ProductId(101), 0.8);
$engine->setRating(new MemberId(2), new ProductId(102), 0.7);

$slate = $reconciler->memberSlate(1, new ReconciliationRequest(
    new ArrayEligibilityProvider([102]), [RecommendationSource::ItemLinks], 5, 'home'));
echo $slate->items[0]->productId; // 102
```

Ratings run from 0.0 to 1.0, and -1.0 marks "not interested".

## API at a glance

| Task | Method | Returns |
|------|--------|---------|
| Rate a product | `RecommendationEngine::setRating()` | `void`, throws on invalid input |
| Predicted rating for one product | `SlopeOneSource::predict()` with `Subject::member()` | `RecommendationResult\|null`, with the rating in `score` |
| Predicted ratings for all unrated products | `SlopeOneSource::candidates()` with `Subject::member()` | `RecommendationResult[]`, with the rating in `score` |
| Displayed slate for a member, filtered by catalogue eligibility | `RecommendationReconciler::memberSlate()` | `RecommendationList` |
| Displayed slate for a visitor, filtered by catalogue eligibility | `RecommendationReconciler::visitorSlate()` | `RecommendationList` |
| Record a visitor purchase or click in session state | `VisitorContext::recordPurchase()`, `recordClick()` | `void` |
| Full bounded eligible pool, not cut to `limit` | `RecommendationReconciler::memberCandidatePool()` | `RecommendationList` |

Visitor variants take a `VisitorContext` in place of the member ID. Visitor reconciliation takes a
`VisitorReconciliationRequest`, built from `VisitorSource` values, in place of `ReconciliationRequest`.

Items carry `$diagnostics` (`ReconciliationDiagnostics`) only when the request sets `diagnostics: true`; otherwise it is `null`.
`EvaluationRecorder::recordImpression()` rejects a ranked list whose items have no diagnostics. Items are scored by
`RankFusionScorer` unless a calibrated click model is active for the request. Both implement `CandidateScorer`.

### Item lookups and predictions

The candidate sources in `Quellabs\Recommender\Sources` answer item-to-item lookups, predictions and reasons. Each
takes a `Subject`, which is a member, a visitor or a product:

| Task | Method |
|------|--------|
| Products linked to a product | `ItemLinksSource::candidates(Subject::product($id), ...)` |
| Products with a Slope One difference to a product | `SlopeOneSource::candidates(Subject::product($id), ...)` |
| Rated products that explain a recommendation | `ItemLinksSource::reasons(Subject::member($id), $product)` or `Subject::visitor($visitor)` |
| One predicted rating | `SlopeOneSource::predict(Subject::member($id), $product)` or `Subject::visitor($visitor)` |
| All predicted ratings | `SlopeOneSource::candidates(Subject::member($id), ...)` or `Subject::visitor($visitor)` |

### Limits

- `limit` is the maximum number of results. `0` means all results. `Statistics` methods default to `10`. Source
  `candidates()` methods have no default, so pass `limit` explicitly. The reconciler reads a member's ratings once per request. Standalone sources load their own ratings.
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
| `ItemLinksSource::candidates()` for a product | Liked count of the co-occurrence link |
| `SlopeOneSource::candidates()` for a product | Average Slope One difference, which can be negative |
| `RecommendationReconciler` slates | `rankingScore` is the fused rank score. Each `evidence` entry's `rawScore` is the source's native score: for item links, the sum of liked count times (rating minus threshold); for top-rated, the average rating |
| `SlopeOneSource::candidates()` and `predict()` for a member or visitor | The predicted rating, in `[0, 1]` |
| `ItemLinksSource::reasons()` | Liked count of the link to the given product |

## Documentation

The full API, configuration reference, reconciliation and evaluation workflows are documented in the Recommender
page of the Canvas documentation.

## Upgrading

### Breaking API changes

Rename calls as shown. Methods not listed are unchanged.

| Previous | Now |
|----------|-----|
| `ItemRecommender::getLinkedItems()` | `ItemLinksSource::candidates()` with `Subject::product()` |
| `ItemRecommender::getSlopeItems()` | `SlopeOneSource::candidates()` with `Subject::product()` |
| `ItemRecommender::memberGetRecommendedItems()` | `RecommendationReconciler::memberSlate()` with an item-links request |
| `ItemRecommender::visitorGetRecommendedItems()` | `RecommendationReconciler::visitorSlate()` with an item-links request |
| `ItemRecommender::memberRecommendationsDetailed()` | `RecommendationReconciler::memberSlate()` with an item-links request |
| `ItemRecommender::visitorRecommendationsDetailed()` | `RecommendationReconciler::visitorSlate()` with an item-links request |
| `ItemRecommender::memberGetReasons()` | `ItemLinksSource::reasons()` with `Subject::member()` |
| `ItemRecommender::visitorGetReasons()` | `ItemLinksSource::reasons()` with `Subject::visitor()` |
| `ItemRecommender::memberPredict()` | `SlopeOneSource::predict()` with `Subject::member()` |
| `ItemRecommender::memberPredictAll()` | `SlopeOneSource::candidates()` with `Subject::member()` |
| `ItemRecommender::visitorPredict()` | `SlopeOneSource::predict()` with `Subject::visitor()` |
| `ItemRecommender::visitorPredictAll()` | `SlopeOneSource::candidates()` with `Subject::visitor()` |
| `RecommendationEngine::getRating()` | `RecommendationEngine::memberRating()` |
| `RecommendationEngine::memberPredictDetailed()` and the other `*Detailed()` predictions on the engine | `SlopeOneSource::predict()` and `candidates()` |
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

Prediction methods that were renamed also changed return type. Read `->score` for the predicted rating and
`->supportCount` on the new `RecommendationResult` objects:

| Previous | Previous return | Now return |
|----------|-----------------|------------|
| `ItemRecommender::memberPredict()` | `float\|null` | `SlopeOneSource::predict()`, returning `RecommendationResult\|null` |
| `ItemRecommender::visitorPredict()` | `float\|null` | `SlopeOneSource::predict()`, returning `RecommendationResult\|null` |
| `ItemRecommender::memberPredictAll()` | `ProductRating[]` | `SlopeOneSource::candidates()`, returning `RecommendationResult[]` |
| `ItemRecommender::visitorPredictAll()` | `ProductRating[]` | `SlopeOneSource::candidates()`, returning `RecommendationResult[]` |

Argument changes. Positional calls must move their arguments. Named calls only need the renamed names.

| Method | Now |
|--------|-----|
| `ItemRecommender::slopeProducts()` | `SlopeOneSource::candidates(Subject $subject, eligibility, int $limit, SourceSettings $settings, category)` |
| `RecommendationEngine::memberNumRatings()` | `(int $member, RatingKind $kind, category)` |
| `RecommendationEngine::memberRatings()` | `(int $member, RatingKind $kind, ?RatingOrder $order, category)` |
| `RecommendationEngine::productRatings()` | `(int $product, ?RatingOrder $order, category)` |
| `RecommendationEngine::memberRating()` | `(MemberId $member, ProductId $product, RatingKind $kind, category)` |
| `Statistics::topRatedProducts()` | `(limit, int $topRatedMinRatings, category)` |

The eligibility argument is second, and `category` is last, in every method that takes them. `minHistory` and `topRatedMinRatings` are `ReconciliationTuning` arguments. The `minRatings` argument of `Statistics::topRatedProducts()` is now `topRatedMinRatings`.

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
| `ItemRecommender::memberReasons()`, `visitorReasons()` | `int[]` of product IDs | `ItemLinksSource::reasons()`, returning `RecommendationResult[]`; score is the link's liked count |
| `RecommendationEngine::memberRatings()`, `productRatings()` | Raw database values | `Rating[]`, with `memberId`, `productId`, `rating` as `float` and `timestamp` as `string` |
| `VisitorContext::ratings()` | `array` rows with `product_id`, `rating` and `category` | `VisitorRating[]`, with `productId` and `rating`. Filter by category with the argument |
| `RecommendationResult::$strategy` | `string` such as `'item_links'` | `RecommendationResult::$source`, a `RecommendationSource` enum case |
| `RecommendationResult::$itemId`, `ReconciledRecommendation::$itemId` | `$itemId` | `$productId`, same `int` type |
| `RecommendationResult::$contributingItemIds`, `SourceEvidence::$contributingItemIds` | `$contributingItemIds` | `$contributingProductIds`, same `int[]` type |
| `RecommendationList::$scoreKind` | `string` such as `'rank_fusion'` | `ScoreKind` enum, `Direct` or `Ranked`. The scorer is `RecommendationList::$scorerId`, `null` for rank fusion |
| `ReconciledRecommendation::$featureSnapshot`, `$searchedDepths`, `$sourceLogOddsContributions` | Properties on the item | Properties on `$item->diagnostics` (`ReconciliationDiagnostics`), which is `null` unless the request sets `diagnostics: true` |
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
- The `array $filter` parameter of the former `ItemRecommender` prediction and link methods, now on the sources, is
  `?EligibilityProvider $eligibility`. Pass `null` where the old call passed `[]`, because an empty array meant
  "no filter". Wrap a non-empty ID list in `ArrayEligibilityProvider`. An `ArrayEligibilityProvider` built from an
  empty list accepts no candidates, so it does not replace an empty filter.
- `RecommendationConfig` no longer accepts `notInterested` or the `not_interested` config key. The sentinel is
  fixed at `RecommendationConfig::NOT_INTERESTED` (-1.0). Remove the key from `config/recommender.php`.
- `RecommendationConfig` takes `maxCandidateDepth`, `maxBackfillRounds` and `maxEligibilityBatchSize` directly.
  `ReconciliationLimits` is removed. The `fromArray()` keys are unchanged.
- `ReconciliationTuning` validates its own values, with the same messages as before.
- `ItemRecommender::memberRecommendations()` and `visitorRecommendations()` are removed. Use
  `RecommendationReconciler::memberSlate()` or `visitorSlate()` with a `RecommendationSource::ItemLinks` or
  `VisitorSource::ItemLinks` request. Slates return `ReconciledRecommendation` items: `rankingScore` is the fused score,
  and `evidence[]->rawScore` is the liked-count score. Slate `limit` is 1 to 100, and a provider is required; pass one
  that accepts every candidate where the old call passed `null`.
- `RecommendationEngine::setRating()`, `recordPurchase()`, `recordClick()` and `setNotInterested()` return `void`.
  They throw on invalid input instead of returning `false`.
- `Statistics::mostRatedProducts()` and `topRatedProducts()` return `ProductCount[]` and `ProductAverage[]` instead
  of arrays.
- The candidate sources moved from `Internal` to `Quellabs\Recommender\Sources`, and `ItemRecommender` is removed.
- Implementation classes moved to `Quellabs\Recommender\Internal\Model`, `Internal\Persistence` and
  `Internal\Links`. The Canvas DI provider is now `Quellabs\Recommender\Integration\ServiceProvider`.
  Rebuild Composer discovery metadata after upgrading.

- The source `candidates()` methods take `limit` with no default. Pass `limit: 0` to get all results.
- `RecommendationEngine`, `RecommendationReconciler` and the candidate sources throw `InvalidArgumentException` when a
  member or product ID is outside the unsigned 32-bit range. Read methods now validate their IDs too.
- `RecommendationReconciler::visitorSlate()` and `visitorCandidatePool()` take a `VisitorReconciliationRequest`, built
  from `VisitorSource` values. `VisitorSource` has no user-similarity case, so a visitor request cannot include it.
  Passing a `ReconciliationRequest` is a type error.
- A subject with fewer than `minHistory` non-negative ratings gets only the top-rated source. Other requested
  sources are not used for it, including new products. `minHistory` defaults to 1 and is a `ReconciliationTuning`
  argument. The source settings `minSupport`, `topRatedMinRatings`, `minNeighbourSimilarity` and `maxNeighbours` are a `SourceSettings`
  object, passed as `ReconciliationTuning::$sources`. `ColdStartPolicy` is removed.
- Thresholds are plain `int` values, checked when the call runs. The `minSupport` argument of the prediction and slope
  methods is `int $minSupport`, and `Statistics::topRatedProducts()` takes `int $topRatedMinRatings`. `ReconciliationTuning` takes ints too. Values below their minimum throw `InvalidArgumentException`.
  `Statistics::topRatedProducts()` previously clamped values below 1 to 1, and now rejects them.
- Member and product IDs are `int` parameters, checked to the unsigned 32-bit range. The exception is methods that take
  both a member and a product: `RecommendationEngine::memberRating()`, `setRating()`, `recordPurchase()`,
  `recordClick()`, `setNotInterested()` and `deleteRating()`. These take `MemberId` and `ProductId` so the two cannot be
  swapped. Wrap IDs as `new MemberId(1)` and `new ProductId(101)` in those calls. Named arguments are `member:` and
  `product:`. Results, value objects and ID arrays keep `int`. The source methods take a `Subject` for the member or
  visitor and an `int` product.

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

`RecommendationEngine::deleteMemberData()` erases the member's ratings in every category. Applications that installed
the evaluation tables must also call `EvaluationRecorder::deleteMemberEvaluations()` for the same member.

## License

MIT
