# Mark nested lists

Write a single PHP function:

```php
function mark_nested_lists( string $html ): string
```

Given an HTML fragment (as found inside `<body>`), add the class
`nested-list` to every `UL` or `OL` element that has a `UL` or `OL` ancestor
anywhere above it. Top-level lists must not be modified. Return the modified
HTML; everything else must be preserved byte-for-byte.

Examples:

```php
mark_nested_lists( '<ul><li>One<ol><li>Nested</li></ol></li></ul>' )
// => '<ul><li>One<ol class="nested-list"><li>Nested</li></ol></li></ul>'
```
