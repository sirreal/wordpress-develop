# Round 28 Scratch Variant

Variant name: `ordinary-text-negative-example`

Control round: `round-27`

Edited rendered file: `/tmp/html-api-docs-eval/round-28/html-processor.md`

Source docblocks were not edited. This is a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
d35fbe30fdfbcc3cae6ba83be8edc104a7630ad217a5ab08e817cbb6a14aabc8
```

Inserted under `##### Recipe: collect DOM-style text from a subtree` after
the `#text` accumulation example:

````markdown
Default policy: ordinary subtree text is not "every token with modifiable text." It is only the `#text` tokens reached by the walk. For example, in `<section>A<em>B</em><script>C</script><textarea>D</textarea></section>`, ordinary subtree text is `AB`: inline markup may split text across multiple `#text` tokens, but SCRIPT and TEXTAREA do not add ordinary `#text` descendants.

Opt-in policy: when the caller's contract explicitly asks for a special element's content, whitelist those opening element tokens and read their {@see WP_HTML_Tag_Processor::get_modifiable_text}. TITLE and TEXTAREA provide decoded text on their opener tokens; SCRIPT and STYLE provide raw script or stylesheet text. Do not include special element opener text merely because it is available.

Negative example:

```php
// Too broad for ordinary subtree or heading text: this can read comments,
// processing instructions, and special-element opener text.
if ( null !== $processor->get_modifiable_text() ) {
    $text .= $processor->get_modifiable_text();
}
```
````

Purpose: test whether a default-first negative example reduces
special-element opener text over-inclusion in ordinary heading/subtree text
without regressing tasks that explicitly ask for TITLE/TEXTAREA text.
