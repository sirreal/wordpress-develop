# Round 31 Scratch Variant

Variant name: `html-processor-next-tag-cursor-card`

Control round: `round-30`

Edited rendered file: `/tmp/html-api-docs-eval/round-31/html-processor.md`

Source docblocks were not edited. This is a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
6b15f5fc0b65a35c3fedc0a464c19d1ae015fb4457f0ed294c1050b9c22663f0
```

Inserted under `### next_tag()` immediately after the summary sentence:

````markdown
> **Cursor-relative searches**
>
> `next_tag()` searches forward from the processor's current cursor. A `false`
> return means no later matching tag was found; it does not reset the cursor,
> and a later call with a different query will not rescan tags already passed.
> In a query, `tag_name` is one tag name string, or `null` for any tag; it is
> not a list of names.
>
> To find the first of several tag names from the current position, scan for
> any tag and branch on `get_tag()`:
>
> ```php
> $wanted = array( 'UL', 'OL' );
> while ( $processor->next_tag() ) {
>     if ( in_array( $processor->get_tag(), $wanted, true ) ) {
>         break;
>     }
> }
> ```
>
> When code intentionally needs to revisit earlier tags, set a bookmark before
> scanning and `seek()` back to it, or create a new processor for the same HTML.
````

Purpose: test whether local HTML Processor `next_tag()` placement prevents
sequential filtered-search mistakes and teaches the first-of-several-tags
idiom without editing source docblocks.
