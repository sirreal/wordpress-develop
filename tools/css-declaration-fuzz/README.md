# CSS declaration-list fuzzer

This is personal development tooling for the proposed CSS APIs. It is not part
of the WordPress Core proposal and is intentionally kept in a tooling-only
branch.

The target drives `WP_HTML_Style_Attribute_Processor` with decoded CSS
declaration-list text. Because that processor is built on
`WP_CSS_Token_Processor`, every case also exercises the proposed low-level CSS
tokenizer.

## Branch and PR stack

The intended ownership boundary is:

```text
WordPress trunk
└── #92  css-api/css-token-processor-foundations
    └── #78  css-token-style-attr-processor
        └── html-css-fuzz-style-attribute-processor  (personal tooling; do not propose)
```

- [PR #92](https://github.com/sirreal/wordpress-develop/pull/92) is the
  tokenizer and builder foundation.
- [PR #78](https://github.com/sirreal/wordpress-develop/pull/78) is the public
  style-attribute/declaration-list processor proposed on top of that foundation.
- This branch adds only `tools/css-declaration-fuzz/`.
- The older `html-css-fuzz-css-token-processor` branch remains the selector
  fuzzer's historical integration branch. It is useful tooling, but its
  tokenizer lineage is not the exact final #92 snapshot and it should not be
  used as the base for the Core proposal.

## What is checked

Each deterministic seed selects one of four buckets:

- `structured`: valid generated declaration lists with an independent expected
  sequence of property names and priorities;
- `mutated`: structured input damaged by truncation, deletion, duplication,
  control bytes, invalid UTF-8, or syntax insertion;
- `eof-repair`: declarations with functions or blocks implicitly closed at EOF;
- `raw-bytes`: arbitrary bounded byte strings biased toward CSS syntax.

The worker checks:

- tokenizer byte ranges form an exact, gap-free partition of the input;
- raw token slices agree with reported byte offsets;
- tokenization and declaration traversal terminate and are deterministic;
- no-op tokenization and traversal preserve the exact source;
- repeated updates to one URL or string token supersede the earlier update;
- structured declarations match the generated property/priority model;
- filtered traversal agrees with unfiltered traversal, including custom-property
  case sensitivity;
- successful value and priority changes preserve declaration-list structure
  after reparsing and are idempotent;
- mixed set, priority, append, replace, remove, and advance sequences preserve
  the logical cursor and declaration order;
- failed mutations are atomic and leave the cursor in place;
- removal deletes exactly one logical declaration;
- append creates exactly one declaration and performs precise EOF repair;
- unsafe property names, top-level semicolons, top-level `!important`, bad
  strings, and unclosed mutation values are rejected;
- `WP_CSS_Builder::string()` round-trips arbitrary bytes through the tokenizer.

The public processor intentionally has no value getter. The oracle therefore
compares observable declaration names/priorities and mutation behavior, not
property-specific value semantics. It also does not claim browser independence:
the structured model is independent of the declaration-list parser, but both
the target and model ultimately rely on the proposed tokenizer for escape
decoding. A future browser or Lexbor differential would strengthen this.

## Usage

Run the deterministic self-check:

```sh
php tools/css-declaration-fuzz/tests/self-check.php
```

Run bounded, process-isolated fuzzing:

```sh
php tools/css-declaration-fuzz/runner.php \
    --max-seeds 10000 \
    --duration-seconds 120
```

Artifacts are written under `artifacts/css-declaration-fuzz/`:

- `state.json` records the exact branch/SHA, counters, token types, operation
  outcomes, and next seed;
- `failures.ndjson` stores replayable failures with the style bytes base64
  encoded.

Replay a generated seed or exact saved input:

```sh
php tools/css-declaration-fuzz/replay.php --seed 123
php tools/css-declaration-fuzz/replay.php \
    --seed 123 \
    --style-base64 '<value from failures.ndjson>'
```

Minimize a self-contained failure signature:

```sh
php tools/css-declaration-fuzz/minimize.php \
    --seed 123 \
    --signature append-reparse-mismatch
```

Measure target line coverage:

```sh
phpdbg -qrr tools/css-declaration-fuzz/coverage.php --seeds 2000
```

The runner never runs unbounded: `--max-seeds` must be positive. Worker chunks
are isolated so fatal errors and timeouts can be attributed to individual
seeds.
