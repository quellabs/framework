# Codex Repository Instructions

Before working in this repository, read and follow [CLAUDE.md](CLAUDE.md)
in this directory. It contains the shared assistant behavior and code
documentation instructions for both Claude and Codex and applies throughout
this repository.

Keep shared instructions in CLAUDE.md so both assistants use the same rules.

## Testing

Before considering a task complete:

- Independently identify all direct and indirect behavior that may be affected by the change. 
  Do not limit testing to the files or behavior explicitly mentioned in the task.
- Run the relevant existing tests for all affected paths.
- Add or update tests when behavior changes.
- Consider edge cases and regression scenarios, not only the happy path.
- Run the broader related test suite when the change can affect adjacent behavior.
- Do not report completion while relevant tests are failing.