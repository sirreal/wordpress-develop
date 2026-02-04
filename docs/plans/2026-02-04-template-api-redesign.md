# WP_HTML_Template API Redesign

**Date:** 2026-02-04
**Status:** Design Complete

---

## Overview

Redesign the `WP_HTML_Template` API to support efficient repeated rendering. The key insight: parse once, bind many times, render on demand.

## Classes

### WP_HTML_Template

The parsed template. Reusable across multiple bind operations.

```php
class WP_HTML_Template {
    public static function render(string $template, array $replacements): string|false;
    public static function from(string $template): self;
    public function bind(array $replacements): WP_HTML_Bound_Template;
}
```

### WP_HTML_Bound_Template

A template with replacements bound. Ready to render.

```php
class WP_HTML_Bound_Template {
    public function render(): string|false;
}
```

## Usage Patterns

### One-shot rendering

For simple cases where you render once:

```php
echo WP_HTML_Template::render('<p></%name></p>', ['name' => 'Alice']);
```

### Reusable templates

For loops or repeated use:

```php
$template = WP_HTML_Template::from('<li></%item></li>');

foreach ($items as $item) {
    echo $template->bind(['item' => $item])->render();
}
```

### Nested templates

Templates can contain other bound templates as replacements:

```php
$icon = WP_HTML_Template::from('<span class="icon </%class>"></span>');
$message = WP_HTML_Template::from('<div class="alert"></%icon> </%text></div>');

echo $message->bind([
    'icon' => $icon->bind(['class' => 'warning']),
    'text' => 'Something went wrong',
])->render();
```

## Replacement Values

Valid replacement types depend on context:

| Context   | String | WP_HTML_Bound_Template |
|-----------|--------|------------------------|
| Text      | Yes    | Yes                    |
| Attribute | Yes    | No (error)             |

## Lazy Compilation

`from()` stores the template string but does not parse it immediately. Compilation happens on the first `bind()` call and is cached for subsequent calls.

```php
// Just stores the string
$template = WP_HTML_Template::from('<p></%name></p>');

// First bind() triggers compilation, caches result
$bound1 = $template->bind(['name' => 'Alice']);

// Subsequent bind() reuses cached compilation
$bound2 = $template->bind(['name' => 'Bob']);
```

## Compilation Output

Compilation produces a list of placeholders with their positions and contexts:

```php
[
    'template' => '<p class="</%class>"></%content></p>',
    'placeholders' => [
        'class'   => ['start' => 11, 'length' => 10, 'context' => 'attribute'],
        'content' => ['start' => 23, 'length' => 12, 'context' => 'text'],
    ],
]
```

This allows `bind()` to validate replacements and `render()` to efficiently build the output string.

## Error Handling

Validation occurs at `bind()` time using the compiled placeholder information:

### Missing key

```php
$t = WP_HTML_Template::from('<p></%name> </%age></p>');
$t->bind(['name' => 'Alice']);
// Warning: Missing replacement key 'age'
```

Rendering continues with missing placeholders removed.

### Unused key

```php
$t->bind(['name' => 'Alice', 'age' => '30', 'extra' => 'ignored']);
// Warning: Unused replacement key 'extra'
```

Rendering continues with unused keys ignored.

### Wrong type for attribute

```php
$t = WP_HTML_Template::from('<p class="</%class>">text</p>');
$t->bind(['class' => $otherTemplate->bind([...])]);
// Warning: Replacement 'class' must be a string (attribute context)
```

`render()` returns `false`.

## Migration from Current API

| Old | New |
|-----|-----|
| `T::sprintf($tpl, $r)` | `T::render($tpl, $r)` |
| `T::from($tpl, $r)->render()` | `T::from($tpl)->bind($r)->render()` |
| `T::from($tpl)->render($r)` | `T::from($tpl)->bind($r)->render()` |

Breaking changes are acceptable since this is pre-release code (`@since 7.0.0`).
