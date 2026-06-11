# WP_HTML_Decoder Fuzzer

Differential fuzzer for `WP_HTML_Decoder`:

- `decode_text_node()`
- `decode_attribute()`
- `read_character_reference()`
- `attribute_starts_with()`

The fuzzer runs in a bare PHP process. It loads only `WP_Token_Map`, the
generated HTML5 named-character-reference map, and `WP_HTML_Decoder`; it does
not bootstrap WordPress, a database, browsers, Node, or `wp-env`.

## Requirements

- PHP 8.4+ with `Dom\HTMLDocument`
- `mbstring`
- Run from the repository root

## Oracle

The primary oracle is PHP's HTML5 parser:

- Text context: parse `<!DOCTYPE html><body><div>PAYLOAD</div>` and read the
  div's `textContent`.
- Attribute context: parse `<div title="PAYLOAD">` and read
  `getAttribute( 'title' )`.

`html_entity_decode( ENT_HTML5 )` is deliberately not used as the primary
oracle because it does not implement the HTML attribute-context rule for
semicolonless named references followed by `=` or an alphanumeric byte.

In the default `oracle` mode, the generator neutralizes parser-vs-decoder
confounders by producing valid UTF-8 payloads with no raw `<`, no raw double
quote, no CR, and no NUL. This keeps the DOM parser focused on
character-reference decoding instead of tag structure, attribute termination,
input-preprocessing newline normalization, or NUL substitution.

The separate `bytes` mode deliberately generates arbitrary byte payloads,
including invalid UTF-8, NUL, raw `<`, raw double quote, and CR. These payloads
never go to the DOM oracle. They run only oracle-free decoder invariants.

## Checks

For each generated payload, the fuzzer runs both text and attribute contexts:

1. Compare `decode_text_node()` or `decode_attribute()` to the DOM oracle.
2. Rebuild the decoded string with repeated `read_character_reference()` calls
   plus literal spans, then compare it to the high-level decoder.
3. Assert every matched character reference reports a positive byte length and
   does not overrun the input.
4. Check `attribute_starts_with()` against the decoded attribute prefix for
   ASCII search strings in both case-sensitive and ASCII-case-insensitive modes,
   including monotonic prefix, extension, and case-sensitivity invariants.
5. Assert decoded output is valid UTF-8.
6. Assert text without `&` is an identity decode.

In `bytes` mode, checks 1, 4, and 5 are skipped because they depend on
DOM-safe UTF-8 payloads or a DOM-derived decoded attribute value. The lane keeps
the reader rebuild, advance/overrun, and no-`&` identity checks for both text
and attribute contexts.

Decoding is not treated as idempotent; `&amp;amp;` should decode only one level
to `&amp;`.

## Generator

Every case is determined by `(seed, case index)`. Generated cases run in both
text and attribute contexts so the same payload exercises semicolonless and
attribute-disambiguation differences side by side. The generator preserves the
former context PRNG draw, so the earlier both-context lane change did not by
itself shift payload mapping.
Adding or reweighting generation strategies intentionally changes future
`--seed --case` payload mapping; failure-manifest replay remains stable because
manifests store `payload_base64`.

The generator uses the real generated named-reference map, with weighted
strategies for:

- exact named references
- semicolonless legacy references
- attribute-context ambiguous followers
- numeric decimal and hex references, including C1 controls, surrogates,
  noncharacters, zero, overflow, and leading zeros
- adjacent references
- truncation sweeps
- references ending at EOF, including bare introducers, partial numeric
  references, semicolonless numeric references, and truncated names
- multibyte UTF-8 around references
- `attribute_starts_with()` prefixes such as encoded `javascript:`
- nonexistent lookalikes and ampersand boundaries
- plain no-ampersand text

`bytes` mode uses separate weighted strategies for uniform random bytes,
no-ampersand byte strings, arbitrary bytes around `&` boundaries, invalid UTF-8
sequences, and raw HTML delimiters/control bytes.

## Common Commands

Run the smoke test:

```sh
php tools/html-decoder-fuzz/tests/harness-smoke.php
```

Run one worker batch:

```sh
php tools/html-decoder-fuzz/worker.php --seed 1 --cases 5000
```

Run one oracle-free arbitrary-byte worker batch:

