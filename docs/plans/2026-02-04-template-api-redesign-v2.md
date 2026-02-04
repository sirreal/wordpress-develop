# WP_HTML_Template API Redesign v2

**Date:** 2026-02-04
**Status:** Design Complete

---

## Overview

A single-class template API with strict error handling. Parse once, bind values, render on demand.

## Essential Interface

```php
class WP_HTML_Template {
    public static function from(string $template): static;
    public function bind(array $replacements): static;
    public function render(): string|false;
}
```

### Methods

**`from(string $template): static`**

Creates a template from a string.

**`bind(array $replacements): static`**

Returns a new immutable instance with replacements bound.

**`render(): string|false`**

Renders the template to an HTML string. Returns `false` on any error.

## Usage Patterns

### One-shot rendering

```php
$html = WP_HTML_Template::from('<p>Hello, </%name>!</p>')
    ->bind(['name' => 'World'])
    ->render();
```

### Nested templates (HTML injection)

A template instance can be used as a replacement value. It will be rendered and injected as HTML (not escaped).

```php
$html = WP_HTML_Template::from('<div></%icon> </%message></div>')
    ->bind([
        'icon' => WP_HTML_Template::from('<span class="dashicon warning"></span>'),
        'message' => 'Something went wrong.',
    ])
    ->render();
```

### Nested templates with their own placeholders

```php
$html = WP_HTML_Template::from('<div class="alert"></%content></div>')
    ->bind([
        'content' => WP_HTML_Template::from('<a href="</%url>"></%text></a>')
            ->bind(['url' => '/help', 'text' => 'Learn more']),
    ])
    ->render();
```

### Loop rendering

Parse once, bind and render with different values each iteration.

```php
$item_template = WP_HTML_Template::from('<li></%item></li>');

foreach ($items as $item) {
    echo $item_template->bind(['item' => $item])->render();
}
```

## Replacement Values

| Replacement Type | Text Context | Attribute Context |
|------------------|--------------|-------------------|
| String | Escaped | Escaped |
| WP_HTML_Template | Rendered (HTML preserved) | `false` (error) |

## Error Handling

All error conditions cause `render()` to return `false`:

| Condition | Result |
|-----------|--------|
| Missing replacement key | `false` |
| Unused replacement key | `false` |
| Template in attribute context | `false` |
| HTML processing/normalization failure | `false` |

Replacements must match placeholders exactly—no more, no less.

## Immutability

`bind()` always returns a new instance. Templates are safe to reuse:

```php
$tpl = WP_HTML_Template::from('<p></%text></p>');

$a = $tpl->bind(['text' => 'Hello']);
$b = $tpl->bind(['text' => 'World']);

// $a and $b are independent
echo $a->render(); // <p>Hello</p>
echo $b->render(); // <p>World</p>
```

## Migration from Current API

| Old | New |
|-----|-----|
| `T::sprintf($tpl, $r)` | `T::from($tpl)->bind($r)->render()` |
| `T::from($tpl, $r)->render()` | `T::from($tpl)->bind($r)->render()` |
| `T::from($tpl)->render($r)` | `T::from($tpl)->bind($r)->render()` |

Breaking changes are acceptable since this is pre-release code (`@since 7.0.0`).
