# Round 49 Scratch Variant

Variant name: `html-processor-read-only-completion-policy`

Control round: `round-48`

Edited rendered file: `/tmp/html-api-docs-eval/round-49/html-processor.md`

Source docblocks were not edited. This is a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
6347a6d78c43bc698fde6bbc9e861b9653dafad352f14739810b272f15dec804
```

Changed rendered documentation in one place:

- The class-level DOM-style text recipe now adds a compact read-only
  completion-policy rule of thumb. It distinguishes best-effort extraction
  from complete-source validation and from mutation, normalization, or
  token-rewrite output.

Purpose: test whether making the already-documented caller-policy distinction
more concrete prevents subjects from discarding already visited read-only
extraction results when `paused_at_incomplete_token()` or `get_last_error()`
reports a later scan problem.
