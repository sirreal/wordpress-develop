# CSS Selector Fuzzer — Next Steps / Improvement Roadmap

> **Status: all seven work items below are implemented and validated** (see
> `README.md`, `COVERAGE.md`, `FINDINGS.md`). The acceptance bar is met:
> coverage measured (93.8%; 96.8% of reachable code, remainder justified);
> three oracles agree on no-quirks supported cases with every divergence
> triaged; metamorphic invariants passing; combinator positive-match rate
> raised from 14.5% to ~68% (path-directed bucket); minimizer working; a clean
> 5000-seed run with all signatures triaged to the three known bugs, all of
> which still reproduce. The notes below are retained as the design rationale.
>
> **Core fixes landed:** the three FINDINGS.md bugs are fixed on this branch
> (`CSS selector:` commits `7419a9fef6` / `0cefeb2fc8` / `16d03e2c5f`), each
> with PHPUnit regression tests. A post-fix 5000-seed run is clean.
>
> **Open follow-up hardening (post-review):** `tests/self-check.php` runs its
> parse-expectation assertions over a fixed seed window (1–400) that, against
> an *unfixed* core, dodges the known core bugs only by seed luck. On this
> branch the bugs are fixed so the collision risk is gone, but the hazard
> returns whenever the tooling runs against a core without the fixes (e.g.
> cherry-picked onto trunk before the fixes land) or when a future unfixed bug
> is found. Decouple self-check from unfixed core bugs — e.g. allowlist known
> signatures in the parse-expectation loop — as a standalone hardening. This is
> worth doing on its own (it makes self-check robust to *any* future generator
> change) and is the prerequisite for randomized class-NUL document injection.
>
> **Candidate finding 4 — FIXED:** per CSS Syntax 3 §4.3.8, `\` followed by
> EOF is a valid escape (EOF is not a newline), and §4.3.7 says consuming it
> returns U+FFFD — so `.foo\` parses as class `foo\u{FFFD}`. Verified against
> lexbor (agrees: `.foo\` matches class `foo\u{FFFD}`; `\` parses as type
> `\u{FFFD}`). Fixed on this branch (`CSS selector:` commit): EOF guard in
> `consume_escaped_codepoint()` returns U+FFFD, `next_two_are_valid_escape()`
> accepts a backslash as the final byte. Review of the fix surfaced a second
> bug in the same family: `normalize_selector_input()` trimmed *trailing*
> whitespace before tokenizing, so `.foo\ ` (escaped space — valid class
> `foo `, matches nothing) and `.foo\<LF>` (invalid escape — must be
> rejected) both collapsed to `.foo\` and matched class `foo\u{FFFD}` — a
> wrong-match-set bug. Fixed by switching to `ltrim()`; the grammar consumes
> insignificant trailing whitespace. Fuzzer updated to match: the lone `\`
> invalid-bucket entry became `\<LF>` (still invalid), and `edge-escape`
> gained an `eof-escape` kind covering `.name\` / `#name\` / `name\`.
>
> **Candidate finding 5 (recorded 2026-06-10, low severity, not fixed):**
> the attribute-selector case modifier is matched byte-wise (`i`/`I`/`s`/`S`
> literals), so an *escaped* modifier ident like `[a=b \69]` (tokenizes to
> the ident `i`) is rejected. Per the Selectors-4 grammar `<attr-modifier> =
> i | s` these are ident tokens, so escapes should arguably be accepted —
> but browsers are themselves inconsistent (Chromium accepts `[a=b \69]`
> and rejects `[a=b \73]`). Fail-safe refusal, not a mis-match; revisit only
> if the matcher ever moves to token-level parsing.
>
> **HTML case-insensitive attribute value list — IMPLEMENTED (2026-06-10):**
> per https://html.spec.whatwg.org/multipage/semantics-other.html#case-sensitivity-of-selectors
> the values of ~46 listed attributes (`type`, `rel`, `lang`, `dir`,
> `media`, ...) match ASCII case-insensitively on HTML elements when the
> selector has no modifier; an explicit `s` still forces sensitivity, and
> elements outside the html namespace are unaffected. Oracle notes from
> verification:
> - **lexbor does not implement the rule at all** (`[rel=nofollow]` does
>   not match `rel="NOFOLLOW"`) — compensated in the differential the same
>   way as lexbor #368 (lexbor is compared against the reference run with
>   the list disabled); candidate upstream report.
> - **Chromium applies the list to foreign elements too** (`[type=TEXT]`
>   matches `<svg><a type="text">`), diverging from the HTML spec's "on an
>   HTML element" scoping. WP follows the spec (html namespace only, via
>   `get_namespace()`). The standalone Tag Processor has no namespace
>   tracking and applies the list to every element — an inherent
>   tag-processor approximation, same as its ancestor-blind matching.
>
> **Session decisions (2026-06-10):** EOF-truncated selectors (`div[a=b`)
> will be made spec-conformant — CSS Syntax auto-closes open blocks at EOF —
> rather than documented as an intentional rejection. HTML's default
> case-insensitive attribute value list will be implemented (no-modifier +
> html-namespace + listed attribute; explicit `s` keeps forcing
> case-sensitivity). Grammar-level truncations (`[`, `[a=`, `div >`, `div,`)
> stay invalid — browsers reject those too. No Trac tickets for any of this.

