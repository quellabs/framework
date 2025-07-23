# Twig Template Engine for Canvas Framework

A Twig template engine implementation for the Canvas PHP framework that provides a consistent interface for template rendering operations.

## Features

- **Interface Compliance**: Implements `TemplateEngineInterface` for consistent template engine operations
- **Flexible Configuration**: Supports all major Twig configuration options
- **Caching Support**: Built-in template caching with cache management
- **Global Variables**: Support for framework-wide template variables
- **Custom Extensions**: Easy registration of custom functions and filters
- **String Templates**: Render templates from strings as well as files
- **Multiple Template Paths**: Support for namespaced template directories
- **Debug Mode**: Development-friendly debugging and auto-reload features

## Installation

Install the package via Composer:

```bash
composer require quellabs/canvas-twig
```

The package includes Twig as a dependency and automatically copies the configuration file to `/config/twig.php`. No additional setup is required - just register the service provider in your Canvas framework configuration.

## Configuration

The package automatically creates a configuration file at `config/twig.php` during installation.

## Basic Usage

### Using the Template Engine Interface

```php
use Quellabs\Contracts\Templates\TemplateEngineInterface;

// Get the template engine instance (via dependency injection)
$template = $container->for('twig')->get(TemplateEngineInterface::class);

// Render a template file
$output = $template->render('pages/home.twig', [
    'title' => 'Welcome',
    'user' => $userData
]);

// Render a template string
$stringTemplate = '<h1>Hello {{ name }}!</h1>';
$output = $template->renderString($stringTemplate, ['name' => 'World']);

// Check if a template exists
if ($template->exists('pages/about.twig')) {
    $output = $template->render('pages/about.twig');
}

// Add global variables
$template->addGlobal('current_year', date('Y'));

// Clear cache
$template->clearCache();
```

### Direct Usage

```php
use Quellabs\Canvas\Twig\TwigTemplate;

$config = [
    'template_dir' => '/path/to/templates',
    'cache_dir' => '/path/to/cache',
    'debugging' => false,
    'caching' => true
];

$twig = new TwigTemplate($config);
$output = $twig->render('template.twig', ['data' => $value]);
```

## Error Handling

The template engine provides detailed error messages for common issues:

```php
try {
    $output = $template->render('nonexistent.twig', $data);
} catch (TemplateRenderException $e) {
    echo "Template error: " . $e->getMessage();
}
```

## Performance Considerations

1. **Enable Caching**: Always enable caching in production environments
2. **Template Compilation**: Twig compiles templates to PHP for better performance
3. **Auto-reload**: Disable `auto_reload` in production to avoid file system checks
4. **Cache Warming**: Consider pre-compiling templates for optimal performance

## Debugging

Enable debug mode during development:

```php
$config = [
    'debugging' => true,
    'auto_reload' => true,
    'strict_variables' => true  // Catch undefined variable errors
];
```

Use the debug function in templates:
```twig
{{ dump(variable) }}
```

## Comparison with Smarty

| Feature     | Twig                  | Smarty                   |
|-------------|-----------------------|--------------------------|
| Syntax      | `{{ variable }}`      | `{$variable}`            |
| Filters     | `{{ value\|filter }}` | `{$value\|modifier}`     |
| Comments    | `{# comment #}`       | `{* comment *}`          |
| Inheritance | `{% extends %}`       | `{extends}`              |
| Blocks      | `{% block %}`         | `{block}`                |
| Security    | Built-in escaping     | Optional security policy |

## Requirements

- PHP 8.2 or higher
- Canvas Framework with TemplateEngineInterface

## License

MIT License

## Contributing

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Submit a pull request

## Support

For issues specific to this Twig implementation, please check:

1. Twig documentation: https://twig.symfony.com/doc/
2. Canvas framework documentation
3. This implementation's source code comments