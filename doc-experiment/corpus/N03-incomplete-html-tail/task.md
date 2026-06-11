# Detect truncated HTML

Write a single PHP function:

```php
function has_incomplete_html_tail( string $html ): bool
```

Determine whether the document was cut off in the middle of an HTML token —
for example, input that ends inside an unfinished tag, an unterminated
comment, or an unclosed `SCRIPT` element whose contents run to the end of
the input. Return `true` when the end of the input falls inside such an
incomplete token; return `false` for input whose tokens are all complete.

Note that some trailing syntax is complete by definition: a lone `<` at the
end of input is just text, and unclosed elements like `<div>text` are
structurally unclosed but lexically complete (every token is whole).

Examples:

```php
has_incomplete_html_tail( '<p>all fine</p>' )        // => false
has_incomplete_html_tail( '<div class="x' )          // => true
has_incomplete_html_tail( '<!-- unfinished comment' ) // => true
has_incomplete_html_tail( '<div>unclosed element' )   // => false
```
