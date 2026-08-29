# Normalize HTML with a fallback

Write a single PHP function:

```php
function normalize_or_placeholder( string $html ): string
```

Given an HTML fragment (as found inside `<body>`), return its normalized
HTML serialization. If the HTML API cannot normalize the fragment, return
this exact fallback HTML:

```html
<p>Unsupported HTML</p>
```

Example:

```php
normalize_or_placeholder( '<div><p>Hello' )
// => '<div><p>Hello</p></div>'
```