Repo: `/Users/jonsurrell/a8c/wordpress-develop/html-css-fuzz`, branch
`html-css-fuzz` @ `6ebbcc2fe4` (trunk + merged `html-api/add-css-selector-parser`).
PHP 8.4.21. Everything under `tools/css-selector-fuzz/` is untracked; nothing
committed. `/artifacts` is gitignored (runner output lives there).

## Measured weaknesses driving this plan

- Match oracle is a hand-reimplementation (`ReferenceMatcher`) by the same author
  who could share a spec misreading with WP — no third opinion exists today.
- Positive-match rate is low (measured): supported-compound 39.6% of parseable
  cases match ≥1 element; supported-complex only **14.5%**; ~72% of all supported
  cases match nothing. Most match assertions are vacuous `[] == []`.
- The "structurally safe element set" restriction (needed so `model ==
  parse-tree` holds) means combinator/breadcrumb matching is only ever tested on
  clean trees — never on foster-parented / adoption-agency / foreign-content /
  implied-end-tag restructured trees, which are the hard cases.
- No metamorphic invariants (the cheapest oracle-free signal class) — absent.
- Line coverage never measured. Some target branches are provably unreachable by
  the current generator (e.g. `consume_escaped_codepoint`'s U+FFFD path for
  null/surrogate/over-max codepoints; the `normalize_selector_input` NUL→U+FFFD
  path).
- No automatic minimizer (the sibling `html-api-fuzz` branch ships
  `tools/html-api-fuzz/minimize.php` as a pattern to copy).
- Match path only exercises `WP_HTML_Processor::create_full_parser`; fragment
  contexts and varied quirks-mode triggers (only doctype presence is toggled)
  are untested.

## Work items, in priority order

### 1. Metamorphic invariants (cheapest, highest signal-per-effort, no deps)

Add oracle-free relations to `Worker::run_case` that must hold for any parseable
supported selector over any document. For each, transform the selector, assert
the match set (both processors) is unchanged — or for AST-level ones, assert the
extracted AST is unchanged:

- ASCII-case-fold a type-selector name → identical matches (type names are
  case-insensitive).
- Reorder subclass selectors within a compound (`div.a#b[c]` ≡ any permutation of
  the subclass part) → identical matches and structurally-equivalent AST.
- Escape an arbitrary ident codepoint that does not require escaping → identical
  AST and matches (exercises the escape decoder against the no-op case).
- Append a redundant universal (`sel` vs `sel:where`-free `*sel` where the type
  slot is empty → `*` + subclasses) → identical matches.
- Duplicate a selector-list branch (`a, a`) → identical matches.
- Whitespace-insert around combinators and commas where insignificant → identical
  matches.

These need no external engine; they would have independently caught Bug 1.

### 2. Path-directed generation (fix the 14.5% positive-match rate)

Add a generation mode that GUARANTEES positive matches and meaningful negatives:

- Pick a random element in the generated model tree.
- Synthesize a selector that must match it: type from its tag, subclasses from a
  subset of its real classes/id/attributes, and (for complex) a context chain
  drawn from its real ancestor tags with `>`/descendant combinators matching the
  actual nesting.
- Emit the matching selector (assert it matches that element) AND near-miss
  mutations (swap one ancestor combinator `>`↔descendant, drop/extend a class,
  change one attribute operator) and assert the flip.

This makes the combinator/breadcrumb walker actually exercised with real depth
and real positive/negative boundaries instead of mostly-empty match sets. Keep
the existing buckets; add this as a new bucket.

### 3. lexbor differential oracle (the match-oracle correctness ceiling)

Use lexbor as a THIRD, independent oracle — primarily to validate
`ReferenceMatcher`, secondarily to unlock wilder HTML. Build cost is acceptable
(confirmed by maintainer). Refs: https://lexbor.com/modules/selectors/ and the
HTML module for selector matching.

Design:

- C harness linking liblexbor: read many `{html, selector}` cases from stdin
  (one process, batched — invoked by the runner like the existing PHP worker
  subprocess; isolate crashes). Parse HTML with `lxb_html_document_parse`, parse
  the selector with the CSS/selectors module, run `lxb_selectors_find`, and via
  the callback collect each matched element's unique `data-fid` attribute. Emit
  one line of matched-fid sets per case. FFI to `liblexbor.so` is an acceptable
  alternative but a standalone CLI isolates crashes better.
- Mark every generated element with a unique `data-fid` (the generator already
  does this).
