# Mark paragraphs inside blockquotes

Write a single PHP function:

```php
function mark_quoted_paragraphs( string $html ): string
```

Given an HTML fragment (as found inside `<body>`), add the class `quoted` to
every `P` element that has a `BLOCKQUOTE` ancestor anywhere above it (not
only as the direct parent). Return the modified HTML; everything else must
be preserved byte-for-byte. Paragraphs outside any blockquote must not be
modified.

Example:

```php
mark_quoted_paragraphs( '<blockquote><p>Quoted.</p></blockquote><p>Not quoted.</p>' )
// => '<blockquote><p class="quoted">Quoted.</p></blockquote><p>Not quoted.</p>'
```
