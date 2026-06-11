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
- `pcov` for `coverage` mode
- Run from the repository root

## Oracle

The primary oracle is PHP's HTML5 parser:

- Text context: parse `<!DOCTYPE html><body><div>PAYLOAD</div>` and read the
  div's `textContent`.
- Attribute context: parse `<div title="PAYLOAD">` and read
  `getAttribute( 'title' )`.

For text context, `html_entity_decode( ENT_HTML5 | ENT_QUOTES, 'UTF-8' )` also
runs as a secondary oracle on payloads whose references it supports:
known semicolon-terminated named references and literal text. Numeric
references, unknown named-looking references, and semicolonless named-looking
references stay with the DOM oracle and fuzzer
invariants, because `html_entity_decode()` does not implement those parser
states. `html_entity_decode()` is deliberately not used as the primary oracle or
as an attribute-context oracle because it does not implement the HTML
attribute-context rule for semicolonless named references followed by `=` or an
alphanumeric byte.

In the default `oracle` mode, the generator neutralizes parser-vs-decoder
confounders by producing valid UTF-8 payloads with no raw `<`, no raw double
quote, no CR, and no NUL. This keeps the DOM parser focused on
character-reference decoding instead of tag structure, attribute termination,
input-preprocessing newline normalization, or NUL substitution.

The separate `bytes` mode deliberately generates arbitrary byte payloads,
including invalid UTF-8, NUL, raw `<`, raw double quote, and CR. These payloads
never go to the DOM oracle. They run only oracle-free decoder invariants.

The separate `names` mode deterministically sweeps every generated named
character reference base name with and without `;`, followed by representative
end, alphanumeric, equals, punctuation, whitespace, and multibyte followers.

## Checks

For each generated payload, the fuzzer runs both text and attribute contexts:

1. Compare `decode_text_node()` or `decode_attribute()` to the DOM oracle, and
   compare supported text payloads to the secondary `html_entity_decode()`
   oracle.
2. Rebuild the decoded string with repeated `read_character_reference()` calls
   plus literal spans, then compare it to the high-level decoder.
3. Assert every matched character reference reports a nonempty chunk, a byte
   length of at least two, no input overrun, and the same chunk/length when
   the matched slice is read again at offset zero.
4. Check `attribute_starts_with()` against the decoded attribute prefix for
   ASCII search strings in both case-sensitive and ASCII-case-insensitive modes,
   leading byte-slice prefixes that can end inside UTF-8 replacements, and
   monotonic prefix, extension, and case-sensitivity invariants.
5. Assert decoded output is valid UTF-8.
6. Assert known nested ampersand fixtures decode exactly one level, so
   `&amp;amp;` decodes to `&amp;` rather than `&`.
7. Assert text and attribute payloads without `&` are identity decodes.

In `bytes` mode, checks 1, 4, and 5 are skipped because they depend on
DOM-safe UTF-8 payloads or a DOM-derived decoded attribute value. The lane keeps
the reader rebuild, advance/overrun, single-level decode, and no-`&` identity
checks for both text and attribute contexts.

Decoding is not treated as idempotent. The checks and smoke suite explicitly
verify this with nested ampersand-reference fixtures.

## Generator

Every case is determined by `(seed, case index)`. Generated cases run in both
text and attribute contexts so the same payload exercises semicolonless and
attribute-disambiguation differences side by side. The generator preserves the
former context PRNG draw, so the earlier both-context lane change did not by
itself shift payload mapping.
Named-reference lists derived from the generated token map, or injected for
tests, are sorted by length and byte value before case-index mapping, so token
map storage order and caller array order do not affect generated payloads.
Adding or reweighting generation strategies intentionally changes future
`--seed --case` payload mapping; failure-manifest replay remains stable because
manifests store `payload_base64`.

The generator uses the real generated named-reference map, with weighted
strategies for:

- exact named references
- semicolonless legacy references
- attribute-context ambiguous followers
- numeric decimal and hex references drawn from ranges covering C0 controls,
  all C1 controls, surrogates, BMP and per-plane noncharacters, astral values,
  above-Unicode values with legal digit counts, digit-count overflow, zero-only
  references, and leading zeros
- adjacent references
- truncation sweeps
- references ending at EOF, including bare introducers, partial numeric
  references, semicolonless numeric references, and truncated names
- multibyte UTF-8 around references
- `attribute_starts_with()` prefixes generated by encoding target strings
  per character as literals, decimal references, hex references, leading-zero
  references, and semicolonless references
- `attribute_starts_with()` prefixes that split multi-code-point named-reference
  replacements such as `&nvlt;`
- edit-distance-1 named-reference lookalikes plus ampersand boundaries
- valid named-reference names with letter case mangled into case-sensitive
  near-misses
- composed cases that splice two or three generated strategy outputs
- plain no-ampersand text from an oracle-safe alphabet that includes space, tab,
  LF, and FF

`bytes` mode uses separate weighted strategies for uniform random bytes,
no-ampersand byte strings, arbitrary bytes around `&` boundaries, invalid UTF-8
sequences, and raw HTML delimiters/control bytes.

