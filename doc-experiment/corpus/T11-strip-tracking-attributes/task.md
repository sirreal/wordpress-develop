# Remove tracking attributes

Write a single PHP function:

```php
function strip_tracking_attributes( string $html ): string
```

Given an HTML document or fragment, remove every attribute whose name starts
with `data-track-` from every tag, and return the modified HTML. Attributes
with similar names such as `data-tracker` or `data-track` must remain.

Example:

```php
strip_tracking_attributes( '<a href="/x" data-track-id="7" data-tracker="keep">go</a>' )
// => '<a href="/x" data-tracker="keep">go</a>'
```
