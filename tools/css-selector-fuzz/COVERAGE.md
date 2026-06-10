# CSS Selector Fuzzer — Coverage Report

Line coverage of `src/wp-includes/html-api/css/` under the fuzzer, measured
with phpdbg's opcode log over 3000 deterministic seeds:

    phpdbg -qrr tools/css-selector-fuzz/coverage.php --seeds 3000 --list-uncovered

| file | covered / executable | % |
|---|---|---|
| class-wp-css-attribute-selector.php       | 106 / 116 | 91.4% |
| class-wp-css-class-selector.php           |  10 / 10  | 100%  |
| class-wp-css-complex-selector-list.php    |  16 / 16  | 100%  |
| class-wp-css-complex-selector.php         |  59 / 66  | 89.4% |
| class-wp-css-compound-selector-list.php   |  27 / 28  | 96.4% |
| class-wp-css-compound-selector.php        |  29 / 32  | 90.6% |
| class-wp-css-id-selector.php              |  12 / 12  | 100%  |
| class-wp-css-selector-parser-matcher.php  | 110 / 111 | 99.1% |
| class-wp-css-type-selector.php            |  15 / 17  | 88.2% |
| **TOTAL**                                 | **384 / 408** | **94.1%** |

The 24 unreached lines are all accounted for below. Twelve are a phpdbg
measurement artifact (the code executes); the other twelve are defensive
guards that the public entry points cannot reach. Effective coverage of
reachable code is **396 / 408 = 97.1%**.

(Executable-line totals grew from 401 to 408 with the EOF-escape and
EOF-auto-close changes; two parser-matcher EOF guards that used to be
unreachable defensive lines are now genuinely exercised — see below.)

## phpdbg `case`-label artifact (12 lines — code executes)

phpdbg attributes a `switch` arm's execution to the body line, not the bare
`case X:` label line. The fuzzer exercises every one of these arms (verified
directly: the body line immediately after each label is covered, and the
lexbor differential + self-check confirm the corresponding behavior). These
are not real gaps:

- `class-wp-css-attribute-selector.php`
  - 309, 313, 317, 321, 325 — the `~= |= ^= $= *=` matcher operators.
  - 350, 351, 356, 357 — the `i`/`I`/`s`/`S` case modifiers.
- `class-wp-css-compound-selector.php`
  - 120, 122, 124 — the `.` / `#` / `[` subclass-selector dispatch.

## Defensive guards unreachable from the public API (12 lines)

These are internal precondition checks that the calling code already
guarantees, or branches for grammar the parser never emits:

- `class-wp-css-attribute-selector.php:282` — `return null` when the first
  byte is not `[`. `parse()` is only ever called by
  `parse_subclass_selector()` *after* it has matched `[`, so the guard never
  fires.
- `class-wp-css-complex-selector.php:170–179` — the `_doing_it_wrong`
  "unsupported combinator" arm in the match walker. The parser only ever
  stores `' '` (descendant) or `'>'` (child) combinators, so the match-time
  default arm is dead defensively.
- `class-wp-css-compound-selector-list.php:87` — `return false` when the
  processor is not on a `#tag` token. `select()` only invokes matching while
  positioned on a tag; reachable only by calling `matches()` directly off a
  non-tag token.
- `class-wp-css-selector-parser-matcher.php:351` —
  `next_two_are_valid_escape()` EOF guard; every caller either bound-checks
  first or only calls it on a known backslash byte.
- `class-wp-css-type-selector.php:45` — `return false` when `get_tag()` is
  null during matching; matching only runs on resolved element tokens.
- `class-wp-css-type-selector.php:75` — `parse()` EOF guard; the compound
  parser checks `offset < strlen` before calling.

Two guards documented here in earlier revisions are now genuinely covered:
the `parse_string()` EOF guard and the
`check_if_three_code_points_would_start_an_ident_sequence()` EOF guard are
both reached since EOF auto-close lets `[a=` call the value parsers at the
end of input.

## Notes on what raised coverage

- The `edge-escape` bucket drives the U+FFFD escape-decoder branch
  (`consume_escaped_codepoint` for NUL / surrogate / over-max codepoints)
  and the `normalize_selector_input` NUL→U+FFFD and CR/CRLF/FF→LF paths,
  which the structural generators cannot reach. Its `eof-escape` kind covers
  the backslash-at-end-of-input → U+FFFD decode, and its `eof-truncated`
  kind covers the EOF auto-close paths in the attribute parser, including
  unterminated strings (with and without a trailing "do nothing" backslash,
  which keeps the `parse_string` backslash-at-EOF arm exercised).
- A few `invalid`-bucket templates (`[a=`, `[a~`, `[a="b<LF>c`, `a.`) reach
  attribute / string / class parse guards that random structural generation
  rarely lands on. With them the per-file numbers above are **deterministic**
  at the documented 3000-seed window (e.g. `class-wp-css-class-selector.php`
  reaches 10/10 reliably rather than depending on whether a bare `.` happened
  to be sampled).
