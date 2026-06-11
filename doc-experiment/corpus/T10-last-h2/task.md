# Mark the last section heading

Write a single PHP function:

```php
function mark_last_h2( string $html ): string
```

Given an HTML document or fragment, add the class `final-section` to the
**last** `H2` tag in the document, and return the modified HTML. Everything
else must be preserved byte-for-byte. If the document has no `H2`, return
it unchanged. `H2` tags inside HTML comments are not real tags and do not
count.

The document may be large and may contain many `H2` tags.

Example:

```php
mark_last_h2( '<h2>One</h2><p>…</p><h2>Two</h2><p>…</p>' )
// => '<h2>One</h2><p>…</p><h2 class="final-section">Two</h2><p>…</p>'
```
