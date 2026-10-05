# Code Style: `packages/canvas` and `packages/objectquel`

This describes the style observed in `packages/canvas/src` and `packages/objectquel/src`. It was derived by reading
about 20 representative files (controllers, validators, scheduler, cache driver, AOP aspect, planner optimizer and
visitor, annotations, enums, exceptions, the `EntityManager` facade, and a Sculpt command) plus repository-wide grep
counts. It is a description of what the code does, not a rulebook. Where the two packages differ, that is noted.

## Formatting

- **Indentation: tabs.** Not spaces. Continuation lines are indented one extra tab.
- **Blank lines inside indented blocks carry the indentation.** A blank line in a class body is a line containing only
  tabs, not an empty line. Keep this so diffs stay clean.
- **Opening braces on the same line** (K&R): `class Foo {`, `public function bar(): void {`, `if (...) {`. `else` and
  `catch` follow the closing brace on the same line.
- **Blank line between every member** of a class, including between properties and methods.
- **One blank line** (tab-indented) after the `class {` line in most files, and before the closing `}`.
- **Opening PHP tag, then a blank line, then `namespace`**, then `use` block, then one blank line, then the class
  docblock.
- **Multi-line signatures** when there are several parameters, one parameter per line, with names aligned and defaults
  aligned, closing `) {` on its own line:
  ```php
  public function __construct(
  	JobStorageInterface $storage,
  	Container           $container,
  	string              $tasksPath,
  	?LoggerInterface    $logger = null
  ) {
  ```
- **Multi-line conditions** with the operator at the end of each line and the closing `) {` on its own line
  (`JoinOptimizer::optimize`).
- **Ternaries** may span lines with `?` and `:` leading the continuation lines (`EntityManager::executeQuery`).
- **Aligned assignments** appear in constructors and property lists (`$this->storage   = $storage;`). Not every class
  does this.
- **Multi-line array literals** put each key on its own line, with `=>` aligned in some files (`FileCache::getStats`)
  and not in others.
- **No `declare(strict_types=1)`** in either package (canvas: 0 files; objectquel: 3 files, all Digest).
- Heredoc is used for long help text (`MakeEntityCommand::getHelp`), closing `HELP;` at column 0.
- Single quotes for plain strings, double quotes when interpolating (`"Starting task: {$taskName}"`). Interpolation uses
  braces: `{$var}`, `{$e->getMessage()}`.

## Comments and docblocks

This is the most distinctive part of the style.

### Docblocks

- **Every method has a docblock**, even trivial getters. Typically a one-line summary, then `@param` and `@return`
  lines. No blank line between the summary and the tags.
  ```php
  /**
   * Returns the table name
   * @return string
   */
  ```
- **No period at the end of the summary line** in most docblocks. Multi-sentence summaries do end with periods.
- **`@param` lines use `type $name Description`**, with no alignment and no trailing period.
- **Array and generic types go in docblocks** even when native types exist: `@param array<string, mixed> $data`,
  `@return list<string>`, `@return array<TaskResult>`, `@param-out`, `@phpstan-import-type`.
- **Class docblocks are long when the class is non-trivial.** They list features or failure modes as bullets, one
  behavior per line (`CsrfProtectionAspect`, `MakeEntityCommand`). Architectural contracts are stated explicitly
  (`JoinOptimizer`: "Architectural assumptions:").
- **Property docblocks**: either `/** @var type Description */` on one line, or `/** Description */` with no tag. Canvas
  and objectquel both do this.
- Constructors use `/** Constructor ... */` or `/** Initialize ... */` with `@param` lines.

### Inline comments

- **Section markers are allowed.** A `//` line may mark a logical section as a visible boundary before a group of
  statements, even when it adds little information. Keep each one to a single short line.
- **Other comments follow `CLAUDE.md`**: one short line for non-obvious rationale, no multi-paragraph blocks, no history.
- **Comments are sentence-case, no trailing period** in most cases, and describe what the next block does or, more
  often, why it does it.
- **"Why" comments are common and specific**: they name the failure mode being prevented
  (`// This prevents handler failures from masking the original error`,
  `// This creates a distributed lock across multiple scheduler instances`).
- **Comments mark non-obvious branches**: `// Fast path`, `// Slow path`, `// Double-check cache after acquiring lock`.
- **Warnings for suppressed errors**: when `@` is used, the line above says why
  (`// Use @ to suppress warnings for concurrent directory creation`).
