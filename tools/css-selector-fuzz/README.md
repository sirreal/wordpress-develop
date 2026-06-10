# CSS Selector Fuzzer

Generative fuzzer for the HTML API CSS selector support:
`WP_CSS_Compound_Selector_List`, `WP_CSS_Complex_Selector_List`, and the
`select()` methods on `WP_HTML_Tag_Processor` and `WP_HTML_Processor`.

Every case is fully deterministic from its integer seed: the same seed always
produces the same document, the same selector, and the same verdict.

## What a case does

1. Generate a random HTML document from a structurally "safe" element set so
   the model tree is provably identical to the parsed tree (this is itself
   verified every case — `model-desync`).
2. Generate a selector in one of seven buckets:
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
3. Check invariants:
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
   - Repeating a case yields a byte-identical result digest (determinism).

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

Run a batch in-process (no isolation, faster):

    php tools/css-selector-fuzz/worker.php --start-seed 1 --count 500

Options of note:

- `runner.php --stop-on-failure` stops at the first failing chunk.
- `worker.php --determinism-every N` re-runs every Nth seed twice (default 16).
- `worker.php --max-failures N` stops a batch after N failures (default 200)
  to bound artifact size.
