# UTF-8 Encoding Fuzzer

Differential fuzzer for the WordPress UTF-8 functions:

- `wp_is_valid_utf8()` / `_wp_is_valid_utf8_fallback()`
- `wp_scrub_utf8()` / `_wp_scrub_utf8_fallback()`
- `_wp_utf8_codepoint_count()` and the resumable `_wp_scan_utf8()` paths (secondary)

The pure-PHP fallbacks in `src/wp-includes/compat-utf8.php` are the main
fuzz surface; the mbstring-backed public functions are checked alongside
them. Only `compat-utf8.php` and `utf8.php` are loaded — no WordPress
bootstrap, database, or `wp-env`.

## Oracles

Every result is compared against independent known-good implementations:

| Oracle    | Backing                              | Validity | Scrub |
|-----------|--------------------------------------|----------|-------|
| `mb`      | `mb_check_encoding()` / `mb_scrub()` | ✓        | ✓ (primary) |
| `pcre`    | PCRE2 strict UTF validation          | ✓        |       |
| `intl`    | ICU `UConverter::transcode()`        |          | ✓     |
| `python3` | CPython codec, persistent subprocess | ✓        | ✓     |
| `node`    | WHATWG `TextDecoder`, persistent subprocess | ✓ | ✓     |

All scrub oracles implement the Unicode "maximal subpart" replacement
recommendation (Unicode 16.0 §3.9, Table 3-8), which is the documented
behavior of `wp_scrub_utf8()`. Every oracle must pass a hand-computed
known-answer battery at startup; one that fails (or whose subprocess
dies) is disabled and reported rather than allowed to produce noise.
iconv is deliberately excluded: GNU libiconv accepts code points above
U+10FFFF and fails the battery.

`mb` (PHP ≥ 8.1.6, for maximal-subpart `mb_scrub()`) is required.
External oracles are auto-detected; control them with
`--external auto|python3|node|python3,node|none`.

## Checks

Differentials: both validity targets against every validity oracle, both
scrub targets against every scrub oracle. Oracle-vs-oracle disagreements
are reported separately (`oracle-disagreement`) so they don't masquerade
as WordPress bugs.

Internal invariants:

- valid ⟺ scrub returns the input unchanged
- scrub output is always valid UTF-8
- scrub is idempotent
- `_wp_utf8_codepoint_count()` equals `mb_strlen()` of the scrubbed text
  (each maximal subpart counts as one code point)
- scanning with `_wp_scan_utf8()` in pseudo-random `max_code_points`
  chunks reconstructs the same scrubbed text and always makes forward
  progress (chunk sizes derive from the input hash, so replays are exact)

## Inputs

Each case is fully determined by `(seed, case index)`. The generator
mixes nine strategies: uniformly random bytes, random ASCII,
boundary-heavy valid UTF-8 (encoding-length edges, surrogate-gap edges,
noncharacters, BOM, U+10FFFF), mutated valid UTF-8 (bit flips,
truncations, splices), splices of hand-picked valid/invalid atoms
(overlongs, surrogates, truncated sequences, out-of-range leads),
ISO-8859-1-ish text, UTF-16 with/without BOM, long ASCII runs with
broken tails (`strspn()` fast-path stress), and repeated motifs.
Roughly a third of generated inputs are fully valid UTF-8.

## Common Commands

Run one worker batch:

```sh
php tools/encoding-fuzz/worker.php --seed 1 --cases 5000
```

Run parallel lanes for a minute (artifacts under `artifacts/encoding-fuzz/`):

```sh
php tools/encoding-fuzz/runner.php --lanes 4 --duration-seconds 60
```

Run indefinitely:

```sh
php tools/encoding-fuzz/runner.php --lanes 8 --duration-seconds 0 --max-cases 0
```

The duration budget stops new batches; in-flight batches finish, so a
run can overshoot by up to one batch (`--cases-per-batch`, default 2000).
A lane silent for `--stall-timeout` seconds (default 120) is killed and
its seed recorded for reproduction.

Replay a failure (or any input, or a re-derived case):

```sh
php tools/encoding-fuzz/replay.php --failure artifacts/encoding-fuzz/run-.../failure-seedS-caseN/failure.json
php tools/encoding-fuzz/replay.php --input some-bytes.bin
php tools/encoding-fuzz/replay.php --seed 123 --case 45
```

Minimize a failure while preserving its signature:

```sh
php tools/encoding-fuzz/minimize.php --failure .../failure.json
```

Exit codes everywhere: `0` clean, `1` findings, `2` harness error.

## Artifacts

The runner writes `summary.ndjson` (every worker event), `state.json`
(aggregate counters, failure/stall seeds, compact Git metadata, stop
reason), per-lane stderr logs, and one directory per failing case with
`input.bin` and a self-contained `failure.json` (base64 input, signatures,
diff windows with hex previews, environment and Git metadata).

## Harness Self-Test

```sh
php tools/encoding-fuzz/tests/harness-smoke.php
```

Verifies the oracle battery, runs the real targets over the battery
vectors, and — most importantly — mutation-tests the harness: seven
classes of deliberately broken implementations (validator accepting
0xC0, validator rejecting noncharacters, non-maximal-subpart scrubber,
identity scrubber, byte-dropping scrubber, off-by-one code point count,
throwing target) must all be caught. It also asserts generator
determinism and the valid/invalid input mix.

For end-to-end pipeline testing while the real implementations are
healthy, `ENCODING_FUZZ_FAULT=accept-c0|non-maximal` injects a broken
target into worker, replay, and minimize alike:

```sh
ENCODING_FUZZ_FAULT=non-maximal php tools/encoding-fuzz/runner.php --lanes 2 --duration-seconds 5
ENCODING_FUZZ_FAULT=non-maximal php tools/encoding-fuzz/minimize.php --failure .../failure.json
```

(The `non-maximal` fault minimizes to the two bytes `E0 F4`: two
adjacent maximal subparts whose replacement characters get collapsed.)