- **Named-section banners** are not used in the sampled files. The ASCII-art banners in `EntityManager.php` and
  `DirtyState.php` are a one-off header style, not a convention.

Comment density is governed by `CLAUDE.md`, with the section-marker exception above. See the last section.

## Types

- **Typed properties everywhere**: `private Container $container;`, `private ?EntityManager $entityManager = null;`.
- **Typed parameters and return types everywhere**, including `: void`, `: ?Response`, `: mixed`, `: static` where
  relevant.
- **Union and nullable types** use native syntax: `object|array|null`, `array|\JsonSerializable`, `?LoggerInterface`.
- **No constructor property promotion.** Constructor parameters are legacy style: declare each property explicitly
  with a docblock and assign it in the constructor body. Exception classes in objectquel still use promotion
  (`QuelException`); do not copy that into new code.
- **Fully qualified global classes** with a leading backslash in code (`new \RuntimeException`, `\Throwable`,
  `\InvalidArgumentException`, `\DateTime`). Imports (`use`) are used for project and vendor classes.
- **Named arguments** are used at call sites where they clarify (`new CronExpression(...)` is not;
  `hasNullChecks: $nullCheckVisitor->isFound()` is).
- **PHPStan level 9** is configured (`phpstan.neon`) and the docblocks are written for it. Expect precise generic
  docblocks on arrays.

## Naming

- **Classes**: PascalCase. Suffixes are meaningful:
    - `...Interface` for every contract (`JobStorageInterface`, `ValidationRuleInterface`, `CacheInterface`).
    - `Abstract...` for base classes (`AbstractTask`, `AbstractMigration`).
    - `...Aspect` for AOP aspects (`CsrfProtectionAspect`, `TrackingParamsAspect`).
    - `...Exception` for exceptions (`RouteNotFoundException`, `QuelException`).
    - `...Command` for Sculpt commands; `...Base` for shared command bases (`MakeCommandBase`).
    - `...Controller` for controllers (`BaseController`, `SecureController`).
    - `...Visitor` / `Visitors/` for AST visitors; `Optimizers/` for planner optimizers; `Strategy...` for
      interchangeable runtime strategies (`StrategyPcntl`, `StrategyTimeout`).
    - `...Factory` for static factories (`TaskRunnerFactory::create()`).
- **Methods**: camelCase. Verb-first. Conventions:
    - `get...` / `set...` for accessors.
    - `is...` / `has...` for booleans (`isExpired`, `hasNullChecks`, `isNestedFieldStructure`).
    - `validate...`, `build...`, `resolve...`, `collect...`, `analyze...`, `run...`, `write...`, `read...` for actions.
- **Properties and variables**: camelCase, descriptive. Avoid abbreviations except conventional ones (`$em`, `$ast`,
  `$e`, `$ttl`).
- **Constants**:
    - Canvas uses `UPPER_SNAKE` for class constants (`DEFAULT_TOKEN_NAME`).
    - Objectquel enums (`DirtyState`, `BasicEnum` subclasses) use `const int PascalCase` (`Dirty`, `NotManaged`). This
      is an intentional exception in objectquel, not a typo.
- **Namespaces** mirror directories, with sub-namespaces for concern grouping: `Contracts/`, `Foundation/`, `Helpers/`,
  `Drivers/`, `Strategies/`, `Components/`, `Internal/`.
- **Descriptive and long names over short ones** for anything that isn't a loop index: `$handledRanges`,
  `$collectedNodes`, `$exemptMethods`, `$traverseSubqueries`.

## Structure and control flow

- **Guard clauses and early returns.** Nested `if` pyramids are rare. The sampled code flattens with `continue`,
  `return null`, and `throw` at the top.
- **Small focused private methods** with one job each, typically with a docblock. Public methods orchestrate; private
  ones do the work (`Scheduler::run` → `runTask` → `handleTaskFailure`).
- **Visitor and strategy patterns** appear repeatedly in objectquel (`AstVisitorInterface::visitNode`,
  `FoldingRuleInterface`). Prefer a new visitor class over adding a branch to an existing one.
- **Single-purpose helper classes** over large god-classes, but `UnitOfWork`, `BuildSqlFromAst`, `RelationshipLoader`,
  `ReflectionHandler` are very large. The style does not forbid big files; it forbids ambiguous responsibilities.
