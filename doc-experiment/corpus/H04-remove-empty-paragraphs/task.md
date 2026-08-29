# Remove empty paragraphs

Write a single PHP function:

```php
function remove_empty_paragraphs( string $html ): string
```

Given an HTML fragment (as found inside `<body>`), remove every empty `P`
element, and return a normalized serialization of the result. A paragraph
is empty only when it contains nothing at all; whitespace or child elements
count as content. If the fragment cannot be fully processed, return the
original HTML unchanged.

Example:

```php
remove_empty_paragraphs( '<p>Keep <em>me</em></p><p></p><p> </p>' )
// => '<p>Keep <em>me</em></p><p> </p>'
```
