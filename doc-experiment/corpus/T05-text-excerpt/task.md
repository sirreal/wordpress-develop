# Plain-text excerpt with a length limit

Write a single PHP function:

```php
function html_text_excerpt( string $html, int $max_codepoints ): string
```

Given an HTML fragment (as found inside `<body>`), return its text content:
the concatenation of every text node in document order, with character
references decoded. Do not normalize or collapse whitespace — whitespace
between elements that the parser reports as text nodes is included as-is.
Text in `<textarea>` and `<title>` elements also counts. Text that is not a
text node or one of those special text-bearing elements contributes nothing
(for example the contents of `<script>` and `<style>` elements are excluded).

If the resulting text contains more than `$max_codepoints` Unicode code
points, truncate it to exactly `$max_codepoints` code points (never cut in
the middle of a multi-byte character; no ellipsis). If `$max_codepoints` is
zero or negative, return the empty string.

Examples:

```php
html_text_excerpt( '<p>Just <a href="#">a link</a> to content.</p>', 1000 )
// => 'Just a link to content.'

html_text_excerpt( '<p>Just <a href="#">a link</a> to content.</p>', 6 )
// => 'Just a'
```
