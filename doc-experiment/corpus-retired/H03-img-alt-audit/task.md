# Audit image alt text

Write a single PHP function:

```php
function find_images_missing_alt( string $html ): array
```

Return a list (numeric array) of the `src` values of every `IMG` tag whose
alternative text is missing or empty, in document order. "Missing or empty"
means: the `alt` attribute is absent, is written without a value
(`<img alt>`), or has the empty string as its value (`alt=""`). An `alt`
containing only whitespace (`alt=" "`) is **present** and does not count.
Skip `IMG` tags that have no `src` attribute, or whose `src` has no value
(`src` or `src=""`). The `src` values are the decoded attribute values.

Example:

```php
find_images_missing_alt( '<img src="a.jpg"><img src="b.jpg" alt="A bee"><img src="c.jpg" alt="">' )
// => [ 'a.jpg', 'c.jpg' ]
```
