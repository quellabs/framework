# Release Notes: `feature/foreign-keys`

Compared against `main` (12 commits, 59 files changed, +4440/-149).

## Foreign key support (ObjectQuel)

- **New `@Orm\ForeignKey` annotation** describing a real database foreign-key
  constraint (target entity + referenced column). Legal on a plain scalar
  column or alongside a relation.
- **New `@Orm\ForeignKeyAction` annotation**, split out of `ForeignKey`,
  carrying the `ON DELETE` / `ON UPDATE` behavior. Requires a `ForeignKey` on
  the same property. Defaults to `RESTRICT` / `NO ACTION` when absent.
- **`Cascade` and `ForeignKey`/`ForeignKeyAction` are now fully independent.**
  `Cascade` is purely PHP-side object-graph behavior (requires a
  `ManyToOne`/`OneToOne` on the same property); the FK annotations are purely
  structural/DB-side. A relation can have either, both, or neither.
- **Entity → schema generation**: `make:migrations` now diffs entities'
  `ForeignKey`/`ForeignKeyAction` annotations against the live database and
  emits Phinx `addForeignKey`/`dropForeignKey`/modify code, including correct
  `down()` inversion for a modified constraint's original rule.
- **Schema → entity detection**: `make:entity-from-table` detects real FK
  constraints and emits matching annotations, gated behind a new
  `generate_foreign_keys` config key (defaults to `false`, so existing
  projects get byte-identical output until they opt in). Handles
  self-referencing FKs, FKs to not-yet-generated entities, FKs to non-PK
  (unique) columns, and skips composite FKs as unrepresentable.
- **`make:entity`** (entity → schema, owning side) now also writes a
  `@Orm\ForeignKey` with safe defaults onto owning-side `ManyToOne`/`OneToOne`
  properties when `generate_foreign_keys` is enabled, closing the gap where
  only the schema→entity direction supported this.
- **`DatabaseAdapter::getForeignKeys()`** added for both SQLite and MySQL
  introspection; SQLite connections now enable `PRAGMA foreign_keys`.

## Bug fixes

- **OneToOne cascade/FK data-integrity bug**: `UnitOfWork` only populated a
  relation's local FK column on persist for `ManyToOne` or a *bidirectional*
  `OneToOne`, silently leaving a unidirectional `OneToOne`'s FK column `NULL`
  regardless of any `Cascade`/`ForeignKey` declared on it. Same bidirectional-only
  restriction was gating cascade-remove eligibility. Both filters removed —
  `OneToOne` now behaves like `ManyToOne` regardless of whether an `InverseOf`
  back-reference exists.
- **`EntityBridge` eager-loading**: `@Orm\EntityBridge` existed but nothing
  read it, so a many-to-many modeled as a bridge entity with two ordinary
  `ManyToOne` relations hit an N+1 the moment the far side was accessed off an
  eager-loaded parent. `QueryBuilder` now eager-joins one hop further through
  a bridge entity's own relations (no new relation type — the bridge stays a
  real, addressable entity). Also fixes a related bug in
  `RewriteViaRelationToJoinCondition` where chaining a second via-hop off an
  already-joined dependent range overwrote that range's `viaRelation` tag,
  silently breaking `InverseOf` collection matching.

## Test coverage

- New end-to-end suite for `Cascade`/`ForeignKey`/`ForeignKeyAction` across
  `OneToOne` (bidirectional and unidirectional) and `ManyToOne`+`InverseOf`
  ("one-to-many"), previously untested in combination.
- New coverage for real cascading deletes (`ON DELETE CASCADE`/`RESTRICT`) via
  raw SQL bypassing the ORM, on both SQLite and MySQL.
- New coverage for the migration "modified constraint" diff/codegen path,
  including correct `down()` inversion.
- New MySQL counterparts for FK detection/enforcement tests that previously
  only ran against SQLite.
- New coverage for `EntityBridge` eager-loading, including the `fetch="LAZY"`
  opt-out case.
- ObjectQuel FK/relationship tests moved from their own isolated
  `packages/objectquel/tests` suite into `tests/ObjectQuel`, sharing the root
  suite's MySQL `EntityManager` instead of a separate SQLite one.
- Total: 12 commits' worth of incremental coverage, ending well above the
  starting 30-test baseline for this feature.
