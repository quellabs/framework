# ObjectQuel: `create` (create table, permanent + temporary) — implementation plan

Split out of `objectquel-ddl-design.md` (overview/rationale doc, still
current — see it for the full "Relationship to Phinx migrations"
reasoning summarized below, though its literal `create table`/`delete
table` spelling is superseded by the corrected syntax below). Sibling
plan: `objectquel-destroy-plan.md`. Depends on
`objectquel-write-verbs-design.md`'s `attr = type` declaration convention
and `DatabaseAdapter\DDLTypeMapper`. Covers both plain `create` and
`create temporary` in one file — they differ only in a keyword and a
couple of dialect fragments, not in overall shape.

**Syntax correction:** the original design doc's `create table Name (...)`
spelling was checked against the actual Ingres QUEL Reference Guide (the
canonical QUEL reference) and is wrong — authentic QUEL has **no `table`
keyword** in the create statement at all:

> `[##] create [locationname:]tablename (columnname=format [null_clause] {, columnname=format} [null_clause])`

(Wikipedia's QUEL example agrees: `create student(name = c10, age = i4,
...)`.) `temporary` has no historical QUEL precedent either way — QUEL
never had a temp-table concept — so it stays an ObjectQuel-only extension
keyword, just without the now-removed `table`.

Goal: `create [temporary] Name (attr = type constraints, ...)`.

## Current state — existing building blocks to reuse

- `AstRangeDatabaseTempTable` / `Planner\TempTableStage` /
  `TempTableExecutor` already materialize a query's results into a real
  temp table today, but purely as an internal planner optimization,
  created/dropped automatically around a single stage
  (`TempTableExecutor` drops it in a `finally` block). Not addressable
  across multiple QUEL statements, no user-facing syntax produces one.
  **Not** reused directly by this feature — see New AST, below — but
  confirms a "range keyed on a raw table name, not an Entity" shape
  already exists in the codebase to model after.
- `DDLTypeMapper` already does most of the hard cross-dialect work:
  - `getCreateTempTableKeyword()` — the class's own docblock flags a real
    asymmetry: `CREATE TEMPORARY TABLE` works on every engine except SQL
    Server, which has no such keyword (temp-ness is a `#`-prefixed name
    instead, via `getTempTableName()`).
  - `getTempTableColumnType()` already maps a column definition shaped
    like a `@Orm\Column` annotation (`{type, limit, unsigned, precision,
    scale}`) to a dialect-correct DDL type fragment, for
    `mysql`/`pgsql`/`sqlite`/`sqlsrv` already. QUEL's `create table` column
    types should reuse this same abstract type vocabulary, not invent new
    QUEL-specific type names — and, per the "Column types" section below,
    not historic QUEL's `c10`/`i4`/`f8`-style format codes either.
- No permanent `CREATE TABLE` exists anywhere in ObjectQuel today —
  entity-mapped schema is exclusively managed through Phinx migrations.

## Relationship to Phinx migrations (governance rule — decided)

**Decided: no restriction.** `create`/`destroy` are not limited to
non-Entity-mapped tables — they may target the same tables Phinx manages.
No collision/rejection check against `EntityStore` is needed (this
removes the semantic check originally planned in step 6, below). A table
created via `create` is still addressed afterward via the new range form
keyed on a bare table name (`range of a is table Name` — the `table`
keyword survives here as ObjectQuel's own disambiguation marker between a
bare table name and an Entity class name in range declarations; it has
nothing to do with the `create`/`destroy` statement grammar, which never
had a `table` keyword to begin with), but `range of x is SomeEntity`
remains available too and is not exclusive to Phinx-managed tables by
construction — this feature simply doesn't add a guard rail there.

## Syntax

```
create ArchiveLog (
    id = integer identity primary key,
    message = string not null,
    created_at = datetime not null
)
```

```
create temporary StagingTotals (
    user_id = integer,
    total = decimal
)
```

A table created this way is addressed via a new range form, `table`
instead of an Entity class name:

```
range of a is table ArchiveLog
append to a (message = "archived", created_at = now())
```

## Column types (deviation from historic QUEL)

