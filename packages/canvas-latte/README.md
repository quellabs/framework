# Canvas Latte Template Engine

A Latte template engine integration for the Canvas PHP framework.

## Installation

```bash
composer require quellabs/canvas-latte
```

## Requirements

- PHP 8.2 or higher
- Canvas framework

## Usage

The Latte template engine is automatically registered with Canvas through the service discovery system. No manual configuration required.

```php
// In your Canvas controller
class HomeController {
    public function index(TemplateEngineInterface $latte) {
        return $latte->render('home', [
            'title' => 'Welcome to Canvas',
            'user' => $user
        ]);
    }
}
```

## Configuration

To set Latte as the default template engine, add the following to `config/app.php`:

```php
// Template engine
'template_engine' => 'latte',
```

## Template Files

Place your Latte templates in your Canvas application's template directory:

```
templates/
├── home.latte
├── layouts/
│   └── app.latte
└── partials/
    └── header.latte
```

## License

MIT License