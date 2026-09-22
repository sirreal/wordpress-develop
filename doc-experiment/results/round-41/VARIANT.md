# Round 41 Scratch Variant

Variant name: `html-processor-serialization-fallback-policy-card`

Control round: `round-40`

Edited rendered file: `/tmp/html-api-docs-eval/round-41/html-processor.md`

Source docblocks were not edited. This is a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
4aba1668246294ef9130b083b13360c9a12f7a6cfe54276b2bf9fe2e9470a76c
```

Changed rendered documentation in three places:

- `WP_HTML_Processor::create_fragment()` now says `null` means no processor
  was created, while a non-null processor can still later abort and should be
  checked with `get_last_error()` after the relevant scan.
- `WP_HTML_Processor::normalize()` now says it normalizes the original
  fragment and is not a way to finish a token-by-token rewrite; normalizing
  the original input discards emitted rewrite changes.
- `WP_HTML_Processor::serialize_token()` now has an explicit fallback-policy
  card: accumulated output is the rewrite, `serialize()` after scanning
  returns `null`, raw original input is not normalized output, non-null
  `get_last_error()` is unsupported parser abort, and
  `paused_at_incomplete_token()` is a separate complete-input policy check.

Purpose: test whether method-local fallback guidance improves transfer in
normalized-output tasks where subjects previously improvised raw-input or
`normalize( $html )` fallbacks after token-by-token rewriting.
