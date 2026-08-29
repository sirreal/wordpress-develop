# Extract a table of contents

Write a single PHP function:

```php
function extract_toc( string $html ): array
```

Given an HTML fragment (as found inside `<body>`), return a list (numeric
array) describing every heading from `H1` through `H6` in document order.
Each entry is an associative array with:

- `'level'`: the heading level, from `1` through `6`.
- `'text'`: the heading's text content.

Markup inside a heading contributes its text, but not its tags. Headings
with no text are included with an empty string.

Example:

```php
extract_toc( '<h1>Intro</h1><p>Text</p><h3>Details <em>here</em></h3>' )
// => [ ['level' => 1, 'text' => 'Intro'], ['level' => 3, 'text' => 'Details here'] ]
```
