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
use Quellabs\Recommender\Evaluation\ModelScorerResolver;
use Quellabs\Recommender\Internal\Reconciliation\RequestSourcesFactory;
use Quellabs\Recommender\Internal\UserSimilarity;
use Quellabs\Recommender\MemberId;
use Quellabs\Recommender\ProductId;
use Quellabs\Recommender\RecommendationEngine;
use Quellabs\Recommender\Subject;

// $connection is the application's Cake\Database\Connection.
$config        = new RecommendationConfig(directLinks: true);
$engine        = new RecommendationEngine($connection, $config);
$similarity    = new UserSimilarity($connection, $config, $engine);
$sourceFactory = new RequestSourcesFactory($connection, $config, $similarity);
$scorers       = new ModelScorerResolver($connection);
$reconciler    = new RecommendationReconciler($config, $sourceFactory, $scorers);

$engine->setRating(MemberId::of(1), ProductId::of(101), 0.9);
$engine->setRating(MemberId::of(2), ProductId::of(101), 0.8);
$engine->setRating(MemberId::of(2), ProductId::of(102), 0.7);

$slate = $reconciler->slate(Subject::member(1), new ReconciliationRequest(
    new ArrayEligibilityProvider([102]), [RecommendationSource::ItemLinks], 5, 'home'));
echo $slate->items[0]->productId; // 102
```

Ratings run from 0.0 to 1.0, and -1.0 marks "not interested".

## API at a glance

| Task | Method | Returns |
|------|--------|---------|
| Rate a product | `RecommendationEngine::setRating()` | `void`, throws on invalid input |
| Average rating for a product | `RecommendationEngine::productAverageRating()` | `float\|null`, averages genuine ratings only, excluding "not interested" |
| Number of ratings for a product | `RecommendationEngine::productNumRatings()` | `int`, counts genuine ratings only |
| Predicted rating for one product | `SlopeOneSource::predict()` with `Subject::member()` | `RecommendationResult\|null`, with the rating in `score` |
| Predicted ratings for all unrated products | `SlopeOneSource::candidates()` with `Subject::member()` | `RecommendationResult[]`, with the rating in `score` |
| Displayed slate for a member or visitor, filtered by catalogue eligibility | `RecommendationReconciler::slate()` with `Subject::member()` or `Subject::visitor()` | `RecommendationList` |
| Record a visitor purchase or click in session state | `VisitorContext::recordPurchase()`, `recordClick()` | `void` |
| Full bounded eligible pool, not cut to `limit` | `RecommendationReconciler::candidatePool()` with `Subject::member()` or `Subject::visitor()` | `RecommendationList` |

Visitor requests use the same `ReconciliationRequest`. A visitor request that includes
`RecommendationSource::UserSimilarity` throws `InvalidArgumentException`, because user similarity needs a persisted member.

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

## License

MIT
