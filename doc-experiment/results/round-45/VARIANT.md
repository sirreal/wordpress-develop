# Round 45 Scratch Variant

Variant name: `html-processor-text-policy-decision-table`

Control round: `round-44`

Edited rendered file: `/tmp/html-api-docs-eval/round-45/html-processor.md`

Source docblocks were not edited. This is a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
dbec31d2a26f4223bfa3509950485bd0cafa67b7acfb971ec7d28df15fa4e0a3
```

Changed rendered documentation in three places:

- The class-level DOM-style text recipe now has a compact policy table:
  ordinary subtree text uses only `#text`; special-element opener text is an
  explicit opt-in with decoded/raw behavior called out; and read-only
  extraction fallback policy is separated from mutation, normalization, and
  token-rewrite fail-closed policy.
- The `next_token()` special-element paragraph now frames SCRIPT, STYLE,
  TITLE, and TEXTAREA opener-carried text as opt-in data for that element's
  own contents, not ordinary heading, table-cell, link, or article text.
- The inherited `get_modifiable_text()` section now states that it is not a
  predicate for ordinary text nodes: ordinary DOM-style extraction should
  first require `get_token_type() === '#text'`.

Purpose: test whether a compact decision table and method-local opt-in
reminders improve transfer for text extraction tasks where subjects
over-include special-element opener text or discard read-only accumulated
results after incomplete/unsupported trailing input.
