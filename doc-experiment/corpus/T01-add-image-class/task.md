# Add a class to every image

Write a single PHP function:

```php
function add_image_class( string $html ): string
```

Given an HTML document or fragment, add the class `wp-image` to every `IMG`
tag, and return the modified HTML. Everything else in the document must be
preserved byte-for-byte. If an `IMG` tag already has classes, `wp-image` is
added to them (do not remove or reorder existing classes).

Images that appear inside HTML comments are not real tags and must not be
modified. Tag name matching is case-insensitive (`<IMG>` is an `IMG` tag).

Examples:

```php
add_image_class( '<p><img src="a.jpg"></p>' )
// => '<p><img class="wp-image" src="a.jpg"></p>'

add_image_class( '<img class="photo" src="a.jpg">' )
// => '<img class="photo wp-image" src="a.jpg">'
```
