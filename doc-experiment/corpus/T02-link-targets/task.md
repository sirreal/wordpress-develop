# Open links in a new tab

Write a single PHP function:

```php
function add_link_targets( string $html ): string
```

For every `A` tag that has an `href` attribute, set its `target` attribute to
`_blank`, and return the modified HTML. The `href` attribute counts as
present even when its value is the empty string (`href=""`) or when it is
written without a value (`<a href>`). `A` tags without an `href` attribute
must not be modified. An existing `target` attribute is overwritten.
Everything else in the document must be preserved byte-for-byte.

Example:

```php
add_link_targets( '<a href="/x">go</a> <a name="anchor">stay</a>' )
// => '<a target="_blank" href="/x">go</a> <a name="anchor">stay</a>'
```
