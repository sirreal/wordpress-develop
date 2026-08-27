# CSS Selector Fuzzer — Findings

Run: branch `html-css-fuzz` @ `5da3afedd0`, PHP 8.4.21. 5000 deterministic
seeds, 0 crashes/timeouts. Three distinct, reproduced WordPress-core correctness
bugs in the new HTML-API CSS selector support. Every selector below is valid,
supported CSS that the API mis-handles **without** reporting lack of support.

**Status: all three bugs are fixed on this branch** (commit prefix
`CSS selector:` — Bug 1 `aed6cfb4aa`, Bug 2 `989e18da8a`, Bug 3 `0a87b20178`),
each with PHPUnit regression tests that fail pre-fix. A post-fix 5000-seed run
is clean (0 failures, 0 crashes). The repros below no longer trigger; they
remain as regression anchors and Trac-ready minimal test cases.

No new bugs surfaced beyond these three, and no fuzzer-side (oracle or
generator) defect surfaced: with all three fixes applied a 5000-seed run is
completely clean, and the lexbor differential (third independent oracle) agreed
with the reference matcher on every compared no-quirks case (0 `lexbor-divergence`).
Caveats on the strength of that agreement: roughly half of the `compared`
cases (and ~62% of all match assertions across buckets) are vacuous `[] == []`;
older lexbor builds with #368 exclude quirks-mode class/ID matching from the
differential, though current harnesses include it when the startup probe reports
reliable class/#id behavior in both no-quirks and quirks mode. See `README.md`
for the full disclosure.

Reproduce any case: `php tools/css-selector-fuzz/replay.php --selector '<sel>' [--html '<html>']`.
Auto-minimize a failing seed: `php tools/css-selector-fuzz/minimize.php --seed <seed>`
(faithful for seeds with a self-contained failure; seeds whose only recorded
failure is generator-side — `ast-mismatch`, `parse-expectation` — are refused
unless a related self-contained signature is opted into with `--signature`).

---

## Bug 1 — Identity escapes mis-decode after a multibyte character (mis-parse)

**Invariant:** `ast-mismatch` (57 hits, the dominant signature).

`WP_CSS_Selector_Parser_Matcher::consume_escaped_codepoint()` decodes a
non-hex ("identity") escape with:

```php
$codepoint_char = mb_substr( $input, $offset, 1, 'UTF-8' );
```

`$offset` is a **byte** offset (threaded by reference through the whole
selector-string parse), but `mb_substr()`'s 2nd argument is a **character**
index. The two diverge by one per multibyte continuation byte seen earlier in
the string, so an identity escape preceded by any multibyte content decodes the
**wrong codepoint** (reads N characters too far right, N = preceding continuation bytes).

Minimal reproduction (second selector's type should be `sup`):

| selector | parsed context type |
|---|---|
| `#abc,\sup #x`  | `sup` ✅ |
| `#Ü,\sup #x`    | `uup` ❌ |
| `#ÜÜ,\sup #x`   | `pup` ❌ |
| `#ÜÜÜ,\sup #x`  | `" up"` ❌ |

Hex escapes (`\75 `) use byte-correct `substr` and are unaffected; only the
non-hex identity-escape branch is wrong. Depending on what wrong codepoint is
produced this also causes spurious parse failures (a valid selector returns
`null`).

**Fix (landed in `aed6cfb4aa`):** read the next codepoint from the byte
offset: `mb_substr( substr( $input, $offset ), 0, 1, 'UTF-8' )`.

---

## Bug 2 — Empty-value `^=` `*=` `$=` match everything instead of nothing (mis-match)

**Invariant:** `match-mismatch-html` / `match-mismatch-tag` (12 hits).

Per Selectors-4, `[attr^=""]`, `[attr*=""]`, `[attr$=""]` match **nothing** (an
empty operand never matches). `WP_CSS_Attribute_Selector::matches()` instead:

- `^=`: `substr_compare($attr,'',0,0) === 0` → always 0 → matches any element with the attribute.
- `*=`: `strpos($attr,'') === 0` (PHP) → matches any element with the attribute.
- `$=`: matches elements whose attribute value is exactly `""`.

`~=` is handled correctly (returns nothing for an empty/whitespace operand).

Reproduction against `<i x="">…</i><b x="abc">…</b><u>…</u>`:

| selector | WP matches | spec |
|---|---|---|
| `[x^=""]` | `I, B` | none |
| `[x*=""]` | `I, B` | none |
| `[x$=""]` | `I`    | none |
| `[x~=""]` | none ✅ | none |

**Fix (landed in `989e18da8a`):** in `matches()`, return `false` for `^= $= *=`
when `'' === $this->value`, before the `substr_compare`/`strpos` calls. `~=`
needs no guard — a whitespace-delimited list never yields an empty item — and
a test pins that. (No `substr_compare` length edge exists here: `-strlen('')`
is `0`, and PHP clamps out-of-range negative offsets rather than erroring.)

---

## Bug 3 — Off-by-one length guard rejects `[name=x]` at end of string (false reject)

**Invariant:** `parse-expectation` (1 hit; valid selector → `null`).

`WP_CSS_Attribute_Selector::parse()` guards "need at least `=x]` remaining":

```php
// need to match at least `=x]` at this point
if ( $updated_offset + 3 >= strlen( $input ) ) {
    return null;
}
```

