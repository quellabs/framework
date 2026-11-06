# Canvas Authorization — Doctrine

Doctrine-flavored scaffolding for [`quellabs/canvas-authorization`](../canvas-authorization): a complete session-based login, registration, and password-reset flow for Canvas applications using Doctrine ORM.

## Installation

```bash
composer require quellabs/canvas-authorization-doctrine
```

Then scaffold the authentication system into your application:

```bash
vendor/bin/sculpt install:auth-doctrine
```

This generates `UserEntity`/`PasswordResetTokenEntity` (Doctrine attribute-mapped entities), `AuthenticationController`/`AuthenticatedController`, and a database-backed `AccountEligibilityAspect` that periodically revalidates the logged-in user, alongside the shared validators, notifier, error handler, and templates from `canvas-authorization`. Re-generate the aspect alone from a clean copy with `vendor/bin/sculpt make:auth-aspect-doctrine`.

## License

MIT