```sh
php tools/html-decoder-fuzz/worker.php --mode bytes --seed 1 --cases 5000
```

Run parallel lanes for one minute:

```sh
php tools/html-decoder-fuzz/runner.php --lanes 4 --duration-seconds 60
```

Run indefinitely:

```sh
php tools/html-decoder-fuzz/runner.php --lanes 8 --duration-seconds 0 --max-cases 0
```

Long runs keep disk use bounded by default. The runner records aggregate
counters in `state.json`, writes only newly retained failure exemplars plus
oracle/fatal events to `summary.ndjson`, and retains at most five failure
artifact directories for each distinct signature. Per-lane stderr logs are
capped at 64 KiB each, including reused output directories with existing
oversized lane logs. Repeated over-cap failures remain counted in `state.json`
without growing the event log.

When startup verification is unavailable, the runner preserves complete
existing artifacts instead of pruning them to the cap; without the verifier it
cannot safely distinguish stale or fake full-shape manifests from valuable
findings.

Useful retention options:

```sh
# Preserve the previous verbose event log.
php tools/html-decoder-fuzz/runner.php --summary-mode all

# Keep only one on-disk exemplar per signature.
php tools/html-decoder-fuzz/runner.php --max-artifacts-per-signature 1

# Prune every failure artifact and rely on state counters/signatures.
php tools/html-decoder-fuzz/runner.php --artifact-retention none

# Keep every failure artifact for a short diagnostic run.
php tools/html-decoder-fuzz/runner.php --artifact-retention all

# Raise or disable per-lane stderr capture.
php tools/html-decoder-fuzz/runner.php --max-stderr-bytes 262144
php tools/html-decoder-fuzz/runner.php --max-stderr-bytes 0
```

Replay a failure, an input file, or a generated case:

```sh
php tools/html-decoder-fuzz/replay.php --failure artifacts/html-decoder-fuzz/run-.../failure-seedS-caseN/failure.json
php tools/html-decoder-fuzz/replay.php --input payload.txt --context attribute
php tools/html-decoder-fuzz/replay.php --seed 123 --case 45
php tools/html-decoder-fuzz/replay.php --mode bytes --seed 123 --case 45
```

Minimize a failure while preserving its signature:

```sh
php tools/html-decoder-fuzz/minimize.php --failure artifacts/html-decoder-fuzz/run-.../failure-seedS-caseN/failure.json
```

Exit codes everywhere: `0` clean, `1` findings, `2` harness error.

## Artifacts

The runner writes under `artifacts/html-decoder-fuzz/run-*` by default:

- `summary.ndjson` with retained failure exemplars plus oracle/fatal events by
  default (`--summary-mode all` preserves every worker event;
  `--summary-mode none` disables the file)
- `state.json` with aggregate counters, stop reason, Git metadata, and failure
  seeds for retained exemplars, including retained/pruned artifact counts by
  signature
- per-lane stderr logs, capped by `--max-stderr-bytes`
- retained failure directories containing `payload.txt` and a self-contained
  `failure.json` with base64 payload, context, signatures, failure details,
  full expected/got output as base64 for differential failures, environment
  metadata, and Git metadata. By default retention is capped per signature;
  use `--artifact-retention all` to keep every directory.

## Harness Self-Test

`tests/harness-smoke.php` verifies the DOM oracle battery, real target behavior
on the battery, generator determinism and safety, a short real fuzz run, and
mutation-tested broken targets:

- C1 numeric references not remapped through the Windows-1252 table
- semicolonless named references decoded in attributes despite ambiguous
  followers
- off-by-one `read_character_reference()` match lengths
- partial-prefix `attribute_starts_with()` matches
- non-monotonic `attribute_starts_with()` prefix, extension, and
  case-sensitivity results
- raw byte payloads without `&` not decoding identically

For end-to-end failure-pipeline checks, set `HTML_DECODER_FUZZ_FAULT` to one of
`skip-c1-remap`, `attribute-semicolonless`, `match-length-off-by-one`, or
`byte-no-amp-identity`, `attribute-prefix-monotonicity`,
`attribute-extension-monotonicity`, or `attribute-case-monotonicity` before
running `worker.php`, `runner.php`, `replay.php`, or `minimize.php`.
