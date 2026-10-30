# Canvas Eloquent

Eloquent ORM integration for Canvas framework.

## Installation

```bash
composer require quellabs/canvas-eloquent
```

## Usage

The package provides a service provider that registers an Eloquent `Illuminate\Database\ConnectionInterface`
with Canvas's dependency injection container.

```php
use Illuminate\Database\ConnectionInterface;

class MyController {
    public function __construct(
        private ConnectionInterface $db
    ) {}

    public function index() {
        $results = $this->db->table('users')->get();
    }
}
```

### Configuration

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

## Status

This package is under active development.

## License

MIT
