# Collect all links

Write a single PHP function:

```php
function collect_links( string $html ): array
```

Given an HTML fragment (as found inside `<body>`), return a list (numeric
array) describing every `A` tag whose `href` attribute has a string value,
in document order. Each entry is an associative array:

- `'href'`: the attribute's decoded value as the HTML API reports it
  (a string).
- `'text'`: the link's text content — all text nodes inside the `A`
  element concatenated, character references decoded, markup contributing
  nothing.

`A` tags without an `href` attribute, or with an `href` written without a
value, are excluded. Return an empty array when there are no links.

Example:

```php
collect_links( '<p><a href="/a">First</a> <a href>skip</a> <a href="/b"><em>second</em> link</a></p>' )
// => [ ['href' => '/a', 'text' => 'First'], ['href' => '/b', 'text' => 'second link'] ]
```
