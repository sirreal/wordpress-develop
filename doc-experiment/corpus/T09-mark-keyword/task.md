# Highlight a keyword in text

Write a single PHP function:

```php
function mark_keyword( string $html, string $keyword ): string
```

Given an HTML fragment (as found inside `<body>`) and a non-empty keyword,
return a **normalized** serialization of the fragment in which every text
node whose decoded text contains the keyword (case-sensitive substring
match) is wrapped in a `<mark>` element. The entire text node is wrapped,
not just the matching substring.

Notes:

- The match is against the decoded text, so a keyword spelled with
  character references in the source still matches.
- Keywords appearing inside attribute values, comments, or split across
  multiple text nodes do not match.
- Text stored directly on special text-bearing elements such as
  `<textarea>`, `<title>`, `<script>`, and `<style>` is not wrapped for
  this task; only ordinary text nodes are wrappable.
- The output is normalized HTML: optional tags are closed, attribute values
  are double-quoted, and text re-encodes characters like `&` canonically.
  Apart from the added `<mark>` wrappers it is exactly the normalized form
  of the input.

Examples:

```php
mark_keyword( '<p>hello world', 'world' )
// => '<p><mark>hello world</mark></p>'
//    (the whole text node is wrapped, and the open <p> is closed)

mark_keyword( '<p>wor<em>ld</em></p>', 'world' )
// => '<p>wor<em>ld</em></p>'
//    (no single text node contains the keyword)
```
