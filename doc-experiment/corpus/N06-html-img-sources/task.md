# Collect HTML image sources, not SVG ones

Write a single PHP function:

```php
function collect_html_img_sources( string $html ): array
```

Given an HTML fragment (as found inside `<body>`), return a list (numeric
array) of the decoded `src` values of every HTML `img` element — as a
browser would understand the document — in document order. SVG `<image>`
elements (inside `<svg>`) are a different element in a different namespace
and must be excluded. Skip images that have no `src` attribute or whose
`src` has no value.

Be careful: what counts as an HTML `img` element is defined by how
browsers parse the markup, which is not always how it is spelled in the
source.

Example:

```php
collect_html_img_sources( '<p><img src="a.jpg"></p><svg><image href="v.svg" src="not-img.jpg"></svg>' )
// => [ 'a.jpg' ]
```
