# Canvas Authorization

Shared, ORM-agnostic building blocks for session-based authentication in Canvas applications. `SessionAuthenticationAspect` validates a logged-in session before a controller method runs, and the package carries the login/registration/password-reset validators, notifier, error handler, and templates that every ORM-specific install command reuses.

This package has no scaffolding command of its own — install one of the ORM-flavored packages instead, depending on the ORM your project uses:

- [`quellabs/canvas-authorization-doctrine`](../canvas-authorization-doctrine)
- [`quellabs/canvas-authorization-eloquent`](../canvas-authorization-eloquent)
- [`quellabs/canvas-authorization-objectquel`](../canvas-authorization-objectquel)

## Installation

```bash
composer require quellabs/canvas-authorization
```

Pulled in automatically as a dependency of any of the three packages above.

## License

MIT
