# ObjectQuel: `destroy` (drop table / drop index) — implementation plan

Split out of `objectquel-ddl-design.md` (overview/rationale doc, still
current — its "delete table"/"delete index" spelling predates the
research below and should be read as superseded by this file for naming).
Depends on `objectquel-create-table-plan.md` (governs which names are
legal targets — see Semantic analysis) and `objectquel-create-index-plan.md`
(index metadata this file must resolve against). Replaces what were
originally drafted as two separate files, `objectquel-delete-table-plan.md`
and `objectquel-delete-index-plan.md` — see "Why one file", below, for why
they were merged.

Goal: drop a QUEL-created table or index. **The authentic QUEL verb is
`destroy`, not `delete table`** — confirmed against the Ingres QUEL
Reference Guide (Actian/CA, the canonical QUEL reference — the "QUEL
Statements" chapter, "Destroy Statement" section):

> `[##] destroy tablename {, tablename}`
> `[##] destroy permit | integrity tablename integer {, integer} | all`

No `table` keyword, and it accepts a comma-separated list of multiple
names in one statement (max 30 per the reference).

## Why one file (not separate table/index destroy plans)

The same reference is explicit that **an index is not a structurally
different kind of destroy target**: "You can modify and destroy an index
(an index is also a table)." There is no separate "destroy index"
statement in authentic QUEL — `destroy indexname` (same statement, same
grammar) drops an index, and it does **not** take an `ON tablename`
clause; Ingres resolves the owning table internally. Also: "If the table
being destroyed has secondary indexes, the secondary indexes are also
destroyed" — dropping a table cascades to its indexes automatically. Given
that, table-drop and index-drop are one verb with one grammar in QUEL, not
two — this plan reflects that rather than inventing a separate `delete
index ... on ...` construct (which the original doc draft did, before this
reference was checked).

## Relationship to `delete` (the DML verb) — no keyword collision

Unlike the original draft (which had `delete table Name` sharing a leading
keyword with `delete <range> where ...` and needing lookahead dispatch to
disambiguate), `destroy` is a **completely separate keyword** from
`delete`. `objectquel-delete-plan.md` needs no dispatch logic for this at
all — `Token::Destroy` and `Token::Delete` are two independent cases in
`Parser::parse()`'s switch. This removes the dispatch-lookahead work that
plan originally described.

## Syntax

```
destroy ArchiveLog
destroy StagingTotals
destroy archive_log_email_idx
destroy ArchiveLog, StagingTotals
destroy ArchiveLog if exists
destroy temporary StagingTotals
```

`if exists` (added after initial implementation, see Semantic analysis
below) makes a missing name a no-op instead of an error.

`temporary` (also added after initial implementation, mirroring `create
temporary`) tells the compiler every named target is a session-temp
table. This isn't just a style option: on every engine except SQL Server,
`destroy Name` already resolves correctly to a session-temp table without
it (see Compile / execution, below, for why), but SQL Server has no such
shadowing — a local temp table's real name is `#Name`, a different
physical object — so `destroy temporary Name` is the only way to address
one there unambiguously and directly.

One statement for a permanent table or a temporary one (`temporary`
disambiguates when it matters); indexes aren't handled by this statement
at all — the compiler resolves the table/index distinction via metadata
(see Semantic analysis) rather than the author saying so in the syntax,
exactly matching real QUEL's own model (an index name and a table name
are drawn from the same namespace as far as `destroy` is concerned).

## New AST

- `AstDestroy`: `string[] $names` — a flat list, mirroring the
  comma-separated-list grammar directly rather than splitting into
  per-name statements at parse time.

No separate `AstDropTable`/`AstDropIndex` node split — see "Why one file",
above. Semantic analysis (not parsing) is what determines, per name,
whether it resolves to a table or an index.

## Grammar / parser hook points

- New keyword in `Lexer::$keywords`: `destroy` (no `Token::Destroy`
  constant yet).
- `Parser::parse()`'s switch grows a `Token::Destroy` case delegating to a
  new `ObjectQuel\Rules\Destroy` rule class, parsing the comma-separated
  name list.

## Semantic analysis — decided, and scoped down from the original draft

**Governance restriction: none**, mirroring `create`'s already-decided
answer (see `objectquel-create-table-plan.md`, "Relationship to Phinx
migrations — decided") — `destroy` may target any existing table,
Entity-mapped or not. No EntityStore check.

**Index-drop support: deferred, not in this pass.** The plan as
originally drafted assumed a compiler-side registry of "QUEL-created
tables/indexes" to resolve a bare name to table-vs-index (and, for an
index, its owning table). No such registry exists anywhere in this
codebase — `create table` and the (unimplemented) `create index` are
just direct DDL against the live connection, with no separate
bookkeeping layer. Building one would be a real, separate feature, and
`create index` (this file's other stated dependency,
`objectquel-create-index-plan.md`) isn't implemented at all yet either.
This first pass implements **table destroy only** (permanent +
temporary), consistent with `create` also being table-only so far.
Index destroy is real follow-up work once `create index` exists, not
solved here by inventing a shadow registry.

