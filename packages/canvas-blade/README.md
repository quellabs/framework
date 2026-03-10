# Blade Template Engine for Canvas Framework

A Blade template engine implementation for the Canvas PHP framework that provides a consistent interface for template rendering operations.

## Features

- **Interface Compliance**: Implements `TemplateEngineInterface` for consistent template engine operations
- **Flexible Configuration**: Supports all major Blade configuration options
- **Caching Support**: Built-in template caching with cache management
- **Global Variables**: Support for framework-wide template variables
- **Custom Directives**: Easy registration of custom directives and @if-directives
- **String Templates**: Render templates from strings as well as files
- **Multiple Template Paths**: Support for namespaced template directories

## Installation

Install the package via Composer:

```bash
composer require quellabs/canvas-blade
```

The package includes Blade as a dependency and automatically copies the configuration file to `/config/blade.php`. No additional setup is required - just register the service provider in your Canvas framework configuration.

## Configuration

The package automatically creates a configuration file at `config/blade.php` during installation.

## Selecting the Default Template Engine

To use Blade as the default template engine across your application, set the following in `config/app.php`:

```php
'template_engine' => 'blade',
```

Once set, rendering templates in your controllers works as normal:

```php
/**
 * @Route("/")
 * @return Response
 */
public function index(Request $request): Response {
    return $this->render('pages.home', [
        'title' => 'Welcome',
    ]);
}
```

If you need to mix multiple template engines in the same project, you can request a specific engine explicitly via the container:

```php
$template = $container->for('blade')->get(TemplateEngineInterface::class);
$template = $container->for('twig')->get(TemplateEngineInterface::class);
```


## Error Handling

The template engine provides detailed error messages for common issues:

```php
try {
    $output = $template->render('nonexistent', $data);
} catch (TemplateRenderException $e) {
    echo "Template error: " . $e->getMessage();
}
```

## Custom Directives

Register custom directives in `config/blade.php` or at runtime:

```php
// In config/blade.php
'directives' => [
    'datetime' => fn($expr) => '<?php echo (' . $expr . ')->format(\'d/m/Y H:i\'); ?>',
],

// At runtime
$template->registerDirective('datetime', fn($expr) => '<?php echo (' . $expr . ')->format(\'d/m/Y H:i\'); ?>');
```

Use in templates:
```blade
@datetime($created_at)
```

### @if Directives

Register custom condition directives:

```php
'if_directives' => [
    'admin' => fn() => currentUser()->isAdmin(),
],
```

Use in templates:
```blade
@admin
    <a href="/admin">Admin panel</a>
@endadmin
```

## Performance Considerations

1. **Enable Caching**: Always enable caching in production environments
2. **Template Compilation**: Blade compiles templates to PHP for better performance
3. **Cache Warming**: Consider pre-compiling templates for optimal performance


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

For issues specific to this Blade implementation, please check:

1. Blade documentation: https://laravel.com/docs/blade
2. Canvas framework documentation
3. This implementation's source code comments