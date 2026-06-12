# Count items in the first list

Write a single PHP function:

```php
function add_first_list_item_count( string $html ): string
```

Given an HTML fragment (as found inside `<body>`), find the first `UL` or
`OL` element, count its direct `LI` children, add a `data-item-count`
attribute with that count to the list element, and return the modified
HTML. If there is no list, return the HTML unchanged. If the first list
cannot be fully scanned, return the HTML unchanged.

Example:

```php
add_first_list_item_count( '<ul><li>A</li><li>B</li><li>C</li></ul>' )
// => '<ul data-item-count="3"><li>A</li><li>B</li><li>C</li></ul>'
```
