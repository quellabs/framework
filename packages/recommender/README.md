# quellabs/recommender

Collaborative filtering recommendations for PHP 8.3+, built on the CakePHP 5 database layer. Member ratings
become "people who liked A also liked B" suggestions and rating predictions for unrated items, with no
machine-learning infrastructure required.

- **Item-based collaborative filtering** for members and anonymous visitors
- **Slope One** rating prediction
- **Optional reconciliation** of several sources, filtered by your own catalogue eligibility
- **Opt-in evaluation** of displayed recommendations and a click model

Based on the [Vogoo PHP recommendation engine](http://www.vogoo.net/) (2007–2008) by Stéphane Droux, modernised for PHP
8.3+ and the Quellabs ecosystem.

## Requirements

- PHP 8.3+
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

### Keeping derived pairs fresh

`direct_links` and `direct_slope` each control incremental maintenance of one derived measure, independently:

| Write path                                   | `vogoo_links` (liked count)              | Slope One pair stats                      |
|-----------------------------------------------|-------------------------------------------|--------------------------------------------|
| `RecommendationEngine::setRating()`/`deleteRating()` | Updated only when `direct_links` is `true` (default `false`) | Updated only when `direct_slope` is `true` (default `true`) |
| A bulk import written directly to `vogoo_ratings` (bypassing the engine) | Never updated                             | Never updated                              |
| `sculpt recommender:rebuild-links`           | Always rebuilt from `vogoo_ratings`       | Always rebuilt from `vogoo_ratings`        |

A flag being off does not clear existing rows; `ItemLinksSource` and `SlopeOneSource` keep reading whatever is
there, and it goes stale until the next rebuild. With the defaults, a fresh rating updates Slope One immediately
but leaves `vogoo_links` stale until you run `recommender:rebuild-links`.

## Quick start

```php
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;
use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\Evaluation\ModelScorerResolver;
use Quellabs\Recommender\MemberId;
use Quellabs\Recommender\ProductId;
use Quellabs\Recommender\RecommendationEngine;
use Quellabs\Recommender\Subject;

// $connection is the application's Cake\Database\Connection.
$config     = new RecommendationConfig(directLinks: true);
$engine     = new RecommendationEngine($connection, $config);
$reconciler = RecommendationReconciler::fromConnection($connection, $config, new ModelScorerResolver($connection));

$engine->setRating(MemberId::of(1), ProductId::of(101), 0.9);
$engine->setRating(MemberId::of(2), ProductId::of(101), 0.8);
$engine->setRating(MemberId::of(2), ProductId::of(102), 0.7);

$slate = $reconciler->slate(Subject::member(1), new ReconciliationRequest(
    new ArrayEligibilityProvider([102]), [RecommendationSource::ItemLinks], 5, 'home'));
echo $slate->items[0]->productId; // 102
```

`RecommendationReconciler::fromConnection()` is the standalone construction path, for applications without a
DI container. A Canvas application should instead get `RecommendationReconciler` through discovery, which wires
the same dependencies from `config/recommender.php`.

Ratings run from 0.0 to 1.0, and -1.0 marks "not interested".

### Cold start

Below `ReconciliationTuning::$minHistory` genuine ratings (default 1), a subject is "cold": personal sources
(`ItemLinks`, `SlopeOne`, `UserSimilarity`) are dropped because they have no history to read, and only the
non-personal sources the request already asked for (`TopRated`, `NewProducts`) are kept. Requesting only personal
sources for a cold subject falls back to trying `TopRated` alone; `TopRated` can still return no products, for
example when none meet `topRatedMinRatings` or eligibility rejects them all, so handle an empty list regardless.
Set `ReconciliationTuning::$coldStartSources` to a fixed source list to override this per request instead.

## API at a glance

| Task                                                                       | Method                                                                                       | Returns                                                                  |
|----------------------------------------------------------------------------|----------------------------------------------------------------------------------------------|--------------------------------------------------------------------------|
| Rate a product                                                             | `RecommendationEngine::setRating()`                                                          | `void`, throws on invalid input                                          |
| Average rating for a product                                               | `RecommendationEngine::productAverageRating()`                                               | `float\|null`, averages genuine ratings only, excluding "not interested" |
| Number of ratings for a product                                            | `RecommendationEngine::productNumRatings()`                                                  | `int`, counts genuine ratings only                                       |
| Predicted rating for one product                                           | `SlopeOneSource::predict()` with `Subject::member()`                                         | `RecommendationResult\|null`, with the rating in `score`                 |
| Predicted ratings for all unrated products                                 | `SlopeOneSource::candidates()` with `Subject::member()`                                      | `RecommendationResult[]`, with the rating in `score`                     |
| Displayed slate for a member or visitor, filtered by catalogue eligibility | `RecommendationReconciler::slate()` with `Subject::member()` or `Subject::visitor()`         | `RecommendationList`                                                     |
| Record a visitor purchase or click in session state                        | `VisitorContext::recordPurchase()`, `recordClick()`                                          | `void`                                                                   |
| Ranked, eligible pool found by the bounded search, not cut to `limit`      | `RecommendationReconciler::candidatePool()` with `Subject::member()` or `Subject::visitor()` | `RecommendationList`                                                     |

Visitor requests use the same `ReconciliationRequest`. A visitor request that includes
`RecommendationSource::UserSimilarity` throws `InvalidArgumentException`, because user similarity needs a persisted
member.

Items carry `$diagnostics` (`ReconciliationDiagnostics`) only when the request sets `diagnostics: true`; otherwise it is
`null`.
`EvaluationRecorder::recordImpression()` rejects a ranked list whose items have no diagnostics. Items are scored by
`RankFusionScorer` unless a calibrated click model is active for the request. Both implement `CandidateScorer`.

### Item lookups and predictions

The candidate sources in `Quellabs\Recommender\Sources` answer item-to-item lookups, predictions and reasons. Each
takes a `Subject`, which is a member, a visitor or a product:

| Task                                              | Method                                                                                     |
|---------------------------------------------------|--------------------------------------------------------------------------------------------|
| Products linked to a product                      | `ItemLinksSource::candidates(Subject::product($id), ...)`                                  |
| Products with a Slope One difference to a product | `SlopeOneSource::candidates(Subject::product($id), ...)`                                   |
| Rated products that explain a recommendation      | `ItemLinksSource::reasons(Subject::member($id), $product)` or `Subject::visitor($visitor)` |
| One predicted rating                              | `SlopeOneSource::predict(Subject::member($id), $product)` or `Subject::visitor($visitor)`  |
| All predicted ratings                             | `SlopeOneSource::candidates(Subject::member($id), ...)` or `Subject::visitor($visitor)`    |

### Limits

- `limit` is the maximum number of results. `0` means all results. `Statistics` methods default to `10`. Source
  `candidates()` methods have no default, so pass `limit` explicitly. The reconciler reads a member's ratings once per
  request. Standalone sources load their own ratings.
- With an `EligibilityProvider`, the recommender fetches deeper candidates until `limit` eligible results are found
  or the candidate depth cap (`max_candidate_depth`, default 2000) is reached. It can return fewer results than
  `limit` without an error. Check the count when the list must be full.
- `limit = 0` with an eligibility provider checks every candidate, which costs more on large catalogues.
- `RecommendationReconciler` `limit` is the size of the displayed slate, from 1 to 100. Zero is rejected, because a
  slate is always bounded. It also doubles as the backfill stopping target for `candidatePool()`: the bounded search
  widens per-source depth across rounds only until `limit` eligible candidates are found or the depth/round caps are
  reached, so a higher `max_candidate_depth` alone does not make `candidatePool()` return every eligible candidate in
  the catalogue — raise `limit` too if you need a larger pool.

### Missing values and eligibility

- `memberRating()` returns `null` when the member has no rating for the product. `memberAverageRating()` and
  `productAverageRating()` return `null` when there are no ratings. A `0.0` result is a real rating.
- `setRating()` overwrites an existing rating for the same member, product and category. Read the previous value with
  `memberRating()` first only when the new value depends on it. `deleteRating()` removes the rating.
- Rating accessors return objects. `memberRating()` returns a `Rating`, `memberRatings()` and `productRatings()` return
  `Rating[]`, and `VisitorContext::ratings()` returns `VisitorRating[]`. Read their properties, such as `->rating`.
- Pass `null` for "no eligibility filter". An `ArrayEligibilityProvider` built from an empty list accepts no candidates,
  so the call returns no results.

### Scores

| Method                                                                 | `score` meaning                                                                                                                                                                                                   |
|------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ItemLinksSource::candidates()` for a product                          | Liked count of the co-occurrence link                                                                                                                                                                             |
| `SlopeOneSource::candidates()` for a product                           | Average Slope One difference, which can be negative                                                                                                                                                               |
| `RecommendationReconciler` slates                                      | `rankingScore`; see below. Each `evidence` entry's `rawScore` is the source's native score: for item links, the sum of liked count times (rating minus threshold); for top-rated, the average rating |
| `SlopeOneSource::candidates()` and `predict()` for a member or visitor | The predicted rating, in `[0, 1]`                                                                                                                                                                                 |
| `ItemLinksSource::reasons()`                                           | Liked count of the link to the given product                                                                                                                                                                      |

A reconciled list's `rankingScore` is on one of two scales, told apart by `RecommendationList::$scorerId`:

- `scorerId === null`: reciprocal-rank fusion score (no active click model).
- `scorerId !== null`: the active model's click probability at the fixed reference position (position one), used
  to rank every candidate the same way regardless of where it ends up displayed.

The model's probability at an item's actual displayed position is a separate value, recorded only when
`EvaluationRecorder::recordImpression()` stores the impression — it is not `rankingScore` and is not returned by
reconciliation. Scores from rank fusion and from a model, or from two different models, are not on a shared scale
and must not be compared against each other.

### Identifiers

Two calling conventions coexist. Both validate their range; neither is safer than the other.

| Takes `MemberId`/`ProductId`         | Takes plain `int`                                                                  |
|---------------------------------------|--------------------------------------------------------------------------------------|
| `RecommendationEngine::setRating()`   | `RecommendationEngine::memberRatings()`, `productRatings()`, `deleteMember()`, `deleteProduct()`, `productNumRatings()`, `productAverageRating()` |
| `RecommendationEngine::memberRating()`| `Subject::member()`, `Subject::product()`, `VisitorContext` methods                |

### Evaluation

Evaluation is opt-in and separate from serving a slate: generating a slate never records anything by itself.

```bash
sculpt recommender:init-evaluation-db   # create the evaluation tables, once
```

```php
use Quellabs\Recommender\Evaluation\EvaluationRecorder;
use Quellabs\Recommender\Evaluation\OutcomeType;
use DateTimeImmutable;

$recorder = new EvaluationRecorder($connection);

// Continuing the Quick start example: same $reconciler and ratings. diagnostics: true is required to record it.
$slate = $reconciler->slate(Subject::member(1), new ReconciliationRequest(
    new ArrayEligibilityProvider([102]), [RecommendationSource::ItemLinks], 5, 'home', diagnostics: true));

// Record the subset and order actually displayed, not the full pool; here $slate->items is just [102].
$shown = $slate->selectDisplayedProducts(array_map(fn($item) => $item->productId, $slate->items));
$impressionId = $recorder->recordImpression($shown, member: 1);

// Record outcomes later. $orderLineId is your own persisted event/order ID, so a retry doesn't double-count.
$recorder->recordOutcome($impressionId, 102, $orderLineId, OutcomeType::Click, new DateTimeImmutable());
```

Outcomes and implicit ratings are separate calls: recording a click here does not call
`RecommendationEngine::recordClick()`, and vice versa. `EvaluationReport::summary()` reports rates over shown
items under your display policy, not over the whole catalogue.

Training and activating a click model is optional and separate from recording impressions and outcomes:

```bash
sculpt recommender:train-click-model --category=1 --placement=home --sources=item_links \
  --from=2026-09-01T00:00:00Z --to=2026-10-01T00:00:00Z --as-of=2026-10-08T00:00:00Z --click-window-seconds=86400
sculpt recommender:activate-click-model --id=<HEX ID PRINTED ABOVE>
```

Once a model is active for a partition, reconciliation automatically scores matching requests with it instead of
rank fusion, and `rankingScore` switches to that model's position-one reference probability (see Scores above).

## Documentation

The full API, configuration reference, reconciliation and evaluation workflows are documented in the Recommender
page of the Canvas documentation.

## License

MIT
