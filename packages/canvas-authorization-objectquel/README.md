# Canvas Authorization — ObjectQuel

ObjectQuel-flavored scaffolding for [`quellabs/canvas-authorization`](../canvas-authorization): a complete session-based login, registration, and password-reset flow for Canvas applications using ObjectQuel.

## Installation

```bash
composer require quellabs/canvas-authorization-objectquel
```

Then scaffold the authentication system into your application:

```bash
vendor/bin/sculpt install:auth-objectquel
```

This generates `UserEntity`/`PasswordResetTokenEntity` (ObjectQuel entities) and `AuthenticationController`/`AuthenticatedController`, alongside the shared validators, notifier, error handler, and templates from `canvas-authorization`. Unlike the Doctrine and Eloquent variants, the database-backed `AccountEligibilityAspect` is not copied into your application — it's used directly from this package, configured via `userEntityClass`, and receives fixes through `composer update`.

## License

MIT
