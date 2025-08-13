# Sculpt

A powerful CLI framework for the Quellabs ecosystem that provides an elegant command-line interface for rapid development, code generation, and project management. Sculpt seamlessly integrates with ObjectQuel ORM and uses a service provider architecture for extensibility.

[![Latest Stable Version](https://img.shields.io/packagist/v/quellabs/sculpt.svg)](https://packagist.org/packages/quellabs/sculpt)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/quellabs/sculpt.svg)](https://packagist.org/packages/quellabs/sculpt)

## Key Features

- **Unified Command Interface** — Access commands from across the Quellabs ecosystem through a single CLI tool
- **Service Provider Architecture** — Robust plugin system allowing packages to register commands and services
- **Smart Discovery** — Automatically detects and loads commands from installed packages
- **Cross-Package Integration** — Enables seamless interaction between ObjectQuel and other components
- **Parameter Management** — Sophisticated handling of command-line parameters with validation and type checking

## Installation

```bash
composer require quellabs/sculpt
```

## Usage

Once installed, you can run Sculpt commands using:

```bash
# Run a command
vendor/bin/sculpt <command>

# List all available commands
vendor/bin/sculpt

# Get detailed help for a specific command
vendor/bin/sculpt help <command>
```

### Command Structure

Commands in Sculpt follow a namespace pattern: `namespace:command`

Examples:
- `db:migrate` — Run database migrations
- `make:model` — Generate a model class
- `cache:clear` — Clear application cache

### Parameter Formats

Sculpt supports various parameter formats:

```bash
# Named parameters
vendor/bin/sculpt make:model --name=User --table=users

# Flags (long and short forms)
vendor/bin/sculpt migrate --force --verbose
vendor/bin/sculpt migrate -fv

# Positional parameters
vendor/bin/sculpt make:controller User
```

## Extending Sculpt

Sculpt uses a service provider pattern to discover and register commands from packages. This allows any package in the ecosystem to contribute commands.

### Creating a Custom Command

Commands should extend the `BaseCommand` class:

```php
<?php

namespace Your\Package\Commands;

use Quellabs\Sculpt\Commands\BaseCommand;
use Quellabs\Sculpt\ConfigurationManager;

class YourCommand extends BaseCommand {
    /**
     * Define the command signature (how users will call it)
     */
    public function getSignature(): string {
        return 'your:command';
    }
    
    /**
     * Provide a helpful description for the help system
     */
    public function getDescription(): string {
        return 'Description of what your command does';
    }
    
    /**
     * Execute the command with parsed configuration
     */
    public function execute(ConfigurationManager $config): int {
        // Access command parameters
        $name = $config->get('name', 'default-name');
        $force = $config->hasFlag('force');
        
        // Display information to the user
        $this->output->writeLn("<bold>Executing command for: {$name}</bold>");
        
        if ($force) {
            $this->output->warning("Force flag is enabled!");
        }
        
        // Your command logic here...
        $this->output->writeLn("<green>Command completed successfully!</green>");
        
        return 0; // Return 0 for success, non-zero for errors
    }
}
```

### Creating a Service Provider

Service providers register your commands with Sculpt:

```php
<?php

namespace Your\Package;

use Quellabs\Sculpt\Contracts\ServiceProvider;

class SculptServiceProvider extends ServiceProvider {
    /**
     * Register your package's commands and services
     */
    public function register(mixed $container): void {
        // Register commands with the application
        $container->registerCommands($app, [
            \Your\Package\Commands\YourCommand::class,
            \Your\Package\Commands\AnotherCommand::class
        ]);
    }
}
```

### Package Discovery Configuration

Tell Sculpt about your service provider in your package's `composer.json`:

```json
{
    "name": "your/package",
    "extra": {
        "discover": {
            "sculpt": {
                "provider": "Your\\Package\\SculptServiceProvider"
            }
        }
    }
}
```

For multiple providers:

```json
{
    "extra": {
        "discover": {
            "sculpt": {
                "providers": [
                    "Your\\Package\\SculptServiceProvider",
                    "Your\\Package\\AnotherServiceProvider"
                ]
            }
        }
    }
}
```

## Configuration Manager

The `ConfigurationManager` provides a clean API for accessing command parameters with validation and type checking:

```php
// Get named parameters with default values
$name = $config->get('name', 'default-value');

// Check if flags are set (supports both long and short forms)
if ($config->hasFlag('force') || $config->hasFlag('f')) {
    // Execute in force mode
}

// Access positional parameters
$firstArg = $config->getPositional(0);

// Validate parameter format with regex
$email = $config->getValidated('email', '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/');

// Restrict parameter to allowed values
$env = $config->getEnum('environment', ['development', 'staging', 'production']);

// Ensure required parameters are provided
$config->requireParameters(['name', 'type']);
```

## Contributing

We welcome contributions! Here's how you can help:

- **Report bugs** — Open an issue if you find a problem
- **Suggest features** — Share your ideas for improvements
- **Submit pull requests** — Fix bugs or add new features

Please ensure your code follows our coding standards and includes appropriate tests.

## License

Sculpt is open-source software licensed under the MIT license.