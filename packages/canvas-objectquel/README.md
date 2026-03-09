# Canvas ObjectQuel Integration

![Canvas ObjectQuel Logo](https://placeholder-for-logo.png)

[![Latest Version](https://img.shields.io/packagist/v/quellabs/canvas-objectquel.svg)](https://packagist.org/packages/quellabs/canvas-objectquel)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/canvas-objectquel.svg)](https://packagist.org/packages/quellabs/canvas-objectquel)

The Canvas ObjectQuel Integration package provides seamless integration between [ObjectQuel ORM](https://github.com/quellabs/objectquel) and the Canvas PHP framework. This package includes service discovery for dependency injection and Sculpt CLI commands for entity management.

## Table of Contents

- [Installation](#installation)
- [Features](#features)
- [Configuration](#configuration)
- [Service Discovery](#service-discovery)
- [Sculpt Commands](#sculpt-commands)
- [Quick Start](#quick-start)
- [Configuration Reference](#configuration-reference)
- [Usage Examples](#usage-examples)
- [Support](#support)
- [License](#license)

## Installation

Install the package via Composer:

```bash
composer require quellabs/canvas-objectquel
```

The package requires:
- PHP 8.2 or higher
- Canvas PHP framework
- ObjectQuel ORM (>=1.0)

## Features

### 🔌 **Automatic Service Discovery**
- Automatic registration of ObjectQuel EntityManager with Canvas DI container
- Singleton pattern implementation for optimal performance
- Configuration-driven setup with sensible defaults

### 🛠️ **Sculpt CLI Integration**
- Entity generation commands integrated with Canvas Sculpt
- Database migration management
- Entity-from-table generation
- Phinx configuration automation

### ⚙️ **Framework Integration**
- Seamless Canvas framework integration

## Configuration

### Database Configuration

During installation, the package automatically copies `config/database.php` to your Canvas config directory.
Edit this file to configure your database connection.

## Service Discovery

The package automatically registers ObjectQuel services with Canvas's dependency injection container through two service providers:

### EntityManager Service Provider

Located in `Quellabs\Canvas\ObjectQuel\Discovery\ObjectQuelServiceProvider`, this provider:

- Registers the ObjectQuel EntityManager as a singleton service
- Automatically configures the EntityManager using your database configuration
- Provides dependency injection support for controllers and services

```php
// The EntityManager is automatically available in your Canvas application
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Canvas\Annotations\Route;

class ProductController extends BaseController {
    
    /**
     * @Route('/')
     */
    public function index() {
        $products = $this->em()->findBy(ProductEntity::class, [
            'active' => true
        ]);
        
        return $this->render('products.tpl', compact('products'));
    }
}
```

### Sculpt Service Provider

Located in `Quellabs\Canvas\ObjectQuel\Sculpt\ServiceProvider`, this provider registers ObjectQuel CLI commands with the Sculpt framework.

## Sculpt Commands

The package provides several CLI commands for entity and database management:

### Generate Entity

Create a new entity class interactively:

```bash
php bin/sculpt make:entity
```

This command will prompt you to:
- Enter the entity name
- Define properties and their types
- Set up relationships
- Generate getters and setters

### Generate Entity from Database Table

Create an entity from an existing database table:

```bash
php bin/sculpt make:entity-from-table
```

This command will:
- List available database tables
- Generate an entity class based on the selected table schema
- Include proper annotations and relationships

### Generate Migrations

Create database migrations from your entity changes:

```bash
php bin/sculpt make:migrations
```

This command:
- Analyzes differences between entities and database schema
- Generates Phinx migration files
- Includes index and constraint changes

### Run Migrations

Execute pending database migrations:

```bash
php bin/sculpt quel:migrate
```

Additional migration options:

```bash
# Roll back the last migration
php bin/sculpt quel:migrate --rollback

# Roll back multiple migrations
php bin/sculpt quel:migrate --rollback --steps=3

# Get help with migration commands
php bin/sculpt help quel:migrate
```

### Generate Phinx Configuration

Create a Phinx configuration file for advanced migration management:

```bash
php bin/sculpt quel:create-phinx-config
```

## Quick Start

### 1. Install and Configure

```bash
# Install the package
composer require quellabs/canvas-objectquel

# Configure your database in config/database.php
```

### 2. Create Your First Entity

```bash
# Generate a new entity
php bin/sculpt make:entity

# Follow the prompts to create a Product entity
```

### 3. Create and Run Migrations

```bash
# Generate migrations for your new entity
php bin/sculpt make:migrations

# Apply the migrations to your database
php bin/sculpt quel:migrate
```

### 4. Use in Your Application

```php
<?php

namespace App\Controllers;

use Quellabs\ObjectQuel\EntityManager;
use App\Entity\ProductEntity;
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Canvas\Annotations\Route;

class ProductController extends BaseController {
    
    /**
     * @Route('/products/create', methods={['POST']})
     */
    public function create() {
        $product = new ProductEntity();
        $product->setName('New Product');
        $product->setPrice(29.99);
        
        $this->em()->persist($product);
        $this->em()->flush();
        
        return $this->redirect('/products');
    }
    
    /**
     * @Route('/products')
     */
    public function index() {
        $products = $this->em()->executeQuery("
            range of p is App\\Entity\\ProductEntity
            retrieve (p) where p.active = true
            sort by p.name asc
        ");
        
        return $this->render('products.index', compact('products'));
    }
}
```

## Configuration Reference

### Default Configuration Values

The service providers include these default configuration values:

| Setting               | Default Value                          | Description                   |
|-----------------------|----------------------------------------|-------------------------------|
| `driver`              | `mysql`                                | Database driver               |
| `host`                | `localhost`                            | Database host                 |
| `port`                | `3306`                                 | Database port                 |
| `encoding`            | `utf8mb4`                              | Character encoding            |
| `collation`           | `utf8mb4_unicode_ci`                   | Database collation            |
| `entity_namespace`    | `App\\Entity`                          | Namespace for entity classes  |
| `entity_path`         | `src/Entity`                           | Directory for entity classes  |
| `proxy_namespace`     | `Quellabs\\ObjectQuel\\Proxy\\Runtime` | Namespace for proxy classes   |
| `proxy_path`          | `var/cache/proxies`                    | Directory for proxy classes   |
| `metadata_cache_path` | `var/cache/metadata`                   | Directory for metadata cache  |
| `migrations_path`     | `database/migrations`                  | Directory for migration files |

## Usage Examples

### Working with Entities

```php
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Canvas\Annotations\Route;

// In a Canvas controller with DI
class OrderController extends BaseController {
    
    /**
     * @Route('/orders/{id}', methods={['GET']})
     */
    public function show(int $id) {
        $order = $this->em()->find(OrderEntity::class, $id);
        
        if (!$order) {
            return $this->notFound();
        }
        
        return $this->render('orders.show.tpl', compact('order'));
    }
    
    /**
     * @Route('/orders/{id}', methods={['PUT', 'PATCH']})
     */
    public function update(int $id, array $data) {
        $order = $this->em()->find(OrderEntity::class, $id);
        $order->setStatus($data['status']);
        
        $this->em()->persist($order);
        $this->em()->flush();
        
        return $this->json(['success' => true]);
    }
}
```

### Complex Queries

```php
// Using ObjectQuel query language
public function getRecentOrdersWithCustomers() {
    return $this->em()->executeQuery("
        range of o is App\\Entity\\OrderEntity
        range of c is App\\Entity\\CustomerEntity via o.customer
        retrieve (o, c.name) 
        where o.createdAt > :since
        sort by o.createdAt desc
        window 0 using window_size 10
    ", [
        'since' => new DateTime('-30 days')
    ]);
}
```

### Repository Pattern

```php
// Create a custom repository
use Quellabs\ObjectQuel\Repository;

class ProductRepository extends Repository {
    
    public function __construct(EntityManager $entityManager) {
        parent::__construct($entityManager, ProductEntity::class);
    }
        
    /**
     * Find products below a certain price
     * @param float $maxPrice Maximum price threshold
     * @return array<ProductEntity> Matching products
     */
    public function findFeaturedProducts(): QuelResult {
        return $this->em()->executeQuery("
            range of p is App\\Entity\\ProductEntity
            retrieve (p) where p.featured = true
            sort by p.sortOrder asc
        ");
    }
}
```

## Troubleshooting

## Support

For support and documentation:

- **Email**: support@quellabs.com
- **Issues**: [GitHub Issues](https://github.com/quellabs/objectquel/issues)
- **Discussions**: [GitHub Discussions](https://github.com/quellabs/objectquel/discussions)
- **Documentation**: [ObjectQuel Documentation](https://objectquel.quellabs.com/docs)
- **Wiki**: [GitHub Wiki](https://github.com/quellabs/objectquel/wiki)

## License

This package is released under the MIT License.