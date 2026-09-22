# Mark the last section heading

Write a single PHP function:

```php
function mark_last_h2( string $html ): string
```

Given an HTML document or fragment, add the class `final-section` to the
**last** `H2` tag in the document, and return the modified HTML. If the
document has no `H2`, return it unchanged.

Example:

```php
mark_last_h2( '<h2>One</h2><p>…</p><h2>Two</h2><p>…</p>' )
// => '<h2>One</h2><p>…</p><h2 class="final-section">Two</h2><p>…</p>'
```
