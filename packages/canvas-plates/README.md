# Plates Template Engine for Canvas Framework

A Plates template engine implementation for the Canvas PHP framework that provides a consistent interface for template rendering operations.

## Features

- **Interface Compliance**: Implements `TemplateEngineInterface` for consistent template engine operations
- **Native PHP Templates**: No new syntax to learn — templates are plain PHP files
- **Global Variables**: Support for framework-wide template variables
- **Custom Functions**: Easy registration of custom functions accessible in all templates
- **String Templates**: Render templates from strings as well as files
- **Multiple Template Paths**: Support for namespaced template directories (Plates "folders")

## Installation

Install the package via Composer:

```bash
composer require quellabs/canvas-plates
```

The package includes Plates as a dependency and automatically copies the configuration file to `/config/plates.php`. No additional setup is required - just register the service provider in your Canvas framework configuration.

## Configuration

The package automatically creates a configuration file at `config/plates.php` during installation.

## Selecting the Default Template Engine

To use Plates as the default template engine across your application, set the following in `config/app.php`:

```php
'template_engine' => 'plates',
```

Once set, rendering templates in your controllers works as normal:

```php
/**
 * @Route("/")
 * @return Response
 */
public function index(Request $request): Response {
    return $this->render('pages/home', [
        'title' => 'Welcome',
    ]);
}
```

If you need to mix multiple template engines in the same project, you can request a specific engine explicitly via the container:

```php
$template = $container->for('smarty')->get(TemplateEngineInterface::class);
$template = $container->for('plates')->get(TemplateEngineInterface::class);
$template = $container->for('blade')->get(TemplateEngineInterface::class);
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

## Custom Functions

Register custom functions in `config/plates.php` and call them inside templates via `$this->functionName(...)`:

```php
'functions' => [
    'asset' => 'App\\Helpers\\AssetHelper::url',
    'route' => 'App\\Helpers\\RouteHelper::generate',
]
```

```php
<!-- In a template -->
<link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>">
<a href="<?= $this->route('home') ?>">Home</a>
```

## Namespaced Template Folders

Register additional template directories with a namespace in `config/plates.php`:

```php
'paths' => [
    'admin' => '/path/to/admin/templates',
]
```

Then reference them in templates or render calls using Plates' double-colon syntax:

```php
$template->render('admin::users/list', $data);
```

## Requirements

- PHP 8.2 or higher
- Canvas Framework with TemplateEngineInterface

## License

MIT License