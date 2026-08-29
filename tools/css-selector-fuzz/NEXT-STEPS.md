# CSS Selector Fuzzer — Next Steps / Improvement Roadmap

> **Status: all seven work items below are implemented and validated** (see
> `README.md`, `COVERAGE.md`, `FINDINGS.md`). The acceptance bar is met:
> coverage measured (93.4%; 96.2% effective — `COVERAGE.md` is the source
> of truth for the current numbers, remainder justified);
> three oracles agree on no-quirks supported cases with every divergence
> triaged; metamorphic invariants passing; combinator positive-match rate
> raised from 14.5% to ~68% (path-directed bucket); minimizer working; a clean
> 5000-seed run with all signatures triaged to the three known bugs, all of
> which still reproduce. The notes below are retained as the design rationale.
>
> **Core fixes landed:** the three FINDINGS.md bugs are fixed on this branch
> (`CSS selector:` commits `aed6cfb4aa` / `989e18da8a` / `0a87b20178`), each
> with PHPUnit regression tests. A post-fix 5000-seed run is clean.
>
> **Current lexbor differential behavior (2026-06-15):** lexbor now receives
> the exact selector bytes accepted by WP, not a canonical re-render of the
> parsed AST. `lexbor-parse-reject` remains classified as lexbor/fuzzer-oracle
> noise, never as a WP finding by itself. Historical notes below that say
> canonical re-rendering sidestepped lexbor parser bugs describe the earlier
> differential behavior.
>
> **Fuzzer-side follow-up hardening implemented (2026-06-12):**
> `tests/self-check.php` now allowlists known core parse-bug signatures in its
> fixed seed-window parse-expectation loop, while unknown mismatches still fail.
> The safe and wild document generators now inject NUL into random class tokens
> and expose the decoded U+FFFD token to class-selector generation, without
> leaking raw class values into the generic attribute-value pool. The lexbor
> differential includes quirks documents whenever the startup probe confirms
> class/#id behavior in both no-quirks and quirks mode (local master-built
> harness `3a2d595fe8c50e5076ac79c02b2ded79a777bb52` passes), and `runner.php`
> reports per-bucket/per-target vacuous and non-vacuous match assertion rates
> under `matchStats`.
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
> **EOF auto-close for attribute selectors — IMPLEMENTED (2026-06-10):**
> per CSS Syntax 3 §5.4.8/§4.3.5, the end of input closes an unterminated
> simple block (and an unterminated string), so `[att=val`, `[att`,
> `[att="a b`, and `[att=val i` are valid selectors; grammar-level
> truncations (`[`, `[a=`, `[a~`, `[a=b, div`) stay invalid. Verified
> against Chromium form-by-form, including an exhaustive per-byte
> truncation table in review. lexbor rejects all EOF-truncated forms
> (drafted as `lexbor/UPSTREAM-ISSUES.md` issue 4). Historical note: the
> differential was unaffected at the time because it compared canonical
> re-renders; current behavior feeds lexbor the original selector bytes, so
> these are expected `lexbor-parse-reject` noise, not WP findings. Fuzzer
> gained an `eof-truncated` edge-escape kind and the invalid corpus was
> reshuffled along the new validity boundary; COVERAGE.md regenerated.
>
> **Invalid-UTF-8 input policy — IMPLEMENTED as scrub (2026-06-11):**
> selector strings are UTF-8 text; `normalize_selector_input()` now decodes
> the byte stream first via `wp_scrub_utf8()` (WP 6.9, maximal-subpart
> U+FFFD replacement, matching the WHATWG decoder CSS Syntax §3.2 invokes),
> and reports a `_doing_it_wrong()` (named `<class>::from_selectors`) when
> the input changed. The `mb_substitute_character()` leak in
> `consume_escaped_codepoint()` is gone structurally: the identity arm's
> `mb_substr()` fallback is replaced by "consume the maximal subpart the
> `_wp_scan_utf8()` scan already reported, return one U+FFFD" — reachable
> only via direct `parse()` calls with un-normalized input, and consistent
> with the scrub when it is. Decision history: reject (`wp_is_valid_utf8()`
> → null) and raw passthrough were rejected after a three-persona
> adversarial panel; scrub is the unique option stable under both the
> current raw value getters and their likely scrubbed future. The U+2603
> canary in `wpCssSelectorParserMatcher.php` set_up() is retained
> permanently — its job inverted from documenting the leak to proving
> setting-independence. Worker.php learned the notice contract (scrub
> notice expected iff `!wp_is_valid_utf8(selector)`) and flushes the
> `select()` parse caches before each notice-assertion window so the
> once-per-parse notice is deterministic under case re-runs.
> **Linked obligation:** the select-level pin
> `test_select_scrubbed_selector_does_not_match_raw_invalid_document_bytes`
> documents that scrubbed selectors cannot match raw invalid document
> bytes; if the HTML API value getters (`get_attribute()`, `class_list()`,
> …) are ever changed to scrub their return values, that case flips to a
> match and the pin must be updated in the same change.
> **Optional follow-up:** tightening the `parse()` prototype from public to
> protected (the classes are `@access private`) would make un-normalized
> input structurally impossible and let the defensive escape arm be
> deleted.
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
>   the list disabled); drafted as `lexbor/UPSTREAM-ISSUES.md` issue 5.
> - The case-flip generator twist in `path_attr_feature` makes the folding
>   load-bearing for `mustMatchFid` (mutation-tested: disabling the core
>   branch fires 11 failures in 3000 seeds). Minor leftovers from review:
>   the unused `expected_*_processor_matches` back-compat helpers in
>   `ReferenceMatcher` silently default rows to the html namespace — fine
>   today (the safe model generator emits no foreign content) but a trap
>   for a future caller; and `s`-forces-sensitivity only gets differential
>   coverage when sampled values happen to differ in case (pinned by unit
>   tests instead).
> - **Chromium applies the list to foreign elements too** (`[type=TEXT]`
>   matches `<svg><a type="text">`), diverging from the HTML spec's "on an
>   HTML element" scoping. WP follows the spec (html namespace only, via
>   `get_namespace()`). The standalone Tag Processor has no namespace
>   tracking and applies the list to every element — an inherent
>   tag-processor approximation, same as its ancestor-blind matching.
>
> **Session decisions (2026-06-10, both since implemented — see the
> IMPLEMENTED entries above):** EOF-truncated selectors (`div[a=b`) are
> spec-conformant (CSS Syntax auto-closes open blocks at EOF) rather than
> documented as an intentional rejection. HTML's default case-insensitive
> attribute value list is implemented (no-modifier + html-namespace +
> listed attribute; explicit `s` keeps forcing case-sensitivity).
> Grammar-level truncations (`[`, `[a=`, `div >`, `div,`) stay invalid —
> browsers reject those too. No Trac tickets for any of this.
>
> **O(1) identity-escape decode — IMPLEMENTED (2026-06-11, perf only):**
> `consume_escaped_codepoint()`'s identity arm no longer copies the input
> tail per escape (`mb_substr( substr( … ) )`); it sizes the code point in
> place with `_wp_scan_utf8( $input, $at, $invalid_length, 4, 1 )`
> (`compat-utf8.php`, WP 6.9). 200KB all-escape selector: 180 ms → 45 ms,
> scaling now linear (47/90/180 ms at 200/400/800KB; previously ~4× per
> doubling). Behavior is byte-identical by construction: escapes of
> *invalid* UTF-8 still fall through to the literal old `mb_substr()` line
> (re-verified ~74M differential cases, 0 mismatches, including non-default
> `mb_substitute_character` settings), so the open invalid-UTF-8 policy
> decision is untouched — and that fallback path remains quadratic for
> selectors made of escaped invalid bytes (accepted; developer-supplied
> input). Caution recorded in-code: `_wp_utf8_codepoint_span()` looks like
> the natural helper but passes `max_bytes = null`, making its ASCII
> fast-path O(tail) per call — quadratic again. Escape pin coverage grew to
> 14 cases (2/3/4-byte chars incl. at-EOF, NUL, each invalid-byte class).
> (Superseded the same day for invalid bytes: the `mb_substr()` fallback and
> its quadratic tail were removed by the scrub implementation — see the
> invalid-UTF-8 policy entry above.)
>
> **Fuzzer coverage for the scrub surface — IMPLEMENTED (2026-06-11):**
> the deferred coverage work for the invalid-UTF-8 scrub landed in three
> pieces. (1) A dedicated `invalid-utf8` generator bucket injects raw
> ill-formed sequences into class/ID/attribute-name idents and quoted
> string operands and carries the post-scrub AST; the per-class maximal-
> subpart U+FFFD counts are pinned independently of `wp_scrub_utf8()`
> (self-check additionally duplicates the class names and byte values, so
> a deleted or drifted table entry fails instead of shrinking the
> assertion). (2) A `mutated`-bucket splice kind inserts raw ill-formed
> sequences at arbitrary byte offsets — no expectations, but it makes the
> worker's invalid-UTF-8 rejection branch hot (scrub + two `select()`
> notices), which no other bucket reached. (3) The explicit lexbor probe:
> lexbor accepts raw invalid selector bytes and replaces them with U+FFFD,
> but NOT per the WHATWG maximal-subpart rule — one U+FFFD per byte for
> truncated sequences (`E2 8C` → 2, spec 1) and one per whole sequence for
> UTF-8-encoded surrogate halves (`ED A0 80` → 1, spec 3) — drafted as
> `lexbor/UPSTREAM-ISSUES.md` issue 6. Historical note: the differential
> used to stay live for the bucket by feeding lexbor the canonical re-render
> of the post-scrub AST (escaped, pure ASCII), the same mechanism that
> sidestepped lexbor's other byte-level parsing bugs. Current behavior feeds
> lexbor the original selector bytes, so these known decoding differences
> surface as lexbor/fuzzer-oracle noise. Doc-side observation:
> lexbor keeps raw invalid bytes in the DOM unchanged (same stance as the
> Tag Processor), so raw doc bytes match nothing in either engine. The
> handoff's optional metamorphic relation `parse(s) === parse(scrub(s))`
> was skipped deliberately: it is near-tautological (it could only catch
> a `from_selectors()` bypass, and no public path bypasses it).
>
> **Still open:** `gen_chaos()`'s whole-codepoint `unicode` branch is dead
> code — it compares the alphabet *string* against the key `'unicode'` after
> the value lookup already happened — so the unicode alphabet is byte-sliced
> by the generic fallback instead. That slicing is what makes chaos emit
> invalid UTF-8 organically (~15% of chaos cases), so making the branch live
> is a behavior decision, not just a cleanup: it would remove chaos's organic
> ill-formed-byte production, leaving the deliberate paths (`invalid-utf8`
> bucket, `mutated` splice) plus `mutated`'s residual organic corruption of
> pool multibyte characters (~2% of mutated cases even without the splice).

Repo: `/Users/jonsurrell/a8c/wordpress-develop/html-css-fuzz`, branch
`html-css-fuzz` (trunk + merged `html-api/add-css-selector-parser`).
PHP 8.4.21. The fuzzer and all fixes are committed on this branch
(`CSS selector:` / `CSS selector fuzz:` prefixed commits). `/artifacts` is
gitignored (runner output lives there).

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
quirks-mode path. Record the exact lexbor master commit used and note whether
#368 is fixed in it. Re-evaluate enabling quirks comparison only after verifying
lexbor's behavior against that issue.

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
