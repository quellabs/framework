# Codex Repository Instructions

Before working in this repository, read and follow [CLAUDE.md](CLAUDE.md)
in this directory. It contains the shared assistant behavior and code
documentation instructions for both Claude and Codex and applies throughout
this repository.

Keep shared instructions in CLAUDE.md so both assistants use the same rules.

## Code style

Follow [CODE_STYLE.md](CODE_STYLE.md) for all PHP, including `packages/recommender`. Key rules:

- Keep methods under 60 lines. Split longer methods into small private methods, each with a docblock.
- Before adding a helper, search with Grep for an existing one and reuse it. Do not duplicate methods across classes.
- Do not use constructor property promotion.

## Testing

Before considering a task complete:

- Independently identify all direct and indirect behavior that may be affected by the change. 
  Do not limit testing to the files or behavior explicitly mentioned in the task.
- Run the relevant existing tests for all affected paths.
- Add or update tests when behavior changes.
- Consider edge cases and regression scenarios, not only the happy path.
- Run the broader related test suite when the change can affect adjacent behavior.
- Do not report completion while relevant tests are failing.
- For changes under `packages/recommender`, run
  `php vendor/bin/phpmd analyze packages/recommender/src --ruleset phpmd.recommender.xml`
  and do not add findings. The 13 findings present when this rule was added are pre-existing.