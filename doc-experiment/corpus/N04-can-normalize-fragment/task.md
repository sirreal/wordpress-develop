# Check whether HTML can be normalized

Write a single PHP function:

```php
function can_normalize_fragment( string $html ): bool
```

Given an HTML fragment (as found inside `<body>`), determine whether the
HTML API can produce a fully-normalized serialization of it. Some markup —
for example certain misnested formatting elements — is not yet supported
by the HTML Processor, and normalization is not possible; return `false`
for those inputs. Return `true` when normalization succeeds.

Note that markup being malformed does not by itself mean normalization
fails: unclosed tags, implied closing tags, and well-formed tables all
normalize fine.

Examples:

```php
can_normalize_fragment( '<div><p>fine' )                  // => true
can_normalize_fragment( '<table><tr><td>ok</table>' )     // => true
can_normalize_fragment( '<b>one<i>two</b>three</i>' )     // => false (unsupported misnesting)
```
