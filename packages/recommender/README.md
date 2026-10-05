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

Upgrading from the legacy `cnt` schema requires a migration and a rebuild. Follow
[`migrations/2026-10-independent-pair-counts.sql`](migrations/2026-10-independent-pair-counts.sql) and back up both
tables first.

## License

MIT