- **Null-safe calls** `?->` are used for optional collaborators (`$this->debugQuerySignal?->emit(...)`,
  `$result?->recordCount() ?? 0`).
- **`isset()` for typed-property checks in destructors** where a constructor may have been bypassed
  (`EntityManager::__destruct`).
- **Ternary** only for short, single-expression choices. Longer choices use `if`.
- **Loops iterate over collections with `foreach`**, with `continue` for skips. Each skip is preceded by a comment
  explaining why the skip is valid.

## Function length

Measured across the 646 PHP files in both `src` trees (3,605 methods with a body, counted from the method signature to its closing brace):

| Lines | Methods | Share |
|---|---|---|
| median | 6 | |
| 90th percentile | 31 | |
| 99th percentile | 77 | |
| over 30 | 380 | ~11% |
| over 60 | 73 | ~2% |
| over 100 | 19 | ~0.5% |

- **Target: most methods under about 30 lines.** Methods over 60 lines are the exception and need a reason.
- **The long methods are almost all one of three kinds:**
  - Embedded assets: a method returning a large JS, CSS, or HTML heredoc (`WakaPACPanel::getJsTemplate`, 934 lines; `Registry::getBaseJs`, 219 lines). The length comes from the payload, not from logic. Keep the payload in its own file or heredoc and keep the method a one-line return.
  - Dispatch or generation: a long method that emits code or SQL for many cases (`ConditionEvaluator::evaluate`, 298 lines; `PacJSGenerator::buildJavaScriptCode`, 266 lines; `ProxyCodeGenerator::makeProxyMethods`, 147 lines).
  - Data tables: a constructor holding a large literal array (`Zipcode::__construct`, 266 lines).
- **Outside those kinds, split the method.** The style achieves short methods by moving each step into a private method with a docblock, as in `Scheduler::run` → `runTask` → `handleTaskFailure`.
- **A `match` over dialects is allowed to be long** when each arm is a single expression.

## String formatting

Three mechanisms are in use. Pick by what the string is:

- **Double quotes with `{$var}` interpolation** for short messages and log lines: `"Starting task: {$taskName}"`, `"Failed to look up routine '{$name}': ..."`.
- **Concatenation with `.`** for SQL fragments assembled from pieces that are each already quoted or escaped (`'DROP INDEX ' . $this->identifierQuoter->quoteIdentifier(...)`). Used for conditional clauses: `$sql .= ' OFFSET 0 ROWS';`.
- **`sprintf` with `%s` placeholders** when a template has several slots and reads better as one line (`sprintf('ALTER TABLE %s ADD COLUMN %s DEFAULT %s', ...)` in `QuelToSQLAlter`).
- **`implode(', ', ...)`** for joining lists.
- **Heredoc** (`<<<HELP`, `<<<PHP`) only for multi-line text: Sculpt help output and generated PHP source. Not used for SQL.

Within one file, `.` concatenation, `sprintf`, and interpolation can all appear. Do not treat any one of them as a file-level rule.

### SQL

General rules (apply to any package that writes SQL, including recommender):

- **Keep SQL keywords uppercase.**
- **Pass values as named parameters, never interpolate them into SQL.** Placeholders are `:name`, and the array passed to `execute($sql, $params)` is keyed by the name without the colon. Each placeholder occurrence gets its own name (`:member`, `:member2`).
- **Multi-line queries are single-quoted strings.** The opening quote ends the `execute(` line, `SELECT` sits alone on the next line, and each column gets its own indented line. Clauses follow at the `SELECT` indent, one condition per line with `AND` at the end. The params array follows the closing quote:

  ```php
  $rows = $this->connection->execute('
      SELECT
          product_id,
          rating
      FROM vogoo_ratings
      WHERE member_id = :member AND
            category = :category
  ', [
      'member' => $memberId,
      'category' => $category,
  ])->fetchAll('assoc');
  ```

- **Interpolate only identifiers.** Table names that vary at runtime (temporary tables) and generated `IN (...)` placeholder lists are the only interpolated parts. Those queries use double quotes, and their values still bind as parameters.
- **Hardcode table and column names as written** in the query string rather than building them from variables. Recommender does this with backtick-quoted names (`` `vogoo_ratings` ``).
- **Append optional clauses with `.=`** (`LIMIT`, allowlist predicates) and keep the base query as a literal.

## Error handling

