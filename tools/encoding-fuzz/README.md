# UTF-8 Encoding Fuzzer

Differential fuzzer for the WordPress UTF-8 functions:

- `wp_is_valid_utf8()` / `_wp_is_valid_utf8_fallback()`
- `wp_scrub_utf8()` / `_wp_scrub_utf8_fallback()`
- `_wp_utf8_encode_fallback()` / `_wp_utf8_decode_fallback()`
- `wp_has_noncharacters()` / `_wp_has_noncharacters_fallback()`
- `_mb_chr()` / `_mb_ord()`
- `_mb_substr()`
- `_wp_utf8_codepoint_count()`, `_wp_utf8_codepoint_span()`, and the
  resumable `_wp_scan_utf8()` paths (secondary)

The pure-PHP fallbacks in `src/wp-includes/compat-utf8.php` are the main
fuzz surface; the mbstring-backed public functions are checked alongside
them. The harness loads `compat-utf8.php`, `utf8.php`, and selected private
UTF-8 helpers extracted from `compat.php` — no WordPress bootstrap,
database, or `wp-env`.

## Oracles

Every result is compared against independent known-good implementations:

| Oracle    | Backing                              | Validity | Scrub | Encode | Decode | Nonchars |
|-----------|--------------------------------------|----------|-------|--------|--------|----------|
| `bytes`   | independent UTF-8 noncharacter byte-sequence list | | | | | ✓ (primary) |
| `mb`      | `mb_check_encoding()` / `mb_scrub()` / `mb_convert_encoding()` / `mb_str_split()`+`mb_ord()` | ✓ | ✓ (primary) | ✓ (primary) | ✓ (primary) | ✓ (valid UTF-8 cross-check) |
| `pcre`    | PCRE2 strict UTF validation          | ✓        |       |        |        |          |
| `intl`    | ICU `UConverter::transcode()`        |          | ✓     |        |        |          |
| `python3` | CPython codec, persistent subprocess | ✓        | ✓     |        |        |          |
| `node`    | WHATWG `TextDecoder`, persistent subprocess | ✓ | ✓     |        |        |          |
| `native`  | deprecated `utf8_encode()` / `utf8_decode()` | |       | ✓      | ✓ (valid input only) |  |