`>=` is off by one: it also rejects the exact-fit case where `=x]` **is** the
remaining tail. This rejects a valid attribute selector that uses the exact-match
`=` operator with a single-character **unquoted** value when its `]` is the last
character of the selector string.

| selector | result |
|---|---|
| `[a=b]`        | `null` ❌ |
| `div.x[y=z]`   | `null` ❌ |
| `[a=bb]`       | parsed ✅ (2-char value) |
| `[a="b"]`      | parsed ✅ (quoted) |
| `[a^=b]`       | parsed ✅ (2-char operator) |
| `[a=b].c`      | parsed ✅ (trailing content) |

**Fix (landed in `0a87b20178`):** change `>=` to `>` (need
`strlen - $updated_offset >= 3`).

---

## Triage of the 5000-seed run (unpatched core)

427 failures, every one attributable to one of the three bugs above. The
signature → bug mapping (and why each is a WP finding, not a fuzzer defect):

| signature | hits | bug | how it manifests |
|---|---|---|---|
| `metamorphic-ast` (5 variants) | 328 | Bug 1 | a re-rendered / escaped variant of a selector parses to a different AST because an identity escape after multibyte content mis-decodes |
| `ast-mismatch` | 71 | Bug 1 | generated AST ≠ parsed AST, same root cause |
| `path-expectation` | 1 | Bug 1 | a path-directed selector with a multibyte-then-identity-escape value (`Über90\ x`) mis-parses, so the element it was built from no longer matches |
| `metamorphic-parse` (4 variants) | 9 | Bug 3 | a re-rendered variant ending in a single-char unquoted value at EOF is wrongly rejected |
| `parse-expectation` (2 variants) | 7 | Bug 3 | the generated selector itself ends in `=x]` and is wrongly rejected (e.g. `[dir =a]`) |
| `match-mismatch-html` | 7 | Bug 2 | empty-operand `^= *= $=` match elements the spec says they must not |
| `match-mismatch-tag` | 4 | Bug 2 | same, via the tag processor |

Zero `lexbor-divergence`, zero `model-desync`, zero crashes/timeouts. With all
three fixes applied, the same 5000 seeds run with **0 failures** — confirming
the fuzzer reports exactly these three bugs and nothing spurious.

## Fuzzer status

Implemented and validated:

- Deterministic seeds, seed-based replay, self-check suite
  (`php tools/css-selector-fuzz/tests/self-check.php` passes).
- Seven-bucket selector generation including **path-directed** synthesis
  (combinator positive-match rate ~68% vs ~14% before) and **edge-escape**
  (U+FFFD escape decoder, input normalization).
- Three independent match oracles: the spec-faithful `ReferenceMatcher`, the
  AST round-trip, and a **lexbor differential** (liblexbor v3.0.0, no-quirks
  documents, tree-equality gated). The three agree on every compared case.
- **Metamorphic invariants** (oracle-free): meaning-preserving transforms keep
  the match set; AST-preserving transforms keep the AST.
- **Parser-derived oracle tree** (`TreeCapture`): the processor's own parse is
  ground truth, so **wild / restructured HTML** and **`<body>` fragments** are
  fuzzed, not only clean trees.
- **Line coverage** measured (93.4%, see `COVERAGE.md` — the source of truth
  for current numbers; 96.2% effective, remainder justified).
- **Automatic minimizer** (`minimize.php`): delta-debugs selector and HTML to a
  minimal reproducer preserving a chosen signature.

See `README.md` for usage and `NEXT-STEPS.md` for the roadmap this work
completed.
