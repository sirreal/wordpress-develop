# Extract the document title

Write a single PHP function:

```php
function get_document_title( string $html ): ?string
```

Given a complete HTML document, return the text of its `<title>` element,
or `null` if the document has no `<title>` element. An existing but empty
`<title></title>` returns the empty string, not `null`.

Example:

```php
get_document_title( '<!DOCTYPE html><html><head><title>My Site &mdash; Home</title></head><body></body></html>' )
// => 'My Site — Home'
```
