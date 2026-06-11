# Read data attributes

Write a single PHP function:

```php
function get_data_attributes( string $html ): array
```

Find the first `DIV` tag in the document and return an associative array of
all its `data-*` attributes: keys are the full lowercase attribute names
(including the `data-` prefix), values are the decoded attribute values as
the HTML API reports them (a string, or `true` for an attribute written
without a value). Preserve the order in which the attributes appear in the
tag. Return an empty array if there is no `DIV` or it has no `data-*`
attributes.

Example:

```php
get_data_attributes( '<div id="x" data-post-id="42" data-featured>…</div>' )
// => [ 'data-post-id' => '42', 'data-featured' => true ]
```
