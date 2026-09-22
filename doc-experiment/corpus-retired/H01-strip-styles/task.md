# Strip inline styles

Write a single PHP function:

```php
function strip_inline_styles( string $html ): string
```

Remove the `style` attribute from every tag in the document and return the
modified HTML. All other attributes and everything else in the document
must be preserved byte-for-byte; whitespace that surrounded a removed
attribute remains where it was. Attribute names are case-insensitive
(`STYLE="…"` is a `style` attribute). Content inside HTML comments is not
real markup and must not be modified.

Example (note the leftover spaces where the attributes were removed):

```php
strip_inline_styles( '<p style="color:red">Hi <b style="x">there</b></p>' )
// => '<p >Hi <b >there</b></p>'
```
