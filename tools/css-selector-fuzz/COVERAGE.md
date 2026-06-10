# CSS Selector Fuzzer — Coverage Report

Line coverage of `src/wp-includes/html-api/css/` under the fuzzer, measured
with phpdbg's opcode log over 3000 deterministic seeds:

    phpdbg -qrr tools/css-selector-fuzz/coverage.php --seeds 3000 --list-uncovered

| file | covered / executable | % |
|---|---|---|
| class-wp-css-attribute-selector.php       | 102 / 112 | 91.1% |
| class-wp-css-class-selector.php           |  10 / 10  | 100%  |
| class-wp-css-complex-selector-list.php    |  16 / 16  | 100%  |
| class-wp-css-complex-selector.php         |  59 / 66  | 89.4% |
| class-wp-css-compound-selector-list.php   |  27 / 28  | 96.4% |
| class-wp-css-compound-selector.php        |  29 / 32  | 90.6% |
| class-wp-css-id-selector.php              |  12 / 12  | 100%  |
| class-wp-css-selector-parser-matcher.php  | 106 / 108 | 98.1% |
| class-wp-css-type-selector.php            |  15 / 17  | 88.2% |
| **TOTAL**                                 | **376 / 401** | **93.8%** |

The 25 unreached lines are all accounted for below. Twelve are a phpdbg
measurement artifact (the code executes); the other thirteen are defensive
guards that the public entry points cannot reach. Effective coverage of
reachable code is **388 / 401 = 96.8%**.

## phpdbg `case`-label artifact (12 lines — code executes)

phpdbg attributes a `switch` arm's execution to the body line, not the bare
`case X:` label line. The fuzzer exercises every one of these arms (verified
directly: the body line immediately after each label is covered, and the
lexbor differential + self-check confirm the corresponding behavior). These
are not real gaps:

- `class-wp-css-attribute-selector.php`
  - 287, 291, 295, 299, 303 — the `~= |= ^= $= *=` matcher operators.
  - 330, 331, 336, 337 — the `i`/`I`/`s`/`S` case modifiers.
- `class-wp-css-compound-selector.php`
  - 120, 122, 124 — the `.` / `#` / `[` subclass-selector dispatch.

## Defensive guards unreachable from the public API (13 lines)

These are internal precondition checks that the calling code already
guarantees, or branches for grammar the parser never emits:

- `class-wp-css-attribute-selector.php:257` — `return null` when the first
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
- `class-wp-css-selector-parser-matcher.php:130` — `parse_string()` EOF
  guard; every caller checks bounds and the opening quote before calling.
- `class-wp-css-selector-parser-matcher.php:429` —
  `check_if_three_code_points_would_start_an_ident_sequence()` EOF guard;
  callers bound-check first.
- `class-wp-css-type-selector.php:45` — `return false` when `get_tag()` is
  null during matching; matching only runs on resolved element tokens.
- `class-wp-css-type-selector.php:75` — `parse()` EOF guard; the compound
  parser checks `offset < strlen` before calling.

## Notes on what raised coverage

- The `edge-escape` bucket drives the U+FFFD escape-decoder branch
  (`consume_escaped_codepoint` for NUL / surrogate / over-max codepoints)
  and the `normalize_selector_input` NUL→U+FFFD and CR/CRLF/FF→LF paths,
  which the structural generators cannot reach.
- A few `invalid`-bucket templates (`[ a`, `[a="x\`, `a.`) were added to reach
  attribute / string / class parse guards that random structural generation
  rarely lands on. With them the per-file numbers above are **deterministic**
  at the documented 3000-seed window (e.g. `class-wp-css-class-selector.php`
  reaches 10/10 reliably rather than depending on whether a bare `.` happened
  to be sampled).
