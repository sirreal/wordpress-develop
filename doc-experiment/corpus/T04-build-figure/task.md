# Build a figure fragment

Write a single PHP function:

```php
function build_figure( string $url, string $alt, string $caption ): string
```

Build and return an HTML fragment of exactly this shape:

```html
<figure><img src="…" alt="…"><figcaption>…</figcaption></figure>
```

where the `src` attribute holds `$url`, the `alt` attribute holds `$alt`,
and the `figcaption` contains `$caption` as its text. The attributes must
appear in exactly that order: `src`, then `alt`. The inputs are plain,
unescaped strings and may contain characters that are special in HTML
(`&`, `<`, `>`, quotes); they must be encoded so that a browser renders
exactly the provided values.

Use the HTML API to construct the fragment — do not hand-assemble the
string with manual escaping.

Example:

```php
build_figure( 'https://example.com/dog.jpg', 'A dog', 'My dog' )
// => '<figure><img src="https://example.com/dog.jpg" alt="A dog"><figcaption>My dog</figcaption></figure>'
```
