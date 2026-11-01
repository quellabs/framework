# Canvas Doctrine

Doctrine ORM integration for Canvas. Install with `composer require quellabs/canvas-doctrine`, then run `sculpt doctrine:init` and edit `config/doctrine.php`.

The configuration defines one connection under `connection`. Set `entity_paths` to directories containing Doctrine attribute mapped entities, and set `entity_namespace` (default `App\Entities`) for controller route binding. Inject `Doctrine\ORM\EntityManagerInterface` or `Doctrine\DBAL\Connection` through Canvas DI.

Controller parameters typed as entities in the configured namespace bind to the route parameter with the same name. Use `#[ResolveEntity(parameter: 'post', routeParam: 'postId')]` on the controller method to use a different route key. Missing records raise `ResolutionNotFoundException`; missing or nonscalar route values are configuration errors. Composite identifiers are not supported by route binding.
