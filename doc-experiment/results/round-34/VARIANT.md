# Round 34 Scratch Variant

Variant name: `html-processor-depth-bounded-traversal-card`

Control round: `round-33`

Edited rendered file: `/tmp/html-api-docs-eval/round-34/html-processor.md`

Source docblocks were not edited. This is a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
4a4e64bbb3c43c248cb948ca752a01674a3dedc4eb77843d6fb7e63ea0a1f6ea
```

Inserted after `##### Recipe: scan a region before editing its opener` and
before `##### Recipe: collect DOM-style text from a subtree`:

````markdown
##### Recipe: test subtree membership and direct children

When a container opener is matched, record its current depth before advancing.
Later tokens belong to that container while their depth is greater than or
equal to the recorded depth. The first token reported at a shallower depth
means the walk has moved past the container.

To recognize a direct child element opener inside that subtree, require all
three checks:

```php
$is_direct_child_opener =
    '#tag' === $processor->get_token_type() &&
    ! $processor->is_tag_closer() &&
    $processor->get_current_depth() === $container_depth + 1;
```

Do not count closing tags as child elements. A child closer reports the parent
depth, not the child depth, so a depth comparison alone is not enough.

For repeated regions, prefer one {@see WP_HTML_Processor::next_token} loop
with explicit state over nested `next_token()` loops. An inner loop consumes
tokens from the same cursor and can skip the next sibling or region boundary
that the outer loop expected to see.
````

Purpose: test whether a generic class-level traversal card improves
depth-bounded subtree work, direct-child detection, and repeated-region token
loop choices without editing source docblocks.
