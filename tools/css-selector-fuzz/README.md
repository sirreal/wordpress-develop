# CSS Selector Fuzzer

Generative fuzzer for the HTML API CSS selector support:
`WP_CSS_Compound_Selector_List`, `WP_CSS_Complex_Selector_List`, and the
`select()` methods on `WP_HTML_Tag_Processor` and `WP_HTML_Processor`.

Every case is fully deterministic from its integer seed: the same seed always
produces the same document, the same selector, and the same verdict.

## What a case does

1. Generate a random HTML document — 70% from a structurally "safe" element
   set with a known model tree, 30% "wild" (misnested, implied-end-tag,
   foreign-content, varied-doctype token soup with no model).
2. Capture the processor's own view of the document as the matching oracle's
   ground truth (`TreeCapture`): a flat list of rows in visit order, each
   carrying the element's tag, attributes, and ancestor tag list (context
   selectors are type-only, so that is everything matching can observe).
   For safe documents the capture must agree with the generated model
   (`model-desync`) — that soundness check is what justifies trusting the
   capture on wild documents. Wild documents that hit a construct the
   processor bails on (foster parenting, complex adoption-agency runs) are
   deterministically regenerated a bounded number of times.
3. Generate a selector in one of seven buckets:
   - `supported-compound` — must parse in both grammars; carries intended AST.
   - `supported-complex` — uses `>`/descendant combinators; must parse only
     in the complex grammar; carries intended AST.
   - `path-directed` — synthesized from a real element of the generated tree
     (type from its tag, subclasses from its actual classes/id/attributes,
     context chain from its actual ancestors), guaranteed by construction to
     match that element — or flipped into a near-miss (wrong type/class/attr
     guarantees a non-match; loosening `>` to descendant must keep matching).
     The guarantee is asserted against the reference matcher
     (`path-expectation`), making most match assertions non-vacuous:
     measured positive-match rate for combinator selectors is ~68% in this
     bucket vs ~14% in `supported-complex`.
   - `unsupported` — valid CSS the API intentionally rejects (pseudo-classes
     and -elements, `+`/`~`/`||` combinators, namespaces, non-type context
     selectors); must not parse.
   - `invalid` — not valid CSS; must not parse.
   - `chaos` — arbitrary bytes; no parse expectation.
   - `mutated` — a supported selector with random byte mutations; no parse
     expectation.
   - `edge-escape` — selectors that exercise otherwise-unreachable parser
     branches: hex escapes for NUL / surrogate / over-max codepoints (must
     decode to U+FFFD) and raw NUL / CR / CRLF / FF bytes in the input (must
     normalize per `normalize_selector_input`); carries the intended AST.
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
     see below): on no-quirks documents whose selector parsed, a canonical
     re-render of the verified AST is matched by liblexbor and compared,
     as a multiset of fids, against the reference matcher. Gated on WP and
     lexbor building the same element tree (fid/tag/ancestry), so it tests
     the selector layer, not tree construction. Verdicts: `lexbor-divergence`
     (lexbor ≠ reference) is a fuzzer-oracle problem; `match-mismatch-html`
     with no accompanying divergence means reference == lexbor ≠ WP — a
     high-confidence WP finding.
   - Repeating a case yields a byte-identical result digest (determinism).

## lexbor harness

Build with `sh tools/css-selector-fuzz/lexbor/build.sh` (clones and builds
liblexbor, pinned to v3.0.0 = `2ae88a1c6b52`). The worker auto-detects the
binary at `tools/css-selector-fuzz/lexbor/harness` and reports per-batch
tallies (`compared` / `tree-gated` / `skipped-quirks` / `off`).

Known lexbor issues compensated for at this pin:

- [#368](https://github.com/lexbor/lexbor/issues/368) (open at v3.0.0):
  class and `#id` selectors match ASCII case-insensitively even in
  no-quirks documents (`[id=…]` attribute matching is correctly
  case-sensitive). Detected by a startup probe; when present, lexbor is
  compared against the reference matcher run with quirks-style class/ID
  folding, and quirks-mode documents are excluded from the differential
  entirely (the reference matcher is the sole quirks authority).
- lexbor rejects uppercase `I`/`S` attribute-selector modifiers, and its
  non-ASCII ident-codepoint table omits U+00B7 and U+00C0–U+00F6 (it
  starts at U+00F8), rejecting e.g. `.Über` while accepting `.über`.
  Both sidestepped by the canonical re-render (lowercase modifiers, all
  non-ASCII hex-escaped); both are candidate upstream reports, not WP
  findings.
- `lxb_selectors_find` reports a node once per matching selector-list
  branch; `LXB_SELECTORS_OPT_MATCH_FIRST` dedupes.

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

The minimizer drives `Worker::run_pair`, which checks only self-contained
invariants — those computable from the (selector, html) pair without the
generator's intended AST. All three known bugs reduce to one: Bug 1 →
`metamorphic-ast`, Bug 2 → `match-mismatch-html`, Bug 3 → `metamorphic-parse`.

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