Historic QUEL spells column formats as compact codes tied to physical
storage width — `c10` (10-char fixed string), `i4` (4-byte integer), `f8`
(8-byte float), and so on. ObjectQuel does **not** use this format. It
already has its own abstract, engine-agnostic type vocabulary — the same
one `@Orm\Column` annotations use and that `TypeMapper`/`DDLTypeMapper`
already map to PHP types and per-dialect DDL fragments — and `create`
reuses it as-is rather than inventing a third naming scheme. This is a
deliberate break from authentic QUEL syntax, not an oversight; unlike the
`create table` → `create` keyword correction above (which fixes a spelling
error against the real QUEL grammar), this is ObjectQuel choosing
consistency with its own existing annotation-driven schema over historic
fidelity.

Current vocabulary (from `TypeMapper::TYPE_MAP` /
`DDLTypeMapper::getTempTableColumnType()`), one name per abstract type,
dialect rendering already implemented for `mysql`/`mariadb` (default),
`pgsql`, `sqlite`, `sqlsrv`:

- Integers: `tinyinteger`, `smallinteger`, `integer`, `biginteger`
  (`unsigned` is a modifier, not a separate type — see limit/precision
  note below)
- Floating point: `float`, `decimal` (`precision`/`scale`)
- Strings: `string` (the general-purpose type — historic QUEL's `varchar`
  equivalent), `char` (fixed-width), `text`
- Boolean: `boolean`
- Date/time: `date`, `datetime`, `time`, `timestamp`
- Binary: `binary`, `blob`
- Other: `json`, `uuid`, `year`, `enum`, `set`

Notes carried over from the existing mapper, relevant to how `create`'s
grammar should accept type arguments:

- `string`/`char`/`binary` take an optional length limit; `TypeMapper`
  already has per-type default limits (`string`/`char`/`binary` → 255,
  `tinyinteger` → 4, `smallinteger` → 6, `integer` → 11, `biginteger` →
  20) used when none is given. `decimal` takes optional
  `precision`/`scale` instead of a limit.
- `enum`/`set` are accepted as abstract types (`RELEVANT_PROPERTIES`
  already lists `enum` with a `values` property) but
  `DDLTypeMapper::getTempTableColumnType()` has no dedicated case for
  either yet — both currently fall through to its `VARCHAR($limit)`
  default branch. `create`'s type grammar can still accept the type name;
  proper native rendering (MySQL `ENUM(...)`, Postgres native/check-based
  enums, etc.) needing per-dialect work is not blocking for a first
  version but should be called out, not silently inherited as a bug.
- `json` maps to `JSON` (mysql/sqlite* text-affinity), `JSONB` (pgsql),
  `NVARCHAR(MAX)` (sqlsrv) — dialect divergence already handled, nothing
  new needed for `create`.
- No new abstract type names should be introduced by this feature. If a
  needed type is missing from the vocabulary above, that's a
  `TypeMapper`/`DDLTypeMapper` change to make explicitly and separately,
  not a `create`-statement-local special case.

## New AST

- `AstCreateTable`: table name, `AstColumnDefinition[]` (name, abstract
  type reusing `TypeMapper`'s vocabulary, small constraint set — see
  Scope cut), `bool $temporary`.
- `AstRangeDatabaseTable`: a new, lighter sibling of `AstRangeDatabase`
  (which resolves against an Entity class) — holds a raw table name
  directly, no `EntityStore` lookup involved. **Not** the same as
  `AstRangeDatabaseTempTable`, which stays exactly what it is today (a
  subquery that gets materialized); a QUEL-authored table has an explicit
  column list, not an underlying query, so it needs its own range shape.
  Confirm this against the actual class hierarchy once implementation
  starts, not assumed from names alone.

## Grammar / parser hook points

- New keywords: `create`, `temporary`, `identity`, `primary`, `key`,
  `null` (`not` already exists as `Token::Not`, `AstNull` already exists
  as a value node, but a `null`/`not null` *constraint* keyword in a
  column definition is new — confirmed none of
  `null`/`identity`/`primary`/`key` are in `Lexer::$keywords` today).
  `table` is *not* part of the `create` statement grammar itself (see
  Syntax correction, above) — it's only needed separately, as the
  range-declaration marker below.
- `Parser::parse()` gains a `Token::Create` case delegating to
  `Rules\CreateTable`. `Rules\CreateTable` parses `create [temporary]
  tablename (col = format, ...)` directly — no `table` token to consume
  after `create`.
