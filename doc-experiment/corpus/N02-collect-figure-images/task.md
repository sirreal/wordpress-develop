# Collect images inside figures

Write a single PHP function:

```php
function collect_figure_images( string $html ): array
```

Given an HTML fragment (as found inside `<body>`), return a list (numeric
array) of the decoded `src` values of every `IMG` element that is inside a
`FIGURE` element — at any depth, not only as a direct child — in document
order. Images outside any figure are excluded. Skip `IMG` tags that have
no `src` attribute or whose `src` has no value.

Example:

```php
collect_figure_images( '<figure><img src="in.jpg"></figure><p><img src="out.jpg"></p>' )
// => [ 'in.jpg' ]
```
