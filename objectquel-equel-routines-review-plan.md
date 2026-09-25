# EQUEL routines review implementation plan

Status: phase 1 implemented and validated; halted for inspection. Phases 2-7 have not started.

This plan addresses the eight findings from the review of `feature/equel-routines`
through commit `0206a53d`. Phases 1-4 address correctness, phases 5-6 address
structure, and phase 7 completes validation across database engines. Phase 6
combines the two related findings about duplicated type state and compiler setup.

## Execution and inspection rules

- Follow root `AGENTS.md` and `CLAUDE.md` throughout implementation.
- Start only the phase explicitly authorized by the user. Implement phases in
  order unless the user changes that order.
- Before each phase, inspect the current branch and working tree, including any
  commits or merges made since the previous inspection. Adapt to accepted changes
  and preserve unrelated work.
- Reproduce each correctness finding before fixing it. The review was static;
  if a finding is disproved or already resolved, record the evidence instead of
  forcing a change.
- Keep each phase independently reviewable. Add focused regression tests with
  the relevant fix; do not defer all tests to phase 7.
- Run relevant tests and static analysis, inspect the diff, and document actual
  results and unavailable checks. Use isolated test databases for execution tests.
- At the end of every phase, update its status below, report changed behavior,
  affected files, validation results, and remaining limitations, then **halt and
  end the turn**. Do not start the next phase until the user explicitly resumes it.
- The halt allows inspection, amendments, and an optional commit, push, or merge.
  Do not perform those Git actions unless the user authorizes them. A request to
  commit or merge the completed phase alone does not authorize the next phase.
- If inspection results in amendments, complete and validate those amendments,
  then halt again at the same inspection gate.

## Phase 1 - Integrate routine arguments with AST replacement

Status: implemented; awaiting inspection.

**Implementation results:**

- Reproduced two unsupported-parent exceptions before the fix: alias expansion
  inside routine arguments and replacement inside a cloned nested call.
- Added identity-based `AstRoutineCall::replaceArgument()` and connected it to
  `AstNodeReplacer`, following the existing sort-expression replacement pattern.
- Added regressions for direct/nested/repeated alias arguments, argument order,
  parent links, clone independence, retained return types, and invalid replacement.
- Added query-pipeline checks that alias arguments compile identically to explicit
  expressions for MySQL, PostgreSQL, and SQL Server.
- Validation: 307 relevant unit tests passed (726 assertions), including routine
  parser, analyzer, type checker, call, and dialect compiler tests. Tests used an
  isolated in-memory SQLite connection with metadata from the existing fixtures;
  target-engine SQL was compiled, not executed. No routines were deployed.
- PHPStan passed for both changed production files. No commit, push, or merge
  performed. Cross-engine execution remains part of phase 7.

**Objective:** Projection aliases and other transformed expressions work inside
routine-call arguments without unsupported-parent exceptions.

**Implementation:**

1. Trace alias expansion through `ExpandMacros`, `AstNodeReplacer`, and
   `AstRoutineCall`; reproduce an alias used directly as a call argument.
2. Add the smallest consistent argument-replacement operation and connect it to
   the existing replacement mechanism. Preserve argument order and parent links.
3. Check traversal and deep-clone behavior for nested calls and repeated aliases.
   Avoid restructuring unrelated AST nodes.

**Validation and acceptance:**

- Regression tests cover a direct alias argument, nested calls, multiple arguments,
  and independent cloned expressions.
- Existing alias-expansion and routine-call tests pass.
- Invalid replacement requests still fail explicitly.

**Inspection gate:** Report results and halt. Await explicit authorization for phase 2.

## Phase 2 - Make boolean value and predicate conversion consistent

Status: not started.

**Objective:** Boolean-valued scalar expressions and predicates compile correctly
in SQL Server condition and value positions.

**Implementation:**

1. Reproduce a boolean-returning routine used directly in a query condition.
   Inspect `BuildSqlFromAst`, `RoutineStatementCompiler`, and expression helpers.
2. Establish a consistent distinction between a scalar boolean value and a SQL
   predicate, using existing AST contracts and type information where possible.
3. Apply conversion at the appropriate rendering boundary for conditions and
   stored values, including logical operands. Preserve NULL behavior and operator
   precedence; check whether conversions duplicate evaluation of routine calls.
4. Account for routine-body calls whose return types are unavailable during
   offline compilation. Do not add a live catalog dependency merely to resolve
   them; identify any required language decision before changing semantics.

**Validation and acceptance:**

- Cover direct boolean calls, identifiers, literals, supported boolean casts,
  comparisons, AND/OR/NOT, and nullable results in relevant positions.
- Verify SQL Server output with execution tests where an isolated server is
  available, and report any execution checks that remain unavailable.
- Existing PostgreSQL and MySQL/MariaDB boolean behavior remains covered.

**Inspection gate:** Report results and halt. Await explicit authorization for phase 3.

## Phase 3 - Correct standalone retrieve discarding

Status: not started.

**Objective:** A standalone sorted retrieve inside a routine produces valid SQL
and preserves the intended execution semantics.

**Implementation:**

1. Reproduce the SQL Server derived-table restriction through
   `FetchIntoRoutineLowering::discardRetrieve()` and the SQL Server lowering.
2. Establish whether discarding rows must evaluate projected expressions,
   including routine calls, by consulting the language design and existing tests.
   Seek clarification if that requirement is unspecified and affects the solution.
3. Choose an engine-appropriate discard implementation. Removing irrelevant
   sorting is sufficient only if it preserves the established semantics; do not
   assume counting rows is equivalent to evaluating all projected values.