**Existence checking: fail loudly by default, opt into leniency via a
trailing `if exists` qualifier.** The "reject a name that doesn't resolve
to anything, no silent no-op" requirement from the original draft is
satisfied by default without ObjectQuel tracking existence itself: a bare
`DROP TABLE <name>` fails loudly with a real "unknown table" error from
the engine when the name doesn't exist, surfaced as a QuelException. This
also sidesteps a real correctness trap: session-scoped temporary tables
(from `create temporary`) are invisible to schema introspection
(`DatabaseAdapter::getTables()`) on at least MySQL, so a self-built
existence pre-check would wrongly reject destroying a temp table that
does in fact exist — letting the engine resolve the name at DROP time
handles permanent and temporary tables identically and correctly.

`destroy Name {, Name} if exists` is the opt-in escape hatch, added after
initial implementation: a trailing qualifier (not SQL's prefix-position
`IF EXISTS`) to match QUEL's English-sentence grammar, compiling to
`DROP TABLE IF EXISTS <name>` — valid, dialect-independent syntax on all
four target engines, so still no per-dialect branching needed.

**Temp vs. permanent: mostly no dialect branching, with one real
exception — SQL Server.** Unlike `TempTableExecutor`'s internal cleanup
(which deliberately uses `DROP TEMPORARY TABLE` on MySQL/MariaDB for a
narrower safety reason — see that class's docblock: guaranteeing it only
ever drops the specific synthetic temp table it created, never an
unrelated permanent table that happens to share the name), a
user-authored `destroy Name` names its target deliberately. Plain `DROP
TABLE <name>` is correct on mysql/mariadb/pgsql/sqlite for both permanent
and session-temporary tables (MySQL included — temp tables shadow
same-named permanent ones and plain `DROP TABLE` resolves to whichever
exists). SQL Server is the exception: a local temp table's real name is
`#name`, a completely different physical object from the logical name, so
there's no native shadowing to rely on. This is why `destroy` grew an
optional `temporary` keyword after initial implementation (see Syntax,
above) — it lets the compiler resolve the SQL-Server case unambiguously
instead of guessing. Without it, `QuelToSQLDestroy` emulates the same
shadowing SQL Server lacks (see Compile / execution, below).

## Compile / execution

No `BuildSqlFromAst` involvement — same precedent as `create` (see that
plan's "New AST"/"Compile" sections): a top-level DDL-shaped statement
bypasses the retrieve pipeline's expression-level visitor entirely.
`QuelToSQLDestroy` compiles each name in `AstDestroy::$names` to a `DROP
TABLE` statement, and `Execution\Executors\DestroyExecutor`, mirroring
`CreateTableExecutor`, runs each directly via `DatabaseAdapter::execute()`,
checking for its `null` return (it swallows the underlying DB exception
itself rather than throwing — see the fix made to `CreateTableExecutor`
for the same reason) and raising `QuelException` with
`DatabaseAdapter::getLastErrorMessage()` on failure.

Dialect branching, found after initial implementation: plain `DROP TABLE
<name>` is correct everywhere for a permanent table, and for a temp table
on every engine except SQL Server (see `temporary`, above) — but an
*unqualified* `destroy Name` on SQL Server, where it isn't known whether
the target is permanent or temp, needs to emulate the "temp shadows
permanent" priority the other three engines already give unqualified
names natively: `IF OBJECT_ID('tempdb..#Name') IS NOT NULL DROP TABLE
[#Name] ELSE DROP TABLE [Name]`. Without this, an unqualified `destroy` of
a table created via `create temporary` on SQL Server would either fail
with "invalid object name", or — if a permanent table happened to share
the name — silently drop that unrelated permanent table instead. See
`QuelToSQLDestroy` for the full reasoning.

A multi-name `destroy` runs one `DROP TABLE` per name, in order, stopping
at the first failure (not wrapped in a transaction — DDL isn't
transactional on MySQL/MariaDB regardless).

`QueryExecutor::executeQuery()` grows an `AstDestroy` branch identical in
shape to its `AstCreateTable` one: bypass semantic analyzer / optimizer /
planner / hydration, execute directly, return `null`. `explainQuery()`
rejects `AstDestroy` the same way it already rejects `AstCreateTable`.

## Open decisions — resolved

Both deferred to `objectquel-create-table-plan.md`, now settled there and
inherited here: no Phinx-governance restriction (see Semantic analysis,
above), and temp-table disposal is author-explicit with no compiler-side
enforcement — so there is no compiler-inserted-cleanup caller for this
verb's compile path to support; `destroy` only ever runs from parsed user
source. Step 7 from the original draft (wiring compiler-inserted cleanup
into this verb) is dropped.

## Implementation steps (ordered)

1. Add `Token::Destroy` and lexer keyword `destroy`.
2. Add `AstDestroy` node (flat `string[] $names`).
3. Add `Rules\Destroy`, wire `Parser::parse()`'s `Token::Destroy` case.
4. Add `Execution\Executors\DestroyExecutor` (table-only — see Semantic
   analysis above for why index-drop is out of scope for this pass).
5. Wire `AstDestroy` into `QueryExecutor::executeQuery()`/`explainQuery()`
   the same way `AstCreateTable` is wired.
6. Tests: single table drop (permanent + temp), multi-name `destroy` in
   one statement, unknown-name rejection (real engine error, not a
   silent no-op), Entity-mapped-table destroy allowed (no restriction).
