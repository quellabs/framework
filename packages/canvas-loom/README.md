# Loom

A definition-driven page builder for Canvas that turns structured PHP definitions into interactive admin pages — forms,
settings panels, editors — with automatic data binding and WakaPAC initialisation.

## Installation

```bash
composer require quellabs/canvas-loom
```

## Quick Start

```php
use Quellabs\Canvas\Loom\Loom;
use Quellabs\Canvas\Loom\Builder\Resource;
use Quellabs\Canvas\Loom\Builder\Section;
use Quellabs\Canvas\Loom\Builder\Field;

$definition = Resource::make('post-form', '/admin/posts/save')
    ->title('Edit Post')
    ->add(Section::make()
        ->add(Field::text('title', 'Title')->required())
        ->add(Field::textarea('body', 'Content')->rows(10))
    )
    ->build();

$loom = new Loom();
echo $loom->render($definition, [
    'title' => 'My First Post',
    'body'  => 'Hello world.',
]);
```

## How It Works

You build a node tree using fluent PHP builders. `Loom::render()` walks the tree, delegates each node to a renderer, and
emits HTML with the appropriate WakaPAC initialisation scripts. Entity data passed to `render()` is automatically
distributed to field values via DOM hydration.

## Node Types

| Node                 | Purpose                                                        |
|----------------------|----------------------------------------------------------------|
| `Resource`           | Page root — renders a `<form>` with header, title, save/cancel |
| `Section`            | Visual grouping without WakaPAC init                           |
| `Tabs` / `Tab`       | Tabbed interface managed by WakaPAC                            |
| `Panel`              | Reactive WakaPAC container without tabs                        |
| `Columns` / `Column` | Flex layout with percentage widths                             |
| `Field`              | Input with label, validation, and data binding                 |
| `Text`               | Read-only label/value pair, supports interpolation             |
| `Button`             | Action trigger bound to WakaPAC expressions                    |

## Fields

```php
Field::text('title', 'Title')->required()->maxlength(200)
Field::textarea('body', 'Content')->rows(10)
Field::select('status', 'Status')->options(['draft' => 'Draft', 'published' => 'Published'])
Field::checkbox('featured', 'Featured post')
Field::number('priority', 'Priority')->min(1)->max(10)
```

Dependent dropdowns are supported via `->dependsOn('parent_field')`. Dependent fields must be direct siblings within the
same `Column` or `Section`.

## Buttons

Buttons execute WakaPAC expressions via `->action()`. Three variants: primary (default), `->secondary()`, `->danger()`.

```php
Button::make('Save')->action('submit()')
Button::make('Publish')->action("post('/admin/posts/publish')")
Button::make('Delete')->danger()->action("Stdlib.sendMessage('post-form', MSG_DELETE, 0, 0)")
```

Every container exposes `submit()` and `post(url)` on its WakaPAC abstraction automatically.

## Abstraction

Named message constants and other non-reactive properties can be added to a container's WakaPAC abstraction.
Underscore-prefixed keys are non-reactive.

```php
Resource::make('post-form', '/admin/posts/save')
    ->abstraction([
        '_MSG_SHOW_DELETE' => 1001,
        '_MSG_HIDE_DELETE' => 1002,
    ]);
```

## Notifications

```php
$loom = new Loom();
$loom->notification('success', 'Post saved.');
$loom->notification('error', 'Title is required.');
echo $loom->render($definition, $data);
```

Types: `success`, `error`, `warning`, `info`.

## Custom Renderers

Extend `AbstractRenderer`, implement `RendererInterface`, and register on the `Loom` instance:

```php
$loom = new Loom();
$loom->register('field', MapFieldRenderer::class);
echo $loom->render($definition, $data);
```

To override only specific nodes within a type, check the node properties and delegate to the default renderer for
everything else.