# Remove span wrappers

Write a single PHP function:

```php
function unwrap_spans( string $html ): string
```

Given an HTML fragment (as found inside `<body>`), remove every `SPAN`
element while keeping its contents in place, and return a **normalized**
serialization of the result. Spans nested inside other spans are also
removed (their contents remain). All attributes on removed spans are
discarded with them.

The output is normalized HTML: optional tags are closed, attribute values
double-quoted, text re-encoded canonically. Apart from the removed spans it
is exactly the normalized form of the input.

Example:

```php
unwrap_spans( '<p>a <span class="x">b <em>c</em></span> d</p>' )
// => '<p>a b <em>c</em> d</p>'
```
