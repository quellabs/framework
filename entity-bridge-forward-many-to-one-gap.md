# EntityBridge: a child entity's own parent is always lazy — by design

Status: implemented 2026-09-03 (`QueryBuilder::addForwardBridgeRanges()`). Originally documented
for reference, not a bug report, on 2026-08-26.

## Context

`@Orm\EntityBridge` (implemented in `260ec3bc`, LAZY-skip test coverage in `73c008f9`) lets
`QueryBuilder` eager-join one hop further through a linking/junction entity's own relations,
when that linking entity is reached as a **dependent** of the entity being queried — i.e. when
the linking entity owns a `ManyToOne`/owning-`OneToOne` that points *at* the entity being
queried (`main`). Example: `find(Post::class, $id)` eager-joins `PostTag` (a dependent of
`Post`, via `Post::$postTags` `InverseOf`), and because `PostTag` is `@Orm\EntityBridge`,
`QueryBuilder` adds one more hop to eager-join `Tag` off `PostTag`'s own `ManyToOne`.

## The question this started from

What happens if some entity `X` itself owns a `ManyToOne` pointing *at* a bridge entity
(e.g. `AuditLog::$postTag` → `PostTagEntity`, `AuditLog` holds the FK column) and you query
`X` directly? Does the bridge's extra hop apply there too?

Answer: no — and not because of a missed case, but because of how the whole feature is built.

## The actual design (confirmed against the code, not an inconsistency to justify)

ObjectQuel's eager-load machinery only ever knows about the **owning** side of a relation.
`QueryBuilder::getRelationRanges($entityType)` (`packages/objectquel/src/Execution/
QueryBuilder.php:243`) works in exactly one direction:

1. `EntityStore::getDependentEntities($entityType)` finds every entity that **owns** a
   `ManyToOne`/owning-`OneToOne` pointing *at* `$entityType` — the child side.
2. For each such child, `addRanges()` reads `getFetch()` **on that child's own relation
   annotation** and, if `EAGER`, joins the child into the parent's query
   (`via main.{inverseProperty}`).

So `fetch` is a property of the child's declared relation, and it only ever answers one
question: *"when my parent is fetched, should I be pulled in too?"* There is no second loop that
inspects `$entityType`'s own outgoing `ManyToOne`/`OneToOne` properties when `$entityType` is
itself the child being fetched — `getRelationRanges()` never looks at what `main` points to,
only at what points at `main`. Grepping every call site of `getFetch()` in
`packages/objectquel/src` turns up exactly two, both inside this one child-walk.

Consequence: fetching a child entity directly *always* lazy-loads its own parent
(`RelationshipLoader::createAndSetProxy()`), regardless of `fetch`. That's not `fetch` silently
being ignored on the child's own relation — it's that no code path ever reaches `getFetch()` in
that direction at all. One design, one direction, not two directions where one happens to be
unimplemented.

`EntityBridge`'s extra hop fits inside this same single direction: it only fires from *within*
the child-walk (`addRanges()` → `addBridgeExpansionRanges()`), after a bridge has already been
joined in as someone's child. It never runs when the bridge itself is what a *further* child
(`AuditLog`) points at — that would require the reverse direction this design doesn't have.

## If this is ever picked up: scoped estimate (bridge-only, ~half a day)

Not proposed as a general fix to "parent is always lazy" — that's the intended design and stays
as-is. This would only add one narrow, bridge-specific case: when the entity being fetched (`X`)
owns a `ManyToOne`/`OneToOne` whose target `isEntityBridge()`, eager-join that bridge in too
(subject to `fetch`), then extend one hop further exactly like the existing child-side case does.

- `QueryBuilder::getRelationRanges()` gets a second loop, parallel to the existing
  `getDependentEntities()` walk, over `$entityType`'s own owning `ManyToOne`/owning-`OneToOne`
  relations. For each non-`LAZY` one whose target `isEntityBridge()`, add
  `range of {alias} is {target} via main.{property}`, then call the already-existing
  `addBridgeExpansionRanges($target, $alias, $entityType, ...)` unchanged. ~1–2 hrs.
- The join-rewrite side needs no changes: `RewriteViaRelationToJoinCondition::
  buildJoinConditionFromRelation()` (`packages/objectquel/src/ObjectQuel/Visitors/
  RewriteViaRelationToJoinCondition.php:164`) already resolves a direct (non-`InverseOf`)
  `ManyToOne`/`OneToOne` via-clause generically for any alias, including `main` — it's the exact
  code path `addBridgeExpansionRanges()` already exercises for non-`main` aliases.
- New fixture (an entity owning a `ManyToOne` into a bridge) + ~3 tests mirroring
  `EntityBridgeEagerLoadTest`: query-shape hop-added test, end-to-end eager-hydration test, and a
  `LAZY`-skip control test. ~2–3 hrs.
- Low technical risk — the hard part (join-rewrite handling a non-`main`-anchored via-clause) is
  already proven, working code, not new ground.
- No action needed unless a real use case surfaces — filed here so the analysis doesn't need to
  be redone.