- `range of x is table Name` needs a grammar addition in the range-parsing
  path to recognize the `table` keyword as an alternative to an Entity
  class name — this is where `table` actually gets added as a keyword,
  not in the `create` statement.

## Compile (QuelToSQL)

- `AstCreateTable` → `{DDLTypeMapper::getCreateTempTableKeyword() or
  'CREATE TABLE'} <name> (<col> <type> <constraints>, ...)`, one column at
  a time through `DDLTypeMapper::getTempTableColumnType()` for the type
  fragment (already implemented, reused as-is). **Constraint rendering
  (`NOT NULL`/`PRIMARY KEY`/an identity-or-auto-increment fragment) is
  new work** — `DDLTypeMapper` currently only renders the type fragment,
  not constraints, since `TempTableExecutor`'s existing caller infers
  columns from a source query's result shape and never needed them. This
  is the one piece of this plan without an existing implementation to
  lean on — budget real design time for it, per-dialect.
- `range of x is table Name` → resolves through `AstRangeDatabaseTable`,
  compiled by `QuelToSQL` as a plain table reference — same path an
  `AstRangeDatabase` reference hits today, minus the `EntityStore`
  metadata lookup.

## Open decisions — decided

**Temp table disposal at procedure end — decided: author-explicit, no
compiler enforcement.** The author must write `destroy` explicitly to
drop a `create temporary` table; the compiler does **not** insert an
implicit destroy at procedure exit paths, and does **not** perform
exit-path analysis to error on a table left undropped. If a temp table is
never explicitly destroyed, disposal is left to the database engine's own
session/connection-scoped temp-table cleanup — no ObjectQuel-level
guarantee beyond that. This differs from `TempTableExecutor`'s existing
`finally`-block precedent (which is deterministic), but that precedent is
for an internal planner optimization, not user-authored code, so it isn't
binding here. This also means step 10 ("implement whichever
temp-table-disposal option was chosen") drops out of the implementation
steps — there's no compiler-side disposal mechanism to build, only the
plain `destroy` statement itself (see `objectquel-destroy-plan.md`).
Cross-reference: `objectquel-equel-design.md` (procedure body semantics).

## Scope cut for a first version

- Minimal constraint vocabulary only: `not null`, `primary key`,
  `identity` (auto-increment/serial, however the target dialect spells
  it). No foreign keys, no non-primary unique constraints, no default
  values — even for QUEL-created tables, those stay a Phinx job for v1. A
  table needing real relational constraints is arguably a sign it should
  be an Entity-mapped table via Phinx in the first place.
- No `ALTER TABLE` — a QUEL-created table's shape is fixed at creation;
  changing it means `destroy` + `create` again. Fine for scratch/staging/
  archive use, this feature's actual target.
- `range of x is table Name` resolving a permanent table created outside
  QUEL entirely (pre-existing table, no `create` statement, no Entity
  class) is a natural low-cost extension of the same mechanism but isn't
  the core ask — worth allowing if it falls out for free, not worth
  designing around specifically for v1.

## Implementation steps (ordered)

1. ~~Sign-off on governance rule and temp-table-disposal~~ — decided, see
   above: no Phinx-collision restriction, author-explicit temp disposal.
2. Add keywords: `create`, `temporary`, `identity`, `primary`, `key`,
   `null` — plus `table`, separately, for the `range of x is table Name`
   marker (not part of the `create` statement grammar).
3. Add `AstCreateTable` and `AstColumnDefinition` nodes.
4. Add `AstRangeDatabaseTable` node; extend range-parsing to recognize
   `table Name`.
5. Add `Rules\CreateTable`, wire `Parser::parse()`'s `Token::Create` case.
6. Implement constraint rendering in `DDLTypeMapper` (or a new sibling)
   for `NOT NULL`/`PRIMARY KEY`/identity, per dialect.
7. Add `AstCreateTable` visit method to `BuildSqlFromAst`, using
   `getCreateTempTableKeyword()`/`getTempTableColumnType()` plus the new
   constraint rendering.
8. Add `AstRangeDatabaseTable` compile support (plain table reference, no
   `EntityStore` lookup).
9. Tests: permanent + temporary create, each constraint kind, per dialect
   (`mysql`/`pgsql`/`sqlite`/`sqlsrv`), `range of x is table Name`
   resolution and subsequent `append`/`replace`/`delete` against it,
   temp table left undestroyed (confirm no compiler error is raised).
