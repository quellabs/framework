# Independent recommender measures

## Optional reconciliation and evaluation API

This update adds detailed Slope One predictions with summed directed-pair support,
an optional `RecommendationReconciler`, a required per-request catalog
`EligibilityProvider`, ranked candidate pools, and caller-supplied new products.
Existing public algorithm signatures and result shapes remain unchanged.
Reconciliation uses rank fusion until a validated click model is explicitly
activated for the exact category, placement, enabled sources, and context key.

The optional evaluation upgrade adds five package-owned tables. Back up the
database and run `sculpt recommender:init-evaluation-db`; this does not require
`--force` and does not change the two existing tables. Display logging is opt-in
through `EvaluationRecorder`; attribution reports require explicit windows and
cutoff times. `recommender:prune-evaluation` requires an explicit cutoff.
Training and activation are separate commands. Applications handling complete
member deletion should call both the existing ratings deletion method and
`EvaluationRecorder::deleteMemberHistory()`.

To roll back only the optional feature, back up evaluation data and drop the
five new tables in foreign-key-safe order: outcomes, evidence, impression items,
impressions, and models. Existing rating and link data require no rollback.

On a local MySQL test database with one member rating and 1,000 directed links,
one 10-item item-link request used 10 queries and 9.425 ms when all 1,000 IDs
were eligible. With only an item beyond the configured depth cap eligible, it
returned zero items after bounded backfill using 24 queries and 21.617 ms.
These are single-run fixture measurements, not production latency estimates.


The `vogoo_links.cnt` column is replaced by `liked_count` and `slope_count`.
`diff_slope` now sums the directed rating difference only for genuine rating
pairs. Existing `cnt` values mix the two algorithms and cannot be reused.
`getLinkedItems()` now requires a positive liked count; Slope One uses only
the slope count. The rebuild command computes both measures regardless of
incremental settings and clears stale categories.

Before upgrading, back up both `vogoo_ratings` and `vogoo_links` together.
Pause rating writes, run
[`migrations/2026-10-independent-pair-counts.sql`](migrations/2026-10-independent-pair-counts.sql),
then run `sculpt recommender:rebuild-links`. Verify recommendations before
resuming writes. The migration leaves `vogoo_ratings` intact and clears derived
rows before changing the schema. Do not use `recommender:init-db --force` for
an upgrade; it drops ratings.

For rollback, stop rating writes, restore both backed-up tables, reinstall
the previous package version, and resume writes. Restoring only `vogoo_links`
would put derived counts out of sync with ratings written after the backup.

The SQL rebuild replaces each category in a transaction after staging its
results. On a local MySQL test database, 150 members with 12 ratings each
(19,800 directed contributions) took 0.038 seconds with the set-based
aggregation versus 3.542 seconds with per-pair upserts. These measurements
include query overhead and are a small-catalog comparison, not a production
capacity estimate.

For user recommendations, the grouped path used three queries with 20
neighbours in the integration fixture. A local timing run returned 10 items
from 10 neighbours in 2.31 ms and 200 items from 200 neighbours in 5.46 ms.
These fixture measurements exclude network latency and application work.
