# Build a heading outline

Write a single PHP function:

```php
function heading_outline( string $html ): array
```

Given an HTML fragment (as found inside `<body>`), return a list (numeric
array) of all headings (`H1` through `H6`) in document order. Each entry is
an associative array:

- `'level'`: the heading level as an integer (1–6).
- `'text'`: the heading's text content — all text nodes inside it
  concatenated, character references decoded, markup contributing nothing.

Return an empty array when there are no headings.

Example:

```php
heading_outline( '<h1>Title</h1><p>intro</p><h2>Part <em>one</em></h2>' )
// => [ ['level' => 1, 'text' => 'Title'], ['level' => 2, 'text' => 'Part one'] ]
```
