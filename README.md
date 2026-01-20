# Canvas Database

CakePHP Database integration for Canvas framework.

## Installation

```bash
composer require quellabs/canvas-database
```

## Usage

The package provides a service provider that registers `Cake\Database\Connection` with Canvas's dependency injection container.

### Basic Usage

```php
use Cake\Database\Connection;

class MyController {
    public function __construct(
        private Connection $db
    ) {}
    
    public function index() {
        $results = $this->db->execute('SELECT * FROM users')->fetchAll('assoc');
    }
}
```

### Configuration

Add database configuration to your Canvas config file:

```php
return [
    'database' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'database' => 'myapp',
        'username' => 'root',
        'password' => '',
        'port'     => 3306,
        'encoding' => 'utf8mb4',
    ]
];
```

## License

MIT