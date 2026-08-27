# CSS Selector Fuzzer

Generative fuzzer for the HTML API CSS selector support:
`WP_CSS_Compound_Selector_List`, `WP_CSS_Complex_Selector_List`, and the
`select()` methods on `WP_HTML_Tag_Processor` and `WP_HTML_Processor`.

Every case is fully deterministic from its integer seed: the same seed always
produces the same document, the same selector, and the same verdict.

## What a case does

1. Generate a random HTML document — 70% from a structurally "safe" element
   set with a known model tree (of these, ~20% are parsed as a `<body>`
   fragment via `create_fragment` instead of a full document, exercising the
   fragment `select()` path), 30% "wild" (misnested, implied-end-tag,
   foreign-content, token soup with one of five doctypes spanning no-quirks,
   quirks, and limited-quirks). `create_fragment` only accepts the `<body>`
   context publicly, so that is the fragment context fuzzed.
2. Capture the processor's own view of the document as the matching oracle's
   ground truth (`TreeCapture`): a flat list of rows in visit order, each
   carrying the element's tag, attributes, and ancestor tag list (context
   selectors are type-only, so that is everything matching can observe).
   For safe documents the capture must agree with the generated model
   (`model-desync`) — that soundness check is what justifies trusting the
   capture on wild documents. Wild documents that hit a construct the
   processor bails on (foster parenting, complex adoption-agency runs) are
   deterministically regenerated a bounded number of times.
3. Generate a selector in one of nine buckets:
   - `supported-compound` — must parse in both grammars; carries intended AST.
   - `supported-complex` — uses `>`/descendant combinators; must parse only
     in the complex grammar; carries intended AST.
   - `path-directed` — synthesized from a real element of the generated tree
     (type from its tag, subclasses from its actual classes/id/attributes,
     context chain from its actual ancestors), guaranteed by construction to
     match that element — or flipped into a near-miss (wrong type/class/attr
     guarantees a non-match; loosening `>` to descendant must keep matching).
     The guarantee is asserted against the reference matcher
     (`path-expectation`). Within this bucket ~67% of match assertions are
     non-vacuous (positive-match rate ~68% for combinator selectors, vs ~14%
     in `supported-complex`). Across *all* buckets ~38% of match assertions
     are non-vacuous: the negative-oriented buckets (`unsupported`,
     `invalid`, much of `supported-*`) and `edge-escape` (which targets the
     parse/escape-decode path, not matching) are intentionally mostly
     empty-set, so the aggregate `[] == []` rate is ~62%. The point of
     path-directed generation is that the *combinator/breadcrumb* walker —
     the part most likely to harbor a matching bug — is now exercised with
     real depth, not that every assertion is non-vacuous.
     `runner.php` persists per-bucket/per-target match assertion counts and
     vacuous/non-vacuous rates under `matchStats` in `state.json`, so this
     distribution is reported on every run instead of relying on stale notes.
   - `unsupported` — valid CSS the API intentionally rejects (pseudo-classes
     and -elements, `+`/`~`/`||` combinators, namespaces, non-type context
     selectors); must not parse.
   - `invalid` — not valid CSS; must not parse.
   - `invalid-utf8` — a small supported selector with a raw ill-formed UTF-8
     byte sequence (lone continuation, truncated 2/3/4-byte, overlong,
     surrogate half, beyond U+10FFFF) injected into a class/ID/attribute
     ident or string operand; `from_selectors()` scrubs the input first, so
     the case must parse and carries the post-scrub AST (one U+FFFD per
     maximal subpart, with per-class subpart counts pinned independently of
     `wp_scrub_utf8()`).
   - `chaos` — arbitrary bytes; no parse expectation.
   - `mutated` — a supported selector with random byte mutations, including
     raw ill-formed UTF-8 splices at arbitrary byte offsets; no parse
     expectation.
   - `edge-escape` — selectors that exercise otherwise-unreachable parser
     branches: hex escapes for NUL / surrogate / over-max codepoints (must
     decode to U+FFFD) and raw NUL / CR / CRLF / FF bytes in the input (must
     normalize during selector token-stream preprocessing); carries the intended AST.
