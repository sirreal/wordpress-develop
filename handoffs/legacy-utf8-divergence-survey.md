# Handoff: one-shot divergence survey of legacy UTF-8 helpers

## Status

Not started. Deliverable is a **document**, not code and not a
continuous fuzzer.

## Premise

`src/wp-includes/formatting.php` contains older UTF-8 helpers that
overlap with the new strict functions in `src/wp-includes/utf8.php`:

- `seems_utf8( $str )` (formatting.php:884) — loose structural
  heuristic, predates `wp_is_valid_utf8()`.
- `wp_check_invalid_utf8( $text, $strip )` (formatting.php:1127) —
  PCRE-based, charset-option dependent, with a `$strip` mode.

These are *intentionally loose*; a continuous differential against
`wp_is_valid_utf8()` would report their known sloppiness forever and
train people to ignore the fuzzer. What's actually useful is a
one-time, well-organized map of exactly where they diverge from the
strict functions — as input for deprecation/migration decisions and
docblock updates.

## Method

1. Reuse the generator and battery from `tools/encoding-fuzz/`
   (`lib/Generator.php`, `Oracles::battery()`) to drive a few million
   inputs through `seems_utf8`, `wp_check_invalid_utf8` (both `$strip`
   modes), and `wp_is_valid_utf8` side by side. A throwaway script in
   the same style as `tools/encoding-fuzz/worker.php` is fine; it does
   not need to be committed.
2. Bucket divergences by *class*, not by input: e.g. "seems_utf8
   accepts overlong encodings", "accepts surrogates", "accepts
   code points above U+10FFFF", "wp_check_invalid_utf8 returns ''
   instead of stripping when X". Minimize one representative per class
   (2–4 bytes each, by hand or with the encoding fuzzer's minimizer
   predicate pattern).
3. Note environment sensitivity: `wp_check_invalid_utf8` consults the
   blog charset (`get_option( 'blog_charset' )`) — it needs either a WP
   test bootstrap or careful stubbing; document which path was tested.
   This is the reason these functions were excluded from the encoding
   fuzzer in the first place.
4. Cross-check each divergence class against the functions' docblocks
   and original Trac tickets (`git log -L` on the functions; Trac
   search for `seems_utf8`) to separate "documented, intentional
   looseness" from "nobody ever decided this".

## Deliverable

A single markdown report (suggested:
`handoffs/legacy-utf8-divergence-report.md`, or a Trac ticket comment)
containing:

- a divergence matrix: input class × function → accept/reject/output,
  with minimal byte examples
- for each class: intentional vs accidental, with evidence
- migration guidance: for each current core caller of `seems_utf8` /
  `wp_check_invalid_utf8` (grep the callers), whether
  `wp_is_valid_utf8` / `wp_scrub_utf8` is a drop-in, a
  behavior-changing replacement, or unsuitable
- explicit recommendation per function: deprecate, document, or leave

## Non-goals

No code changes to formatting.php, no continuous fuzzing of these
functions, no "fixing" divergences before the survey establishes which
ones are load-bearing for existing content (a stricter check that
rejects bytes previously accepted can break saved posts on upgrade —
flag any such case prominently).