- **Tree-equality gate:** only run the differential on cases where WP's tree and
  lexbor's tree agree (compare the fid→tag→breadcrumb sequence from each). This
  isolates the SELECTOR layer from HTML tree-construction differences (which are
  a different fuzzer's concern — see `html-api-fuzz`). Bonus: this gate lets you
  fuzz ARBITRARY/wild HTML and keep any case where the two trees agree, which
  relaxes the current "safe element set" restriction and reaches restructured
  trees the present generator can't produce.
- Three-way verdict: `reference ≠ lexbor` ⇒ fuzzer-oracle bug (fix the fuzzer);
  `reference == lexbor ≠ WP` ⇒ high-confidence WP finding.

**CRITICAL CAVEAT — quirks-mode / case-sensitivity:** lexbor has a known
class/ID case-sensitivity bug — https://github.com/lexbor/lexbor/issues/368.
WP folds class/ID names ASCII-case-insensitively in QUIRKS mode and
case-sensitively in no-quirks (`WP_HTML_Tag_Processor::is_quirks_mode()`); type
names are always case-insensitive. Do NOT trust lexbor on quirks-mode case
behavior. Restrict the lexbor differential to **no-quirks documents** (emit
`<!DOCTYPE html>`), and keep `ReferenceMatcher` as the authority for the
quirks-mode path. Pin the exact lexbor version used and note whether #368 is
fixed in it. Re-evaluate enabling quirks comparison only after verifying lexbor's
behavior against that issue.

- Also surface (don't auto-fail) **attribute default case-insensitivity**:
  Selectors-4/HTML define a set of attributes matched case-insensitively by
  default; WP appears to implement only explicit `i`/`s` modifiers. lexbor may
  implement the default set, producing divergence that is either a real WP
  conformance gap or an intentional subset limitation — triage per case and
  report.

### 4. Parser-derived oracle tree (decouple "tree right" from "match right")

Instead of asserting `model == parse-tree`, walk the processor ONCE to capture
the ground-truth tree (fid → tag → breadcrumbs → attributes), then run both the
reference matcher and the lexbor differential against arbitrary/wild HTML using
that captured tree as truth for the selector layer. This is the structural
change that makes #3's wild-HTML mode fully general and lets the generator reuse
`html-api-fuzz`'s nasty-HTML generator. (`model-desync` becomes a separate,
optional sanity check rather than a precondition.)

### 5. Coverage measurement + reach the unreachable branches

- Wire line/branch coverage (phpdbg is available: `phpdbg -qrr` with
  coverage, or install pcov/xdebug) over the `src/wp-includes/html-api/css/`
  classes; gate "done" on a coverage target and a written list of intentionally-
  unreached lines.
- Add a generator path emitting raw hex escapes for null / surrogate
  (U+D800–U+DFFF) / over-max (> U+10FFFF) codepoints and assert they decode to
  U+FFFD (currently unreachable — the renderer only escapes real codepoints).
- Fuzz NUL bytes and CR/FF in the selector INPUT to exercise
  `normalize_selector_input` (NUL→U+FFFD, CR/CRLF/FF→LF).

### 6. Automatic minimizer

Port the delta-debugging pattern from `tools/html-api-fuzz/minimize.php`: given a
failing seed, shrink both the HTML and the selector (byte/structural deletes,
keep-failing) to a minimal reproducer. Wire into `replay.php` or a new
`minimize.php`. Bugs 1 and 3 were hand-minimized; automate it.

### 7. Broaden match surface

- Run the match oracle through `create_fragment` with varied fragment contexts,
  not just `create_full_parser`.
- Vary all quirks-mode triggers (not only doctype presence): no-doctype,
  malformed doctype, `<!DOCTYPE html SYSTEM ...>`, limited-quirks doctypes.

## Acceptance bar for "exacting standards"

- Coverage measured and reported for the `css/` classes, with a justified list of
  any unreached lines.
- Three independent oracles agree on no-quirks supported cases (AST round-trip,
  `ReferenceMatcher`, lexbor); divergences are triaged to either a WP finding or
  a fuzzer-oracle fix — never left ambiguous.
- Metamorphic invariants in place and passing.
- Positive-match rate for combinator selectors materially raised (path-directed
  generation): ~68% in that bucket vs ~14% before, so the combinator/breadcrumb
  walker is genuinely exercised. (Aggregate across all buckets remains ~62%
  vacuous `[] == []`, by design — the negative-oriented and parse-focused
  buckets are intentionally mostly empty-set; see `README.md`.)
- Minimizer produces minimal repros automatically.
- A clean multi-thousand-seed run with all signatures triaged; `FINDINGS.md`
  updated with any new bugs (each with a minimal repro and a one-line fix
  direction), and confirmation that the three known bugs still reproduce.

## Existing bugs to keep verifying (regression anchors)

From `FINDINGS.md` — all three are fixed on this branch and pinned by PHPUnit
tests; the minimal repros must now NOT trigger (a clean 5000-seed run confirms):
1. Identity escape after multibyte mis-decodes: `#Ü,\sup #x` → type must be `sup`.
2. Empty-value substring matchers: `[x^=""]`, `[x*=""]`, `[x$=""]` must match nothing.
3. Off-by-one length guard: `[a=b]` (single-char unquoted value, exact `=`, at EOF) must parse.