4. Check invariants:
   - No PHP error/warning/exception from parsing or matching, ever.
   - Parse result (instance vs `null`) matches the bucket's expectation.
   - Anything the compound grammar parses, the complex grammar parses, and
     both produce the same AST.
   - Parsed AST equals the generated AST (escapes, strings, whitespace and
     case randomization must not change meaning).
   - For any selector that parses (including chaos/mutated), the `select()`
     match set equals an independent spec-faithful reference matcher, on both
     processors, including quirks-mode class/ID case-insensitivity.
   - For any selector that does not parse, `select()` returns `false`,
     `_doing_it_wrong` fires exactly once per call (also via the parse
     cache), and the processor remains usable.
   - The processor ends with no `get_last_error()`/unsupported state.
   - Metamorphic relations (oracle-free, run on otherwise-clean cases whose
     selector parsed): meaning-preserving transforms of the selector must
     select exactly the same elements as the original, and AST-preserving
     transforms must parse to exactly the transformed AST. Transforms:
     re-render with fresh whitespace/quoting and aggressive no-op escapes,
     ASCII-case-fold of type names, subclass reordering within a compound,
     explicit `*` for an omitted type, and selector-list branch duplication.
     Skipped for ASTs containing invalid UTF-8 (reachable only from
     chaos/mutated inputs), which the renderer cannot round-trip.
   - lexbor differential (third, independent oracle; requires the harness —
     see below): on full-document cases whose selector parsed, the exact
     selector bytes accepted by WP are matched by liblexbor and compared, as a
     multiset of fids, against the reference matcher. Quirks documents
     participate only when the startup probe confirms lexbor's class/#id
     folding behavior in both no-quirks and quirks mode. Gated on WP and
     lexbor building the same element tree (fid/tag/ancestry), so it tests
     the selector layer, not tree construction. Verdicts:
     `lexbor-parse-reject` and `lexbor-divergence` (lexbor ≠ reference) are
     lexbor/fuzzer-oracle problems, with `wpFinding: false` in their details;
     `match-mismatch-html` with no accompanying `lexbor-parse-reject` or
     `lexbor-divergence` means reference == lexbor ≠ WP — a high-confidence WP
     finding.
   - Repeating a case yields a byte-identical result digest (determinism).
     Note the digest covers the WP-under-test surface (selector, html,
     parse-nullness, ASTs, failure invariants) but **not** the lexbor
     oracle's own output, so it would not flag a flaky lexbor result that
     never escalates to a `lexbor-divergence` failure.

## lexbor harness

Build with `sh tools/css-selector-fuzz/lexbor/build.sh` (clones and builds
liblexbor from upstream `master`; the build script prints the exact commit).
The worker auto-detects the binary at `tools/css-selector-fuzz/lexbor/harness`
and reports per-batch tallies, persisted to `state.json` under `lexbor`:

- `compared` — the differential ran to a selector verdict; parser rejects are
  recorded as `lexbor-parse-reject`, and successful parses either match
  fid-multisets or record `lexbor-divergence`.
- `tree-gated` — WP and lexbor built different trees; differential skipped.
- `skipped-quirks` — quirks document while lexbor class/#id case behavior is
  not trusted.
- `n/a` — the differential does not apply (unparseable selector, fragment, no
  captured tree).
- `unavailable` / `error` — the harness was missing or died. The runner prints
  a loud warning if these appear after the harness had run, so a third oracle
  that dies mid-run cannot hide behind a green run.

Known lexbor issues and handling:

