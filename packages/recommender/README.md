# quellabs/recommender

Collaborative filtering recommendation engine for PHP 8.2+, built on the CakePHP 5 database layer. Implements two
complementary algorithms:

- **Item-based collaborative filtering** — "people who liked A also liked B"
- **Slope One** — lightweight weighted rating prediction for unrated items

Based on the [Vogoo PHP recommendation engine](http://www.vogoo.net/) (2007–2008) by Stéphane Droux, modernised for PHP
8.2+ and the Quellabs ecosystem.

---

## Requirements

- PHP 8.2+
- `cakephp/database` ^5.0
- MySQL (incremental updates use `INSERT ... ON DUPLICATE KEY UPDATE`)

## Installation

```bash
composer require quellabs/recommender
```

## Setup

### 1. Publish the configuration file

```bash
sculpt recommender:init
```

This copies `config/recommender.php` to your project root. Edit it to tune the engine constants. Database credentials
are read from `config/database.php`, which is shared with other Canvas packages.

### 2. Create the database tables

```bash
sculpt recommender:init-db
```

Creates `vogoo_ratings` and `vogoo_links`. Use `--force` to drop and recreate existing tables.
`--force` deletes ratings and is not a migration procedure.

### 3. Populate the link table

If you have existing ratings, rebuild the link table from scratch:

```bash
sculpt recommender:rebuild-links
```

To rebuild a single category only:

```bash
sculpt recommender:rebuild-links --category=2
```

Pause rating writes while rebuilding. The command computes each category in staging storage,
then replaces its rows in one transaction. It rebuilds both measures regardless of the
`direct_links` and `direct_slope` settings, and clears stale rows for empty categories.

### Upgrade an existing installation

Back up both `vogoo_ratings` and `vogoo_links`, pause rating writes, run
[`migrations/2026-10-independent-pair-counts.sql`](migrations/2026-10-independent-pair-counts.sql),
then run `sculpt recommender:rebuild-links` before resuming writes. The old
`cnt` column mixes liked and Slope One contributions, so its values cannot be
assigned to either new count. The migration preserves `vogoo_ratings`; the rebuild
reconstructs every derived row from those ratings. If an upgrade fails, restore
both backed-up tables together, then run the previous package version.

## Database schema

### `vogoo_ratings`

Stores member/product ratings.

| Column       | Type         | Description                           |
|--------------|--------------|---------------------------------------|
| `member_id`  | INT UNSIGNED | Your application's user ID            |
| `product_id` | INT UNSIGNED | Your application's product/item ID    |
| `category`   | INT UNSIGNED | Category grouping (default: 1)        |
| `rating`     | FLOAT        | 0.0–1.0, or -1.0 for "not interested" |
| `ts`         | DATETIME     | Last updated timestamp                |

### `vogoo_links`

Stores pre-computed item co-occurrence counts and Slope One diff values. Populated by the rebuild command or maintained
incrementally.

| Column       | Type         | Description                               |
|--------------|--------------|-------------------------------------------|
| `item_id1`   | INT UNSIGNED | First item in the pair                    |
| `item_id2`   | INT UNSIGNED | Second item in the pair                   |
| `category`   | INT UNSIGNED | Category grouping                         |
| `liked_count` | INT UNSIGNED | Members who liked both items |
| `slope_count` | INT UNSIGNED | Members who genuinely rated both items |
| `diff_slope` | FLOAT | Sum of rating(item_id2) minus rating(item_id1) over slope contributors |

A directed pair row exists while either count is positive. `Statistics::numLinks()`
counts rows of either kind. Each incremental option updates only its own measure.

## Configuration

All options with their defaults:

```php
// config/recommender.php
return [
    // Default category used when no category is passed to engine methods
    'category' => 1,

    // Minimum number of common ratings before similarity is considered reliable
    'threshold_nr_common_ratings' => 30,

    // Multiplier used in the similarity confidence calculation
    'threshold_mult' => 2,

    // Minimum rating for an item to count as "liked" in link calculations
    'threshold_rating' => 0.66,

    // Cost factor used in the member similarity spread calculation
    'cost' => 5.0,

    // Sentinel value stored to mark "not interested" (fixed at -1.0)
    'not_interested' => -1.0,

    // Maintain vogoo_links incrementally on every rating change.
    // When false, run "sculpt recommender:rebuild-links" after bulk imports.
    'direct_links' => false,
    'direct_slope' => true,
];
```

`direct_links` and `direct_slope` are independent. You can enable either or both:

|        | `direct_links`                                    | `direct_slope`                                             |
|--------|---------------------------------------------------|------------------------------------------------------------|
| Powers | `getLinkedItems()`, `memberGetRecommendedItems()` | `getSlopeItems()`, `memberPredict()`, `memberPredictAll()` |
| Counts | Co-occurrence (liked pairs only)                  | All rated pairs                                            |

When both are `false`, `vogoo_links` is read-only at runtime and must be rebuilt manually.
Run a full rebuild when enabling either incremental option on an existing ratings store.
Invalid persisted ratings return `false` from `setRating()`; visitor ratings and
invalid configuration throw `InvalidArgumentException`. Ratings must be finite,
within 0.0–1.0, or exactly -1.0. IDs and categories must be nonnegative.

## Usage

### With Canvas (autowired)

`RecommendationEngine` and `ItemRecommender` are resolved automatically by the Canvas DI container — their
constructors depend only on `Connection` (provided by `quellabs/canvas-database`) and `RecommendationConfig`
(provided by this package), so no manual wiring is required:

```php
use Quellabs\Recommender\ItemRecommender;
use Quellabs\Recommender\RecommendationEngine;

class ProductController
{
    public function __construct(
        private RecommendationEngine $engine,
        private ItemRecommender $recommender,
    ) {}
}
```

### Standalone

```php
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\RecommendationEngine;
use Quellabs\Recommender\ItemRecommender;

$connection = new Connection([
    'driver'   => Mysql::class,
    'host'     => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'mydb',
]);

$config    = new RecommendationConfig();
$engine    = new RecommendationEngine($connection, $config);
$recommender = new ItemRecommender($connection, $config);
```

---

## API reference

### `RecommendationEngine`

Handles rating CRUD. All write methods trigger incremental link/slope updates when `direct_links` or `direct_slope` is
enabled.

```php
// Record ratings
$engine->setRating($memberId, $productId, 0.8);
$engine->automaticRating($memberId, $productId, purchase: true);  // 1.0
$engine->automaticRating($memberId, $productId, purchase: false); // 0.7, or +0.01
$engine->setNotInterested($memberId, $productId);

// Read ratings
$engine->getRating($memberId, $productId);           // ['rating' => 0.8, 'ts' => '...']
$engine->memberRatings($memberId);                   // [['product_id', 'rating', 'ts'], ...]
$engine->memberNumRatings($memberId);
$engine->memberAverageRating($memberId);
$engine->productRatings($productId);
$engine->productNumRatings($productId);
$engine->productAverageRating($productId);

// Delete
$engine->deleteRating($memberId, $productId);
$engine->deleteMember($memberId);
$engine->deleteProduct($productId);
```

### `ItemRecommender`

Item-based CF and Slope One recommendations.

```php
// Item-based CF (requires direct_links or rebuild)
$recommender->getLinkedItems($productId);                          // [productId, ...]
$recommender->memberGetRecommendedItems($memberId);                // [productId, ...]
$recommender->memberGetReasons($memberId, $productId);             // [productId, ...]

// Slope One (requires direct_slope or rebuild)
$recommender->memberPredict($memberId, $productId);                // float|null
$recommender->memberPredictAll($memberId);                         // [['product_id', 'rating'], ...]
$recommender->getSlopeItems($productId);                           // [['product_id', 'diff'], ...]

// Opt-in scores, strategy, and contributing product IDs
$recommender->memberRecommendationsDetailed($memberId);           // RecommendationResult[]
$recommender->visitorRecommendationsDetailed($visitor);           // RecommendationResult[]

// Anonymous visitors (pass a VisitorContext instead of a member ID)
$recommender->visitorGetRecommendedItems($visitor);
$recommender->visitorPredict($visitor, $productId);
$recommender->visitorPredictAll($visitor);
```

All methods accept an optional `$filter` (array of allowed product IDs), `$limit`, and `$category` parameter.
The detailed methods also accept `$minHistory` (default 1) and `$minRatings`
(default 2). Below the genuine-rating history threshold they rank top-rated
products with at least `$minRatings` ratings. Seen and rejected products are
excluded. `item_links` scores sum `liked_count × (source rating − like threshold)`;
`top_rated` scores are average genuine ratings. Scores are meaningful only
within the same strategy. A single-item Slope One prediction estimates even
an already rated item; the all-item methods return only unrated items.

Allowed-item filters are applied before SQL limits. Lists above 500 IDs are
loaded into a temporary indexed table in batches of 500 for bounded query size.

### `UserSimilarity`

User-based CF. Similarity scores range from 0 (no overlap) to 100 (identical taste).

```php
$similarity = $userSimilarity->memberSimilarity($memberId1, $memberId2); // int 0–100
$neighbours = $userSimilarity->getNeighbours($memberId, minSimilarity: 10, limit: 20);
$items      = $userSimilarity->memberGetRecommendedItems($memberId);
```

`getNeighbours()` aggregates candidate overlaps in one query. Recommendation
rating reads are grouped in batches of 500 neighbours.

### `VisitorContext`

Holds in-memory ratings for anonymous visitors. Persist and restore it across requests via session serialization.

```php
$visitor = new VisitorContext($config);
$visitor->setRating($productId, 0.9);
$visitor->setNotInterested($productId);
$visitor->removeRating($productId);
$visitor->getRatings();         // [['product_id', 'rating', 'category'], ...]
$visitor->getRatedProductIds(); // [productId, ...]
```

### `Statistics`

```php
$stats->numMembers();
$stats->numProducts();
$stats->numRatings();
$stats->numLinks();
$stats->mostRatedProducts(limit: 10);  // [['product_id', 'num_ratings'], ...]
$stats->topRatedProducts(limit: 10, minRatings: 5); // [['product_id', 'avg_rating'], ...]
```

## Sculpt CLI commands

| Command                                         | Description                                     |
|-------------------------------------------------|-------------------------------------------------|
| `sculpt recommender:init`                       | Publish `config/recommender.php`                |
| `sculpt recommender:init-db`                    | Create `vogoo_ratings` and `vogoo_links` tables |
| `sculpt recommender:init-db --force`            | Drop and recreate tables                        |
| `sculpt recommender:rebuild-links`              | Rebuild `vogoo_links` from all ratings          |
| `sculpt recommender:rebuild-links --category=N` | Rebuild a single category                       |

## Multi-category support

Every method accepts an optional `?int $category` parameter. When omitted it falls back to the `category` value in
`RecommendationConfig` (default: 1). To work with multiple catalogues, either pass the category explicitly or
instantiate separate `RecommendationConfig` objects per category:

```php
$booksConfig    = new RecommendationConfig(category: 1);
$moviesConfig   = new RecommendationConfig(category: 2);

$booksEngine  = new RecommendationEngine($connection, $booksConfig);
$moviesEngine = new RecommendationEngine($connection, $moviesConfig);
```

## Optional reconciliation and catalog eligibility

The existing item-link, Slope One, and user-similarity methods remain independently callable. Detailed Slope One methods (`memberPredictDetailed`, `memberPredictAllDetailed`, and visitor equivalents) are available on `ItemRecommender` and `RecommendationEngine`. They return `PredictionResult` with a clamped rating and `supportCount`. Support is the sum of directed-pair `slope_count` values, not a count of independent people. Their `minSupport` defaults to 1 and must be positive.

For a combined pool, construct `RecommendationReconciler` with the same connection and config used by the existing algorithms. The application must supply an `EligibilityProvider` already bound to the relevant category, tenant, locale, and current catalog state. Its `filterEligible(array $candidateIds): array` method receives a distinct, small batch and returns an order-preserving subset. It must enforce any timeout itself. Any exception or invalid response on any batch fails the whole reconciliation request; the caller decides whether to use a cache or show nothing.

```php
use Quellabs\Recommender\Reconciliation\ArrayEligibilityProvider;
use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;

$request = new ReconciliationRequest(
    eligibility: new ArrayEligibilityProvider($smallCatalogEligibleIds),
    sources: [RecommendationSource::SlopeOne, RecommendationSource::TopRated,
        RecommendationSource::NewProducts],
    limit: 10,
    placement: 'home',
    newProductIds: $orderedNewProductIds,
    additionalCandidateIds: $explorationIds,
    contextKey: 'tenant-a',
);
$reconciler = new RecommendationReconciler($connection, $config);
$pool = $reconciler->rankCandidatesMember($memberId, $request);
$shown = $pool->selectDisplayedIds([/* distinct item IDs from $pool */]);
```

The source array is a set; its order does not affect ranking. A visitor can use `rankCandidatesVisitor` or `recommendVisitor` with the same request shape, except `user_similarity` requires a persisted member. `recommendMember` and `recommendVisitor` return up to `limit` items; sparse or ineligible catalogs can yield fewer. `rankCandidatesMember` and its visitor equivalent expose the bounded full candidate pool for application-controlled selection. New products can be unrated but must be supplied as IDs by the application. The package does not query or verify a host product table. `ArrayEligibilityProvider` keeps the complete eligible-ID set in memory and suits small catalogs; larger catalogs should implement the interface using their own indexed or cached store. Eligibility answers are never cached by the package.

Each source first searches `max(50, 5 * limit)` candidates. If too few survive, it doubles the source depth up to `max_candidate_depth` (default 2000) for at most `max_backfill_rounds` (default 3) additional rounds. Provider calls carry no more than `max_eligibility_batch_size` IDs (default 500). These are payload and search bounds; the provider is responsible for its own latency. A concurrent change that alters the prefix of a deeper source result fails the request. Seen and rejected items are excluded before provider calls. The provider's bound catalog context and the optional `contextKey` are separate: `contextKey` only partitions model training and evaluation records, alongside category, placement, and enabled sources. Omit it for the no-context partition; an empty string is invalid.

Before a validated model is activated for the exact partition, `scoreKind` is `rank_fusion`: a sum of `1 / (60 + post-eligibility source rank)` across nominating sources. It is a rank score, not a probability. After activation, `scoreKind` becomes `click_probability`; each ranking score estimates a click at position 1 under the observed display policy. Source evidence includes native scores, post-eligibility ranks, available support or rating counts, and fitted source contributions when a model is active. An eligible item can also have an available source signal outside that source's searched candidate slice; its evidence has a null `sourceRank`, is saved with an impression, and does not contribute to rank fusion or model features. Incremental `direct_links` and `direct_slope` flags do not prove that derived data exists; a rebuild may have populated it, and disabled incremental maintenance can make it stale.

## Opt-in evaluation and click model

Back up the database, then run `sculpt recommender:init-evaluation-db` to add the five optional tables from [`migrations/2026-10-evaluation-tables.sql`](migrations/2026-10-evaluation-tables.sql). This command does not drop ratings or links. A rollback backs up and drops, in order, `vogoo_outcomes`, `vogoo_impression_evidence`, `vogoo_impression_items`, `vogoo_impressions`, and `vogoo_models`. Keep old model artifacts while their logged items reference them. Indexes support scoring-key lookup, display cohorts, model references, and item outcomes; table size grows with every opted-in display and event.

Generating or ranking a list makes no analytics write. After actual display, call `EvaluationRecorder::recordImpression($shown, $memberId, $shownAt)` and retain its `ImpressionId`. Direct algorithm callers can build a `RecommendationList::fromDisplayedItems(...)` with their displayed `ReconciledRecommendation` items and evidence. Record clicks and purchases with a stable printable-ASCII event ID, an `OutcomeType`, and the actual event timestamp via `recordOutcome`; exact retries are harmless. A visitor display can omit member ID. To erase a member's evaluation history, call `deleteMemberHistory($memberId)` as well as the existing ratings deletion operation.

Reports require explicit positive `AttributionWindows` and an `asOf` cutoff. `EvaluationReport::summary` counts each displayed item once per outcome type within the chosen window and exact context partition; source-specific reports overlap when an item has several source signals. The cohort is final only after the longest window has elapsed beyond the cohort end and all events through `asOf` have arrived. Choose a retention period yourself and run `sculpt recommender:prune-evaluation --before=<UTC ISO-8601 timestamp> [--batch-size=N]`; no automatic deletion occurs.

Train with `sculpt recommender:train-click-model --category=N --placement=KEY --sources=slope_one,top_rated --from=UTC --to=UTC --as-of=UTC --click-window-seconds=N [--context=KEY]`. The command requires mature displayed-item labels, a chronological holdout, minimum sample counts, and validation against a constant-rate baseline. It stores a candidate model; activate a validated candidate explicitly with `sculpt recommender:activate-click-model --id=HEX`. Without enough data or an active validated model, rank fusion continues to serve. Logged position helps calibrate observed displays but does not remove exposure selection bias; rarely shown products do not have a reliable unbiased click estimate.

## License

MIT
