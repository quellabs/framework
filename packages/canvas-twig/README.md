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

## Selecting the Default Template Engine

To use Twig as the default template engine across your application, set the following in `config/app.php`:

```php
'template_engine' => 'twig',
```

Once set, rendering templates in your controllers works as normal:

```php
/**
 * @Route("/")
 * @return Response
 */
public function index(Request $request): Response {
    return $this->render('pages/home.twig', [
        'title' => 'Welcome',
    ]);
}
```

If you need to mix multiple template engines in the same project, you can request a specific engine explicitly via the container:

```php
$template = $container->for('smarty')->get(TemplateEngineInterface::class);
$template = $container->for('twig')->get(TemplateEngineInterface::class);
$template = $container->for('blade')->get(TemplateEngineInterface::class);
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