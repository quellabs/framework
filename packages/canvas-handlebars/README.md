# Canvas Handlebars Template Engine

A Handlebars template engine integration for the Canvas PHP framework, powered
by [LightnCandy](https://github.com/zordius/lightncandy).

## Installation

```bash
composer require quellabs/canvas-handlebars
```

## Requirements

- PHP 8.3 or higher
- Canvas framework

## How it works

Unlike output-cache engines (Smarty, Twig), LightnCandy **compiles** Handlebars templates to native PHP closures. The
compiled files are stored in `compile_dir` and reused across requests. Recompilation only happens when a source template
is newer than its compiled counterpart — no opcode cache invalidation overhead.

## Usage

The Handlebars template engine is automatically registered with Canvas through the service discovery system.

```php
class HomeController {
    public function index(TemplateEngineInterface $handlebars) {
        return $handlebars->render('home.hbs', [
            'title' => 'Welcome to Canvas',
            'user'  => $user
        ]);
    }
}
```

## Template Files

```
templates/
├── home.hbs
├── layouts/
│   └── app.hbs
└── partials/
    └── header.hbs
```

## Helpers

Register helpers in `config/handlebars.php`:

```php
'helpers' => [
    'uppercase'  => fn($str)  => strtoupper($str),
    'formatDate' => fn($ts)   => date('Y-m-d', $ts),
],
```

Or register them at runtime via the engine instance:

```php
$handlebars->registerHelper('uppercase', fn($str) => strtoupper($str));
```

Use in templates:

```handlebars
<h1>{{uppercase title}}</h1>
<p>Published: {{formatDate createdAt}}</p>
```

## Partials

Partials are reusable template fragments. Register them as strings in config or at runtime:

```php
$handlebars->registerPartial('header', '<header>{{siteName}}</header>');
```

Use in templates:

```handlebars
{{> header}}
<main>{{content}}</main>
```

## Configuration

| Key            | Default                       | Description                                              |
|----------------|-------------------------------|----------------------------------------------------------|
| `template_dir` | `templates/`                  | Where `.hbs` files are stored                            |
| `compile_dir`  | `storage/handlebars/compile/` | Where compiled PHP renderers are cached                  |
| `strict_mode`  | `false`                       | Throw on missing variables instead of rendering empty    |
| `standalone`   | `false`                       | Embed helpers in compiled output (no runtime dependency) |
| `helpers`      | `[]`                          | Named helper callables                                   |
| `partials`     | `[]`                          | Named partial template strings                           |
| `globals`      | `[]`                          | Variables available in all templates                     |

## Sculpt Commands

```bash
php sculpt handlebars:clear-cache
```

Removes all compiled PHP renderers, forcing recompilation on the next request.

## License

MIT License