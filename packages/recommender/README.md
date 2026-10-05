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
echo $recommendations[0]->itemId; // 102
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
| Ranking filtered by catalogue eligibility | `RecommendationReconciler::recommendMember()` | `RecommendationList` |

Visitor variants take a `VisitorContext` in place of the member ID.

## Documentation

The full API, configuration reference, reconciliation and evaluation workflows are documented in the Recommender
page of the Canvas documentation.

## Upgrading

### Breaking API changes

Rename calls as shown. Methods not listed are unchanged.

| Previous | Now |
|----------|-----|
| `ItemRecommender::getLinkedItems()` | `ItemRecommender::linkedItems()` |
| `ItemRecommender::getSlopeItems()` | `ItemRecommender::slopeItems()` |
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
| `VisitorContext::getRatings()` | `VisitorContext::ratings()` |
| `VisitorContext::getRatedProductIds()` | `VisitorContext::ratedProductIds()` |

Other changes:

- The `array $filter` parameter of the `ItemRecommender` recommendation, prediction and link methods is now
  `?EligibilityProvider $eligibility`. Wrap an ID list in `ArrayEligibilityProvider` to keep the old behaviour.
  The parameter is in the second position, so positional calls must be updated.
- `memberRecommendations()` and `visitorRecommendations()` return `RecommendationResult[]`. They previously
  returned bare product IDs.
- `RecommendationEngine::setRating()`, `automaticRating()` and `setNotInterested()` return `void`. They throw on
  invalid input instead of returning `false`.
- `Statistics::mostRatedProducts()` and `topRatedProducts()` return `ProductCount[]` and `ProductAverage[]` instead
  of arrays.
- Implementation classes moved to `Quellabs\Recommender\Internal\Model`, `Internal\Persistence` and
  `Internal\Links`. The Canvas DI provider is now `Quellabs\Recommender\Integration\ServiceProvider`.
  Rebuild Composer discovery metadata after upgrading.

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

Applications that handle full member deletion must call `EvaluationRecorder::deleteMemberHistory()` in addition to
the existing rating deletion.

## License

MIT
