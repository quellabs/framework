# Canvas Eloquent

Eloquent ORM integration for Canvas framework.

## Installation

```bash
composer require quellabs/canvas-eloquent
```

## Usage

The package registers two service providers. `ServiceProvider` registers an Eloquent
`Illuminate\Database\ConnectionInterface` with Canvas's dependency injection container.
`ModelBindingServiceProvider` resolves a controller action parameter typed as a concrete Eloquent model from
the current route (see [Model Parameter Binding](#model-parameter-binding) below), relying on the connection
`ServiceProvider` already booted. The two are independent: injecting the connection gives you the query
builder for raw queries, while model binding resolves one specific model instance for the action being
invoked.

```php
use Illuminate\Database\ConnectionInterface;

class MyController {
    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db) {
        $this->db = $db;
    }

    public function index() {
        $results = $this->db->table('users')->get();
    }
}
```

## Model Parameter Binding

A controller action parameter typed as a concrete `Illuminate\Database\Eloquent\Model` subclass is
resolved automatically from a route value, without calling `Model::find()` yourself.

### Implicit binding by parameter name

By default, the route value with the same name as the controller parameter is used as the primary key:

```php
// Route: /posts/{post}
public function show(Post $post) {
    // $post is the Post whose primary key matches the route's {post} value
}
```

### `@ResolveModel` for a differently-named route value

When the route value's name differs from the controller parameter's name, add `@ResolveModel` to map them:

```php
/**
 * @Route('/posts/{postId}')
 * @ResolveModel(parameter="post", routeParam="postId")
 */
public function show(Post $post) {
    // $post is resolved from the route's {postId} value
}
```

The same mapping can be declared as a native PHP attribute instead of a docblock annotation:

```php
#[Route('/posts/{postId}')]
#[ResolveModel(parameter: 'post', routeParam: 'postId')]
public function show(Post $post) {
    // $post is resolved from the route's {postId} value
}
```

### Not-found behavior

- **Required parameter** (`Post $post`): if the lookup finds no row, the request fails with an HTTP 404.
- **Nullable parameter** (`?Post $post`): if the lookup finds no row, the controller receives `null` and must handle it.

### Binding errors vs. a missing row

A missing row is not the only possible outcome. If the route has no value at all for the key the parameter
is bound to, or the value's shape is not supported (this provider only binds scalar primary keys —
composite/array keys are not supported), that is a binding or configuration mistake, not "no row found",
and raises a server error (HTTP 500) identifying the controller, parameter, and route key involved rather
than silently injecting `null` or a default.

## Configuration

Publish the config file with `sculpt eloquent:init`, then edit `config/eloquent.php`:

```php
return [
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'database' => 'myapp',
    'username' => 'root',
    'password' => '',
    'port'     => 3306,
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'   => '',
];
```

## Testing

Run the package unit tests from this directory with `composer test` after installing
its development dependencies. In the framework monorepo, run
`..\..\vendor\bin\phpunit.bat` from this directory on Windows, or
`../../vendor/bin/phpunit` on Unix. The framework's general PHPUnit suite also
runs these tests and the request dispatch integration tests.

## Status

This package is under active development.

## License

MIT
