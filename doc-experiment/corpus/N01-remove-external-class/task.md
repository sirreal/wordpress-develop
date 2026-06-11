# Remove a class from links

Write a single PHP function:

```php
function remove_external_class( string $html ): string
```

Remove the class `external` from every `A` tag that has it, and return the
modified HTML. All other classes on the tag must be preserved. Class name
matching is case-sensitive: `class="EXTERNAL"` does not contain the class
`external`. `A` tags without the class, and all other markup, are left as
the HTML API leaves them — note that when `external` is a tag's only
class, removing it removes the whole `class` attribute, and whitespace
that surrounded a removed attribute remains where it was.

Examples:

```php
remove_external_class( '<a class="external link" href="/x">go</a>' )
// => '<a class="link" href="/x">go</a>'

remove_external_class( '<a class="external" href="/x">go</a>' )
// => '<a  href="/x">go</a>'
//    (only class removed -> class attribute removed; note leftover space)
```