Encode oracles answer "what is this ISO-8859-1 text as UTF-8?"; decode
oracles the reverse. The `native` pair exists until PHP 9 removes it; on
PHP 9+ it is reported as `oracle-unavailable` and skipped. Its decode
side is trusted on valid input only: on ill-formed input the legacy
decoder groups a well-formed lead byte with its expected continuation
length and emits a single `?` in several classes — surrogates
(`ED A0 80` → `?` vs `???`), sequences past U+10FFFF (`F4 90 80 80`),
three/four-byte overlongs (`E0 80 AF`), and even a well-formed lead
before an invalid continuation (`C2 C0`) — though it agrees with
maximal subparts elsewhere (e.g. C0/C1 overlongs and lone
continuations). WordPress deliberately follows the maximal-subpart
semantics of `mb_convert_encoding()` (one `?` per subpart) instead:
the PHP 9 polyfill in `compat.php` prefers `mb_convert_encoding()`
with `_wp_utf8_decode_fallback()` as its mbstring-less shadow
(ticket #63863).

The primary noncharacter oracle is an independent list of UTF-8 byte
sequences for U+FDD0–U+FDEF and the final two code points of every
plane. It is defined over arbitrary bytes, matching the public
function's byte-sequence contract. On valid UTF-8, a trivial mb
decode-and-test oracle (`mb_str_split()` / `mb_ord()`) cross-checks the
byte oracle. The battery covers boundaries and interior points of the
U+FDD0–U+FDEF block, every plane-final pair with neighbors, and
ill-formed surrounds.

Because native and mb decoding agree on *every* valid code point
(verified exhaustively over U+0000–U+10FFFF), the valid-input-only
native decode differential adds little detection power beyond `mb`; it
exists to scream if mb and the fallback ever jointly drift from legacy
behavior on valid text. The legacy-vs-WordPress behavior on ill-formed
input is a documented, intentional divergence — pinned here by battery
vectors, not fuzzed. Cataloguing the full legacy divergence surface is
the separate `legacy-utf8-divergence-survey` work lane.

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
scrub targets against every scrub oracle, `_wp_utf8_encode_fallback()`
against every encode oracle (input treated as ISO-8859-1), and
`_wp_utf8_decode_fallback()` against every decode oracle (the `native`
decode oracle on valid input only). Oracle-vs-oracle disagreements
are reported separately (`oracle-disagreement`) so they don't masquerade
as WordPress bugs.

Noncharacter detection is checked on arbitrary bytes:
`wp_has_noncharacters()`, the deprecated `_wp_has_noncharacters_fallback()`
wrapper, and the independent byte-sequence reference must agree. On
valid UTF-8, the mb decode-and-test reference must also agree.

Internal invariants:

- valid ⟺ scrub returns the input unchanged
- scrub output is always valid UTF-8
- scrub is idempotent
- `_wp_utf8_codepoint_count()` equals an independent maximal-subpart count
  for whole strings and bounded byte windows; a byte window ending inside a
  multibyte character or invalid maximal subpart counts its truncated prefix
  as one invalid subpart
- `_wp_utf8_codepoint_span()` reports the original byte span occupied by a
  requested number of code points; on scrubbed valid text it matches
  `strlen( mb_substr( ... ) )`, and on arbitrary input an independent
  maximal-subpart parser checks that invalid subparts count as one code
  point. Nonzero starts are probed only at known code point or
  maximal-subpart boundaries.
- bounded `_wp_scan_utf8()` calls agree with an independent scan model for
  `max_bytes`, `max_code_points`, negative limits, nonzero boundary starts,
  invalid spans, forward progress, by-ref noncharacter flag reset, and
  scanned-region noncharacter reporting
- scanning with `_wp_scan_utf8()` in pseudo-random `max_code_points`
  chunks reconstructs the same scrubbed text and always makes forward
  progress (chunk sizes derive from the input hash, so replays are exact)
- `_wp_utf8_encode_fallback()` output is always valid UTF-8
- `_wp_utf8_decode_fallback( _wp_utf8_encode_fallback( $s ) ) === $s`
  for any byte string `$s` (encode is total and injective per byte)
- `_mb_chr()` matches the fuzzer's independent arithmetic UTF-8 encoder
  for valid scalar values and returns false for invalid code points
- `_mb_ord()` matches an independent first-code-point decoder on arbitrary
  byte strings and returns false when the first code point is ill-formed
- `_mb_ord( _mb_chr( $cp ) ) === $cp` for valid scalar values, and
  `_mb_chr( _mb_ord( $s ) )` reconstructs the first UTF-8 character in
  `$s` when it is well-formed
- `_mb_substr()` in UTF-8 mode preserves original bytes while treating each
  invalid maximal subpart as one code point; on valid input it also agrees
  with native `mb_substr()`, and explicit non-UTF-8 encodings fall back to
  byte-level `substr()` semantics

## Invalid-Input Noncharacter Policy

Trunk aligned the invalid-input behavior: `wp_has_noncharacters()`
matches the UTF-8 byte sequences for noncharacters directly, so
malformed bytes elsewhere in the string do not suppress detection.
`_wp_has_noncharacters_fallback()` is deprecated and delegates to the
public function. The fuzzer therefore includes invalid-input
noncharacter cases in the normal differential.

## Inputs

Random cases are fully determined by `(seed, case index)` **for a given
generator version**: changing the generator (e.g. its boundary code
point list) invalidates `--seed`/`--case` re-derivation of older
findings. Failure artifacts embed the input bytes, so `--failure` and
`--input` replays remain valid across versions. The generator
mixes nine strategies: uniformly random bytes, random ASCII,
boundary-heavy valid UTF-8 (encoding-length edges, surrogate-gap edges,
noncharacters, BOM, U+10FFFF), mutated valid UTF-8 (bit flips,
truncations, splices), splices of hand-picked valid/invalid atoms
(overlongs, surrogates, truncated sequences, out-of-range leads),
ISO-8859-1-ish text, UTF-16 with/without BOM, long ASCII runs with
broken tails (`strspn()` fast-path stress), and repeated motifs.
Roughly a third of generated inputs are fully valid UTF-8.

A separate deterministic short-boundary corpus lives outside the random
generator so changing the fixed corpus does not perturb random
`(seed, case)` reproduction. It covers lead-byte boundary classes
crossed with boundary second/third/fourth byte positions, adjacent
invalid maximal subparts, valid text immediately before and after
malformed prefixes, EOF truncations at each prefix length, and
noncharacter boundary neighbors.

## Common Commands

Run one worker batch:

```sh
php tools/encoding-fuzz/worker.php --seed 1 --cases 5000
```

Run the deterministic short-boundary corpus:

```sh
php tools/encoding-fuzz/corpus.php
```

Run the compact environment matrix:

```sh
php tools/encoding-fuzz/matrix.php
```

The matrix runs the fixed corpus in the current environment, with the
fuzzer's PCRE-u branch forced off, with native `utf8_encode()` /
`utf8_decode()` disabled to simulate PHP 9, and with the primary mbstring
oracle functions disabled to verify the harness fails closed. A true
no-mbstring target run still requires a PHP build without mbstring; the
local harness intentionally refuses to fuzz without the mb-backed primary
oracle.

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
vectors, and — most importantly — mutation-tests the harness: thirty-five
classes of deliberately broken implementations (validator accepting
0xC0, validator rejecting noncharacters, non-maximal-subpart scrubber,
identity scrubber, byte-dropping scrubber, off-by-one code point count,
invalid-byte-counting code point count, range-end off-by-one code point
count, byte-offset-ignoring code point count, throwing target,
cp1252-confused encoder, identity encoder, per-byte
decoder, valid-input-mangling decoder, round-trip-violating decoder,
null-returning encoder, sometimes-null decoder, blind noncharacter
detector, U+FDD0-block-missing detector, over-eager noncharacter
detector, cp1252-confused `_mb_chr()`, invalid-accepting `_mb_ord()`,
off-by-one code point span, invalid-subpart byte-counted span, and
wrong or stale `found_code_points` span, byte-offset `_mb_substr()`,
scrubbed-input `_mb_substr()`, negative-length `_mb_substr()`, and
non-UTF-8 fallback drift, max-bytes-ignoring `_wp_scan_utf8()`,
noncharacter-leaking `_wp_scan_utf8()`, noncharacter-missing
`_wp_scan_utf8()`, ASCII-overrunning `_wp_scan_utf8()`, and
stale-noncharacter-flag `_wp_scan_utf8()`)
must all be caught. It also asserts generator determinism, the
valid/invalid input mix, the deterministic short-boundary corpus, and
the aligned `wp_has_noncharacters()` behavior on ill-formed input.

For end-to-end pipeline testing while the real implementations are
healthy, `ENCODING_FUZZ_FAULT=accept-c0|non-maximal|encode-cp1252|decode-per-byte|nonchars-miss-fdd0|nonchars-overeager|span-off-by-one|span-invalid-bytes|span-found-max|span-found-stale|substr-byte-level|substr-scrub|substr-no-neg-len|substr-force-utf8|count-invalid-bytes|count-range-minus1|count-ignore-offset|scan-ignore-bytes|scan-nonchars-leak|scan-miss-nonchars|scan-ascii-overrun|scan-stale-nonchars`
injects a broken target into worker, replay, and minimize alike.
Fault-injected artifacts record the fault name in their environment
metadata so they cannot be mistaken for real findings. Replaying or
minimizing a fault-injected artifact requires setting the same
`ENCODING_FUZZ_FAULT`; replay without it checks the healthy targets
against the saved input:

```sh
ENCODING_FUZZ_FAULT=non-maximal php tools/encoding-fuzz/runner.php --lanes 2 --duration-seconds 5
ENCODING_FUZZ_FAULT=non-maximal php tools/encoding-fuzz/minimize.php --failure .../failure.json
```

(The `non-maximal` fault minimizes to the two bytes `E0 F4`: two
adjacent maximal subparts whose replacement characters get collapsed.)

## One-Shot Exhaustive Tests

```sh
php tools/encoding-fuzz/tests/code-point-to-utf8-exhaustive.php
```

`WP_HTML_Decoder::code_point_to_utf8_bytes()` has a domain small
enough (~1.1M code points) to test completely instead of fuzzing: every
code point 0x0–0x10FFFF plus out-of-range probes. The independent
oracle is the fuzzer's pure-arithmetic `Generator::encode_code_point()`;
a second comparison against `mb_chr( $cp, 'UTF-8' )` is a consistency
cross-check (the implementation is itself mb_chr-backed) that would
expose a bug shared between the implementation and the arithmetic
encoder. Surrogates and out-of-range values must yield U+FFFD. Runs in
under a second; exit codes `0`/`1`/`2` like everything else. The smoke
test runs it and proves its detection fires via
`ENCODING_FUZZ_FAULT=codepoint-surrogate-qmark`.

The script also pins a known upstream issue: since [r62424] (#65342,
unreleased) the implementation calls `mb_chr()` without an explicit
encoding, so under a non-UTF-8 `mb_internal_encoding()` (WordPress
sets it from `blog_charset`) it returns raw legacy bytes for mappable
code points while still returning UTF-8 U+FFFD for invalid ones —
contradicting its docblock. The pin fails when the upstream behavior
changes, so the documented stance cannot silently go stale.
