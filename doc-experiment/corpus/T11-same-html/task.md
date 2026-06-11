# Compare two HTML fragments

Write a single PHP function:

```php
function is_same_html( string $a, string $b ): bool
```

Given two HTML fragments (as found inside `<body>`), determine whether they
represent the same parsed structure — that is, whether a browser would
build the same DOM from both. Differences in attribute quoting style,
optional/implied closing tags, tag-name case, and equivalent character
references do not change the structure. Differences in attribute **order**,
element structure, attribute values, or text content do.

If either input cannot be fully parsed/represented, return `false`.

Examples:

```php
is_same_html( '<div><p>a', '<DIV><p>a</p></div>' )          // => true
is_same_html( "<a href=x>go</a>", '<a href="x">go</a>' )    // => true
is_same_html( '<p>a</p>', '<p>b</p>' )                      // => false
```
