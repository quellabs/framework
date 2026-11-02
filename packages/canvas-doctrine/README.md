# Canvas Doctrine

Doctrine ORM integration for Canvas. Install with `composer require quellabs/canvas-doctrine`, then run `sculpt doctrine:init` and edit `config/doctrine.php`.

The configuration defines one connection under `connection`. Set `entity_paths` to directories containing Doctrine attribute mapped entities, and set `entity_namespace` (default `App\Entities`) for controller route binding. Inject `Doctrine\ORM\EntityManagerInterface` or `Doctrine\DBAL\Connection` through Canvas DI.

When Doctrine uses generated proxy classes, Canvas stores them under `storage/doctrine/proxies` in the project root and generates missing proxies on demand. PHP 8.4 with Doctrine ORM 3.5+ uses native lazy objects instead and does not generate proxy files.

Controller parameters typed as entities in the configured namespace bind to the route parameter with the same name. Use `#[ResolveEntity(parameter: 'post', routeParam: 'postId')]` on the controller method to use a different route key. Missing records raise `ResolutionNotFoundException`; missing or nonscalar route values are configuration errors. Composite identifiers are not supported by route binding.

Do not register `canvas-doctrine` and `canvas-objectquel` together with overlapping `entity_namespace`/entity path configuration. Route-entity binding matches by namespace only, so a class belonging to the other ORM can be bound to the wrong EntityManager.

## Migrations

Migrations wrap [doctrine/migrations](https://www.doctrine-project.org/projects/migrations.html) rather than reimplementing it. Set `migrations_path` (default `migrations/`, a project-root-relative directory committed to the repo, unlike `storage/`), `migrations_namespace` (default `App\Migrations`), and `migrations_table` (default `doctrine_migrations`) in `config/doctrine.php`.

- `sculpt doctrine:make:migrations` — generates a migration from the difference between the mapped entities and the current schema
- `sculpt doctrine:make:blank-migration` — generates a blank migration for hand-written changes
- `sculpt doctrine:migrate [version] [--dry-run] [--force]` — runs pending migrations up to `version` (default `latest`); pass a version below the current one to roll back. Prompts for confirmation unless `--force` is passed
- `sculpt doctrine:migrate:status` — shows applied and pending migrations

Applied migrations are tracked in the configured `migrations_table`.