4. Preserve cursor ordering and queries whose ordering affects selected rows.

**Validation and acceptance:**

- Cover sorted and unsorted standalone retrieves, empty and multiple-row results,
  and aggregate/distinct queries supported in this context.
- Test expression evaluation if required by the established semantics.
- Cursor sorting tests continue to pass; generated SQL obeys the target engine's
  derived-table restrictions.

**Inspection gate:** Report results and halt. Await explicit authorization for phase 4.

## Phase 4 - Normalize datetime-valued routine expressions

Status: not started.

**Objective:** Comparisons and arithmetic treat known datetime-returning routines
consistently with datetime columns.

**Implementation:**

1. Reproduce a comparison between a datetime column and a typed routine call.
2. Review `NormalizeDateTime`, type resolution, parameter coercion, and datetime
   write conversion as one path. Extend recognition beyond identifiers where the
   expression's datetime type is known.
3. Preserve the distinction between native datetime values, Unix timestamps, and
   intervals. Avoid double wrapping and unintended changes to function arguments.
4. Keep unknown and ambiguous return types explicit; do not guess an overload's
   type or require a connected database for offline routine compilation.

**Validation and acceptance:**

- Cover both operand orders, two datetime-returning calls, literals, parameters,
  NULL, arithmetic, and writes back into datetime columns.
- Exercise existing timestamp and interval regressions.
- Inspect emitted SQL and run representative round-trip tests on available engines.

**Inspection gate:** Report results and halt. Await explicit authorization for phase 5.

## Phase 5 - Complete adapter ownership of routine introspection

Status: not started.

**Objective:** Routine executors no longer query database catalogs directly.

**Implementation:**

1. Move the existence lookup from `DestroyRoutineExecutor` behind
   `DatabaseAdapter` and its routine inspector.
2. Define existence semantics separately from callable-signature semantics:
   destruction may accept a name shared by a function and procedure, while a
   standalone call currently rejects that ambiguity.
3. Preserve `if exists`, missing-routine behavior, lookup-failure reporting,
   schema qualification, and metadata freshness.
4. Keep destruction SQL and execution policy in their existing compiler/executor
   layers. Reuse identifier quoting where practical without adding a dependency
   cycle or a broad catalog framework.

**Validation and acceptance:**

- Cover present, absent, ambiguous-kind, and failed-lookup cases, with and without
  `if exists` where applicable.
- Existing call-signature and destruction tests pass.
- Inspect production routine executors for remaining catalog SQL.

**Inspection gate:** Report results and halt. Await explicit authorization for phase 6.

## Phase 6 - Consolidate routine type state and compiler construction

Status: not started.

**Objective:** Validation and rendering consume consistent routine type information,
and compiler dependencies have clear ownership with less repeated setup.

**Implementation:**

1. Map ownership and initialization of variables, declared ranges, and cursor
   fields across `RoutineLowering`, `RoutineTypeChecker`, and
   `RoutineStatementCompiler`.
2. Establish one authoritative per-compilation type environment, or an explicit
   shared analysis result if that better fits the existing design. Preserve the
   ordering needed to prepare and infer cursor fields.
3. Eliminate redundant population and ensure state cannot leak between routine
   compilations, including after a failed compilation.
4. Consolidate materially duplicated compiler construction in executors and the
   routine compiler. Evaluate a small factory or explicit compilation context only
   where it removes real duplication; avoid a general service locator.
5. Preserve the separation between target-platform compilation and connected-engine
   metadata lookup. Keep schema lookup lazy and avoid introducing hidden database
   access in SQL rendering.

**Validation and acceptance:**

- Cover variables, cursor fields, declared ranges, and sequential compilations
  using overlapping names with different types.
- Verify compilation for a target engine different from the connected engine.
- Run the ObjectQuel suite and relevant static analysis; compare representative
  SQL outputs to ensure this phase does not change accepted behavior.

**Inspection gate:** Report results and halt. Await explicit authorization for phase 7.

## Phase 7 - Complete cross-engine execution coverage and final review

Status: not started.

**Objective:** Validate the accepted changes against actual supported engines and
make any remaining coverage gaps explicit.

**Implementation:**

1. Inspect the existing test bootstrap and CI setup. Confirm supported server
   versions and available isolated test databases before adding infrastructure.
2. Extend the integration setup to exercise PostgreSQL, SQL Server, MySQL, and
   MariaDB with shared behavioral scenarios and engine-specific setup/cleanup.
   Retain useful SQL-generation unit tests.
3. Cover deployment, replacement and destruction, calls and hydration, alias
   arguments, boolean predicates, datetime conversion, standalone retrieves,
   cursor ordering, and transaction behavior relevant to the changes.
4. Ensure tests clean up routines and data after failure. Keep unavailable-engine
   skips visible; skipped tests are not evidence that an engine passed.
5. Review the cumulative diff and all eight original findings, including changes
   introduced during inspection commits or merges. Run the complete ObjectQuel
   suite and required static checks in the configured environments.

**Validation and acceptance:**

- Report an engine/version matrix with passed, failed, skipped, or unavailable
  execution checks and the reason for each gap.
- Record the disposition of every original finding: resolved, disproved, or
  outstanding with evidence. Do not mark cross-engine verification complete while
  required engines remain untested.
- Confirm repository instruction compliance and an unchanged unrelated working tree.

**Final inspection gate:** Report the cumulative outcome and halt for final
inspection and any explicitly authorized commit, push, or merge. Do not perform
additional implementation automatically.
