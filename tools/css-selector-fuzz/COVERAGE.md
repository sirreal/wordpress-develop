# CSS Selector Fuzzer — Coverage Report

Line coverage of `src/wp-includes/html-api/css/` under the fuzzer, measured
with phpdbg's opcode log over 3000 deterministic seeds:

    phpdbg -qrr tools/css-selector-fuzz/coverage.php --seeds 3000 --list-uncovered

| file | covered / executable | % |
|---|---|---|
| class-wp-css-attribute-selector.php       | 108 / 119 | 90.8% |
| class-wp-css-class-selector.php           |  10 / 10  | 100%  |
| class-wp-css-complex-selector-list.php    |  16 / 16  | 100%  |
| class-wp-css-complex-selector.php         |  59 / 66  | 89.4% |
| class-wp-css-compound-selector-list.php   |  27 / 28  | 96.4% |
| class-wp-css-compound-selector.php        |  29 / 32  | 90.6% |
| class-wp-css-id-selector.php              |  12 / 12  | 100%  |
| class-wp-css-selector-parser-matcher.php  | 120 / 124 | 96.8% |
| class-wp-css-type-selector.php            |  15 / 17  | 88.2% |
| **TOTAL**                                 | **396 / 424** | **93.4%** |

The 28 unreached lines are all accounted for below: twelve are a phpdbg
measurement artifact (the code executes), twelve are defensive guards the
public entry points cannot reach, two are the escape decoder's
invalid-byte arm that the input scrub made unreachable through
`from_selectors()`, and two are reachable lines this seed window happens
to miss. Counting the artifact lines as covered, effective coverage is
**408 / 424 = 96.2%**.

(Executable-line totals grew from 408 to 424 with the case-insensitive
attribute value list and the invalid-UTF-8 scrub changes.)

## phpdbg `case`-label artifact (12 lines — code executes)

phpdbg attributes a `switch` arm's execution to the body line, not the bare
`case X:` label line. The fuzzer exercises every one of these arms (verified
directly: the body line immediately after each label is covered, and the
lexbor differential + self-check confirm the corresponding behavior). These
are not real gaps:

- `class-wp-css-attribute-selector.php`
  - 378, 382, 386, 390, 394 — the `~= |= ^= $= *=` matcher operators.
  - 419, 420, 425, 426 — the `i`/`I`/`s`/`S` case modifiers.
- `class-wp-css-compound-selector.php`
  - 120, 122, 124 — the `.` / `#` / `[` subclass-selector dispatch.

## Defensive guards unreachable from the public API (12 lines)

These are internal precondition checks that the calling code already
guarantees, or branches for grammar the parser never emits:

- `class-wp-css-attribute-selector.php:351` — `return null` when the first
  byte is not `[`. `parse()` is only ever called by
  `parse_subclass_selector()` *after* it has matched `[`, so the guard never
  fires.
- `class-wp-css-complex-selector.php:170–179` — the `_doing_it_wrong`
  "unsupported combinator" arm in the match walker. The parser only ever
  stores `' '` (descendant) or `'>'` (child) combinators, so the match-time
  default arm is dead defensively.
- `class-wp-css-compound-selector-list.php:107` — `return false` when the
  processor is not on a `#tag` token. `select()` only invokes matching while
  positioned on a tag; reachable only by calling `matches()` directly off a
  non-tag token.
- `class-wp-css-selector-parser-matcher.php:375` —
  `next_two_are_valid_escape()` EOF guard; every caller either bound-checks
  first or only calls it on a known backslash byte.
- `class-wp-css-type-selector.php:45` — `return false` when `get_tag()` is
  null during matching; matching only runs on resolved element tokens.
- `class-wp-css-type-selector.php:75` — `parse()` EOF guard; the compound
  parser checks `offset < strlen` before calling.

## Un-normalized input only (2 lines — pinned by PHPUnit)

- `class-wp-css-selector-parser-matcher.php:287–288` — the escape decoder's
  invalid-byte arm (consume the maximal subpart `_wp_scan_utf8()` reported,
  return one U+FFFD). The invalid-UTF-8 scrub in `normalize_selector_input()`
  made this arm structurally unreachable through `from_selectors()` — the
  fuzzer's only entry point — and it exists for direct `parse()` callers
  with un-normalized input. The escape pins in
  `tests/phpunit/tests/html-api/wpCssSelectorParserMatcher.php` exercise it
  for every invalid-byte decode class under the U+2603 canary.

## Reachable, but missed by this seed window (2 lines)

Both lines are demonstrably reachable (witnesses verified directly under
phpdbg) but sit behind enough generator coin flips that a fixed 3000-seed
window may or may not sample them; earlier revisions of this report saw
them flicker in and out across windows:

- `class-wp-css-attribute-selector.php:345` — the `[x` minimum-length
  guard; witness: `[` (also `.a[`, `a[`) at the end of input.
- `class-wp-css-selector-parser-matcher.php:149` — `parse_string()`'s
  break when plain string content runs to end of input; witness: `[a="b`
  (the `eof-truncated` edge-escape kind reaches it only when its quote-drop
  and no-backslash coins both land, ~1 expected case per 3000 seeds).

## Notes on what raised coverage

- The `edge-escape` bucket drives the U+FFFD escape-decoder branch
  (`consume_escaped_codepoint` for NUL / surrogate / over-max codepoints)
  and the `normalize_selector_input` NUL→U+FFFD and CR/CRLF/FF→LF paths,
  which the structural generators cannot reach. Its `eof-escape` kind covers
  the backslash-at-end-of-input → U+FFFD decode, and its `eof-truncated`
  kind covers the EOF auto-close paths in the attribute parser, including
  unterminated strings (with and without a trailing "do nothing" backslash,
  which keeps the `parse_string` backslash-at-EOF arm exercised).
- The `invalid-utf8` bucket and the `mutated` bucket's raw-byte splice
  drive the `wp_scrub_utf8()` replacement branch and its
  `_doing_it_wrong()` notice in `normalize_selector_input()`
  deterministically (previously hit only organically — `chaos`
  byte-slicing its multibyte `unicode` alphabet, `mutated` corrupting the
  pools' few multibyte characters), and the splice makes the
  unparseable-and-invalid-UTF-8 notice ordering in the worker hot.
- A few `invalid`-bucket templates (`[a=`, `[a~`, `[a="b<LF>c`, `a.`) reach
  attribute / string / class parse guards that random structural generation
  rarely lands on. With them the per-file numbers above are stable
  at the documented 3000-seed window (e.g. `class-wp-css-class-selector.php`
  reaches 10/10 reliably rather than depending on whether a bare `.` happened
  to be sampled) — apart from the two borderline-frequency lines listed
  above.