- [#368](https://github.com/lexbor/lexbor/issues/368):
  class and `#id` selectors match ASCII case-insensitively even in
  no-quirks documents (`[id=…]` attribute matching is correctly
  case-sensitive). Detected by a startup probe; when present, lexbor is
  compared against the reference matcher run with quirks-style class/ID
  folding, and quirks-mode documents are excluded from the differential
  entirely. The same startup probe also checks class and `#id` selectors in
  quirks mode; only when all four probes pass is quirks-mode class/ID matching
  included in the differential.
- lexbor rejects uppercase `I`/`S` attribute-selector modifiers, and its
  non-ASCII ident-codepoint table omits U+00B7 and U+00C0–U+00F6 (it
  starts at U+00F8), rejecting e.g. `.Über` while accepting `.über`.
  These can now surface as `lexbor-parse-reject` because lexbor receives the
  original selector input; they are candidate upstream reports, not WP
  findings.
- lexbor's invalid-UTF-8 selector decoding differs from WP's
  maximal-subpart scrub. These cases can surface as parser rejects or
  divergences, and remain lexbor/fuzzer-oracle noise unless independently
  confirmed against WP.
- `lxb_selectors_find` reports a node once per matching selector-list
  branch; `LXB_SELECTORS_OPT_MATCH_FIRST` dedupes.
- lexbor matches `[x~=""]` against whitespace-only attribute values
  (e.g. `x=" "`); Selectors-4 and Chrome say an empty operand never
  matches a list item, and WP agrees with them. Latent
  `lexbor-divergence` noise source if the generator ever pairs `~=""`
  with whitespace-valued attributes; candidate upstream report, not a
  WP finding.

## Known oracle limitations (document-side decoding)

The match oracle's independence differs between class and attribute selectors:

- **Class values are matched by two genuinely independent tokenizers.** WP's
  `select('.x')` goes through `WP_HTML_Tag_Processor::class_list()`, which
  splits on ASCII whitespace and folds NUL → U+FFFD per token;
  `ReferenceMatcher::class_matches()` reimplements that independently (and is
  pinned against `class_list()` on NUL/FF boundary inputs by `self-check.php`).
  The safe and wild random document generators now inject NUL into class
  tokens occasionally and expose the decoded U+FFFD token to class-selector
  generation. Raw class attribute values are intentionally kept out of the
  generic `attrValues` pool so attribute-selector generation does not inherit
  class-list-only decoding semantics.
- **Attribute values are matched through a single shared read.** Both WP's
  attribute matcher and `ReferenceMatcher::attr_matches()` read the same
  `get_attribute()` output, so a value-decoding bug there would be shared and
  invisible regardless of input — a genuine shared-oracle limitation that no
  generator change can close (it needs an independent attribute-value decoder,
  which lexbor partly provides on no-quirks documents).

## Usage

Bounded fuzz run (process-isolated chunks, crash/hang attribution):

    php tools/css-selector-fuzz/runner.php --max-seeds 1000 --duration-seconds 60

Artifacts go to `artifacts/css-selector-fuzz/run-*/` and are intentionally
small: `state.json` (counters, per-signature tallies) and `failures.ndjson`
(one line per failure, with base64 selector + document for offline analysis).

Replay a failure by seed:

    php tools/css-selector-fuzz/replay.php --seed 42 --show-html
    php tools/css-selector-fuzz/replay.php --seed 42 --json

Probe a specific selector:

    php tools/css-selector-fuzz/replay.php --selector 'section > div.cls' --html '<section><div class=cls></div></section>'

Minimize a failing case to a small reproducer (delta-debugging; shrinks
both the selector and the HTML while preserving a failure signature):

    php tools/css-selector-fuzz/minimize.php --seed 1234
    php tools/css-selector-fuzz/minimize.php --selector 'sel' --html '<…>' --signature match-mismatch

The minimizer drives `Worker::run_pair`, which checks only **self-contained**
invariants — those computable from the (selector, html) pair without the
generator's intended AST: `match-mismatch-*`, `metamorphic-*`,
`lexbor-divergence`, `parse-error`, `ast-shape`, `ast-cross-grammar`, and the
rejection checks. The generator-side invariants `ast-mismatch`,
`parse-expectation`, `path-expectation`, and `model-desync` are **not**
self-contained and cannot be reproduced from the pair alone.

So `--seed` faithfully minimizes only seeds whose failure is self-contained.
The three known bugs each *also* surface a self-contained signature (Bug 1 →
`metamorphic-ast`, Bug 2 → `match-mismatch-html`, Bug 3 → `metamorphic-parse`),
but a seed whose recorded failure is *only* the generator-side form (e.g. a
Bug-1 seed that recorded `ast-mismatch` before the metamorphic phase ran) is
**refused by default** rather than silently retargeted — pass `--signature`
to opt into minimizing a related self-contained signature, which is then
clearly labelled as a retarget in the output.

Run a batch in-process (no isolation, faster):

    php tools/css-selector-fuzz/worker.php --start-seed 1 --count 500

Measure line coverage of the `css/` classes (see `COVERAGE.md` for the
current report and a justified list of unreached lines):

    phpdbg -qrr tools/css-selector-fuzz/coverage.php --seeds 3000 --list-uncovered

Options of note:

- `runner.php --stop-on-failure` stops at the first failing chunk.
- `worker.php --determinism-every N` re-runs every Nth seed twice (default 16).
- `worker.php --max-failures N` stops a batch after N failures (default 200)
  to bound artifact size.