- **Exceptions are thrown for programmer errors and invariant violations**, with a message that names the offending
  value or field: `"Table annotation requires a valid 'name' parameter"`,
  `"Invalid validator for field '{$fieldName}'. Expected ValidationRuleInterface, got {$type}"`.
- **Wrap and re-throw with the cause**:
  `new \RuntimeException("Validator {$class} failed for field '{$fieldName}': {$e->getMessage()}", 0, $e)`.
- **Domain exceptions carry a machine-readable type** where callers branch on it (`QuelException::$type`, compared as
  `$e->type !== 'not_plannable'`).
- **Failures in side paths are caught and logged, not rethrown** (`Scheduler::handleTaskFailure`,
  `BaseController::render` logs then rethrows). Look at each case: logging-and-continue is used when the main result
  must not be masked.
- **`@` suppression** is used sparingly, always with a comment, usually around filesystem calls that race (`@unlink`,
  `@mkdir`).
- **Return `false`/`null` for expected misses** (`readCacheFileWithLock` returns `null` for a missing file). Throw for
  unexpected states.
- **`finally` for cleanup** that must always run (lock release, handle close, storage `markAsDone`).
- **No silent catch-all**: `catch (\Throwable $e)` is only used when rethrowing with context.

## Idioms observed

- **Config and options as arrays** with `$config['key']` checks and typed defaults (`FileCache::__construct`).
- **Return shapes documented in docblocks** rather than typed as classes, when the shape is a plain array
  (`array<string, mixed>`, `list<string>`).
- **Result objects** for operations that report outcome (`TaskResult`, `JobResult`, `QuelResult`).
- **Attribute-like annotations** for ORM and routing metadata (`@Orm\Table`, `@Route`, `@ListenTo`), implemented as
  classes with an `@Annotation` docblock marker and an `AnnotationInterface`.
- **Dependency injection through the constructor**, with `Container` passed in and services resolved via
  `$this->container->get(Class::class)` in convenience accessors (`BaseController::em()`).
- **Logger default**: `$logger ?? new NullLogger()` instead of optional logging.
- **Sculpt commands** (objectquel) expose `getSignature(): string`, `getDescription(): string`, `getHelp(): string`
  (heredoc), `execute(ConfigurationManager $config): int`. Confirmed in `MakeEntityCommand`; other commands were not
  read.
- **String building** with interpolation for short strings, `sprintf` for formatted numbers (`FileCache::formatBytes`).

## Differences between the two packages

| Aspect                | canvas                 | objectquel                                                   |
|-----------------------|------------------------|--------------------------------------------------------------|
| Indentation           | tabs                   | tabs                                                         |
| Class banners         | none                   | ASCII-art banner in `EntityManager`, `DirtyState`            |
| Enum-like classes     | not read               | `BasicEnum` with `const int PascalCase`                      |
| Constants             | `UPPER_SNAKE`          | `UPPER_SNAKE` for class constants; enum members PascalCase   |
| Constructor promotion | not seen in read files | used in exception classes; not to copy                                    |
| Named arguments       | not seen in read files | used in planner code (`JoinOptimizer::analyzeConditions`)    |
| Large files           | fewer in sampled files | many (`UnitOfWork`, `BuildSqlFromAst`, `RelationshipLoader`) |

## Conflicts with `CLAUDE.md`

Resolved. `CLAUDE.md` governs where the two disagree. The exceptions are noted below.

1. **Comment density.** `CLAUDE.md` governs: one short line for non-obvious rationale, no multi-paragraph blocks. The
   exception is section markers: a `//` line may mark a logical section as a visible boundary, even when it adds
   little information.
2. **Docblocks on every method and property.** Both agree. Every method and property gets a docblock.
3. **Docblock summaries.** `CLAUDE.md` governs: a short summary, with `@param` descriptions kept to one line.
4. **Banners and historical comments.** `CLAUDE.md` governs: no history. Do not copy the "why this changed" notes or
   `objectquel-append-plan.md` references from `EntityManager::executeQuery`.
5. **Constructor `@return`.** Constructors have no return value, so they omit `@return`. This is not a conflict.
6. **Constructor parameters.** Legacy style: no constructor property promotion. See the Types section.

## Not covered

- Test code (`tests/`) was not read. Test style may differ.
- `packages/canvas-*` and `packages/objectquel-*` sub-packages were not read beyond their entry points.
- Non-PHP files (templates, JS) are out of scope.
