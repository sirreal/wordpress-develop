# Extract the first heading's text

Write a single PHP function:

```php
function get_first_h1_text( string $html ): ?string
```

Given an HTML fragment (as found inside `<body>`), return the text content
of the first `H1` element: the concatenation of all text nodes inside it,
including text inside nested elements, with character references decoded
(`&amp;` becomes `&`). Markup contributes nothing — an `H1` containing only
an image has text content `""` (empty string, not null).

Return `null` only when the document contains no `H1` element.

Examples:

```php
get_first_h1_text( '<h1>Hello</h1>' )                  // => 'Hello'
get_first_h1_text( '<h1>A <em>B</em> C</h1>' )         // => 'A B C'
get_first_h1_text( '<p>No headings here.</p>' )        // => null
```