`names` mode uses a deterministic sweep instead of weighted random generation.
Case index maps directly to a named-reference base, semicolon variant, and
follower class; the same payload still runs in both text and attribute contexts.

`legacy-followers` mode deterministically sweeps every semicolonless legacy
name followed by each oracle-safe ASCII byte, plus valid UTF-8 sequences
covering multibyte lead and continuation byte values.

`prefix-families` mode deterministically sweeps known named-reference prefix
families such as `&not`/`&notin;`/`&notinva;` and `&nGt;`/`&ngt;`, truncating
each reference at every byte split and appending ambiguous followers.

`numeric-boundaries` mode deterministically sweeps decimal and hex numeric
references at the decoder's maximum significant-digit count and one digit past
it, with and without leading zeros, semicolons, and mixed-case hex digits.

`corpus` mode mutates a seed corpus built from retained decoder payloads, the
oracle battery, and html5lib entity vectors. Mutations splice corpus fragments,
perturb bytes within the oracle-safe alphabet, including space, tab, LF, and FF,
add or remove semicolons, and duplicate references to diversify structure beyond
the grammar.

`token-map` mode deterministically sweeps the generated `WP_Token_Map` layout:
large-word group prefixes with names that diverge immediately after the shared
two-byte prefix, every small-word boundary name, and every large-word name at
the small/large length boundary.

`coverage` mode runs the normal oracle-safe generator under pcov and treats each
new covered executable line in `WP_HTML_Decoder` or `WP_Token_Map` as a coverage
edge. Workers emit `coverage` events for payloads that discover new edges and
write those payloads under `coverage-corpus/` when an output directory is
provided. The runner deduplicates coverage edges across lanes and prunes
duplicate coverage-corpus artifacts.

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

Run one deterministic named-reference sweep batch:

```sh
php tools/html-decoder-fuzz/worker.php --mode names --seed 1 --cases 5000
```

Run one deterministic legacy-follower sweep batch:

```sh
php tools/html-decoder-fuzz/worker.php --mode legacy-followers --seed 1 --cases 5000
```

Run one deterministic prefix-family sweep batch:

```sh
php tools/html-decoder-fuzz/worker.php --mode prefix-families --seed 1 --cases 5000
```

Run one deterministic numeric-boundary sweep batch:

```sh
php tools/html-decoder-fuzz/worker.php --mode numeric-boundaries --seed 1 --cases 5000
```

Run one corpus mutation batch:

```sh
php tools/html-decoder-fuzz/worker.php --mode corpus --seed 1 --cases 5000
```

Run one token-map structure sweep batch:

```sh
php tools/html-decoder-fuzz/worker.php --mode token-map --seed 1 --cases 5000
```

Run one coverage-guided batch:

```sh
php -d pcov.enabled=1 -d pcov.directory=src/wp-includes tools/html-decoder-fuzz/worker.php --mode coverage --seed 1 --cases 5000 --output-dir /tmp/html-decoder-fuzz-coverage
```

Run parallel lanes for one minute:

```sh
php tools/html-decoder-fuzz/runner.php --lanes 4 --duration-seconds 60
```

Oracle modes spend most of their time in the two DOM parser calls per payload,
not in the PRNG. Scale long oracle runs with more lanes; the oracle-free `bytes`
mode avoids that DOM cost, and future high-throughput oracle work should batch
payloads into fewer documents or cache repeated sub-payloads.

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

- C1 numeric references not remapped through the Windows-1252 table, and raw
  C1 bytes not passing through unchanged
- supported text payloads disagreeing with the secondary `html_entity_decode()`
  oracle
- nested ampersand references being decoded more than one level
- zero, surrogate, and above-Unicode numeric references not decoding to exactly
  U+FFFD
- semicolonless named references decoded in attributes despite ambiguous
  followers
- off-by-one `read_character_reference()` match lengths
- empty `read_character_reference()` chunks, one-byte matches,
  null-return match-length mutations, non-ampersand offset matches,
  non-compositional local slice reads, and non-gapless reader walks
- partial-prefix `attribute_starts_with()` matches
- partial multi-code-point `attribute_starts_with()` replacement matches
- non-monotonic `attribute_starts_with()` prefix, extension, and
  case-sensitivity results
- safe attribute payloads and raw byte payloads without `&` not decoding
  identically

For end-to-end failure-pipeline checks, set `HTML_DECODER_FUZZ_FAULT` to one of
`skip-c1-remap`, `numeric-c1-not-remapped`, `raw-c1-not-pass-through`,
`text-secondary-oracle`, `numeric-invalid-not-replacement`,
`attribute-semicolonless`, `match-length-off-by-one`,
`reader-empty-chunk`, `reader-short-match-length`,
`reader-substring-composition`, `reader-null-mutates-match-length`,
`reader-non-amp-match`, `reader-gapless-drop-span`,
`attribute-no-amp-identity`, `byte-no-amp-identity`,
`single-level-overdecode`,
`attribute-prefix-monotonicity`,
`attribute-extension-monotonicity`, `attribute-case-monotonicity`, or
`attribute-multicodepoint-prefix` before running `worker.php`, `runner.php`,
`replay.php`, or `minimize.php`.
