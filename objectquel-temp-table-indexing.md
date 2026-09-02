# Temp table indexing for TempTableStage

Source: https://medium.com/@Rohan_Dutt/10-techniques-for-using-temporary-tables-effectively-in-stored-procedures-d4979bad02b0

Reviewed the article's 10 techniques against `TempTableExecutor.php`. Most don't apply
(SQL Server-only features like table variables/columnstore indexes, or stored-procedure/DBA
concerns like tempdb monitoring and cross-procedure schema reuse). Batched inserts and
try/finally cleanup are already handled correctly.

## The one real gap

`createTable()` in `packages/objectquel/src/Execution/Executors/TempTableExecutor.php:164`
creates every column as `VARCHAR(255)` with no index. The temp table exists specifically to be
joined against the outer query via `AstRangeDatabaseTempTable::getJoinProperty()`
(`packages/objectquel/src/ObjectQuel/Ast/AstRangeDatabaseTempTable.php`), so that join currently
runs as a full scan on the temp table for every outer row.

## Proposed fix

1. After `insertRows()` completes, pull the join column(s) off `$range->getJoinProperty()`.
   Sanity check first: confirm the join predicate is a plain column reference, not a function
   expression (e.g. `LOWER(col)`) — a plain `CREATE INDEX` on the column wouldn't be used by a
   function-wrapped predicate, and would need a functional index instead.
2. Run `CREATE INDEX` on those column(s) — index-after-insert avoids per-row index maintenance
   during the batch insert.

Note: index-after-insert is intentional, not an oversight. Indexing an empty table first means
every batch `INSERT` pays incremental B-tree maintenance (page splits/rebalancing). Building the
index once after all data is loaded is a single bulk build instead — the same reason MySQL docs
recommend loading data before adding indexes, and why tools like `mysqldump` add indexes after
the data load. Only reason to flip the order would be wanting the index in place for partial data
after a failed batch, which doesn't apply here since `insertRows()` either fully succeeds or
throws, and `cleanup()` drops the whole table regardless.

## Second real gap: blanket VARCHAR(255) is a design flaw, not just a nice-to-have

Every column is created as `VARCHAR(255)` regardless of its real type (`createTable()`,
TempTableExecutor.php:164-168). This isn't only an index-usability problem — it's a correctness
footgun. If the other side of the join is e.g. an `INT` primary key, MySQL may not use the new
index at all due to implicit type conversion, and numeric/date comparisons routed through a
VARCHAR column can behave subtly differently than native-typed comparisons. This should be fixed
alongside the indexing change, not treated as optional follow-up.

Decided: **look up the real type via `Metadata`** (`packages/objectquel/src/Metadata/ColumnData.php`
already carries per-column declared type off the `@Column` annotation, e.g. the pattern used for
`softDeleteColumnType`), rather than inferring type from a sampled PHP row value.

Rejected the "infer from PHP value" alternative (sample the first non-null value in
`insertRows()`'s typed rows and map PHP type → SQL type): it's cheaper but not authoritative —
it depends on unverified PDO stringification behavior in `DatabaseAdapter`, only samples one row,
gives no answer for the empty-LEFT-JOIN case where there's no row to sample, and guesses at
runtime instead of using the entity's actual declared type. Metadata lookup is correct by
construction and consistent with the project's no-hidden-inference stance — worth the extra
plumbing (new dependency from `TempTableExecutor` on `Metadata`) to resolve the joined column's
declared type directly.

## Status

Second gap implemented: `TempTableExecutor` now resolves each projected column's SQL type from
the source entity's declared `@Column` metadata (via `EntityStore::getMetadata()`), falling back
to `VARCHAR(255)` only for expressions that don't trace back to a single entity property (function
calls, computed expressions, literals, empty-LEFT-JOIN projections with no resolvable source).

First gap (indexing the join column(s) after insert) is still not implemented.
