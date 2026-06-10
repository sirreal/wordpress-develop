# Handoff: extend the UTF-8 encoding fuzzer with three new targets

## Status

Sections 1 (utf8_encode/decode) and 2 (wp_has_noncharacters) DONE;
section 3 in progress. The
host fuzzer (`tools/encoding-fuzz/`) is complete and working on branch
`fuzz-encoder`; read its `README.md` first. ~570k cases had run clean
against the original targets before this work started.

## Goal

Round out coverage of `src/wp-includes/compat-utf8.php` by adding:

1. `_wp_utf8_encode_fallback()` / `_wp_utf8_decode_fallback()`
   differentials against the native `utf8_encode()` / `utf8_decode()`.
2. `wp_has_noncharacters()` / `_wp_has_noncharacters_fallback()`
   differential — **after resolving the semantic question below**.
3. A one-shot exhaustive test of
   `WP_HTML_Decoder::code_point_to_utf8_bytes()` (not fuzzing).

## 1. utf8_encode / utf8_decode fallbacks — DONE, premise corrected

**Implemented**, but a premise of this section was falsified during
implementation and the oracle design adapted (2026-06-10, PHP 8.4.21):

- The original claim "No known divergence going in" was wrong: the
  earlier spot-probes missed it. Native `utf8_decode()` groups a
  well-formed lead byte with its expected continuation length and emits
  a single `?` for surrogates (`ED A0 80` → `?`), beyond-U+10FFFF
  sequences (`F4 90 80 80` → `?`), 3-/4-byte overlongs, and a
  well-formed lead before an invalid continuation (`C2 C0` → `?`),
  where the fallback emits one `?` per maximal subpart (`???` etc.).
- That divergence is **intentional** in WordPress: the PHP 9 polyfill
  in `compat.php` prefers `mb_convert_encoding()` (which uses maximal
  subparts) over the fallback, and the #63863 PHPUnit tests assert
  mb-equivalence. So "the native functions are the only ground truth"
  was also wrong — WP's chosen ground truth is `mb_convert_encoding()`.
- Oracle design as built: `mb` (`mb_convert_encoding()`) is the primary
  encode/decode oracle on arbitrary input; `native` is an encode oracle
  on arbitrary input and a decode oracle on **valid input only**
  (native ≡ mb on every valid code point, verified exhaustively). On
  PHP 9+ `native` reports `oracle-unavailable` and is skipped. The
  legacy divergence is pinned by hand-computed battery vectors.
- Round-trip `decode(encode(s)) === s`, encode-output-validity, the
  smoke-test mutation variants (cp1252-confused encoder, identity
  encoder, per-byte decoder, valid-input mangler, round-trip violator,
  null-returning targets), and the `ENCODING_FUZZ_FAULT=encode-cp1252`
  / `decode-per-byte` end-to-end fault variants are all in place.

**Upstream finding, not fixed here:** the cited core test
`tests/phpunit/tests/formatting/deprecatedUtfEncodeDecode.php` has
vacuous invalid-input coverage — its surrogate branch interpolates
integers instead of `chr()` bytes (`"{$byte1}{$byte2}{$byte3}"`
produces ASCII digits), its single-quoted `'\x95'` data is literal
backslash text, and the `$i < 0xD800 || $i > 0xE000` boundary routes
valid U+E000 through the broken branch. It only ever asserts
mb-equivalence on valid input. Worth a follow-up patch on #63863.

## 2. wp_has_noncharacters — DONE via option (a); core decision still open

**Known divergence, confirmed empirically (2026-06-10):**

```php
$probe = "\xC0\xEF\xBF\xBE"; // invalid byte, then U+FFFE
wp_has_noncharacters( $probe );             // false — PCRE path: preg_match fails on ill-formed UTF-8
_wp_has_noncharacters_fallback( $probe );   // true  — scan skips invalid spans, finds U+FFFE
```

**Implemented as option (a):** the fuzzer treats behavior as undefined
unless `wp_is_valid_utf8()` and runs the three-way differential —
`wp_has_noncharacters()` (PCRE branch) vs
`_wp_has_noncharacters_fallback()` vs a trivial `mb_str_split()` /
`mb_ord()` reference (battery-verified at block boundaries, block
interior, and the final two code points of every plane with their
neighbors — the PCRE class enumerates each plane by hand, so per-plane
vectors are the point) — on **valid inputs only**. The probe above is
pinned as a fixed regression vector in the smoke test, so any semantic
change to either branch surfaces immediately. `BOUNDARY_CODE_POINTS`
in `lib/Generator.php` gained adjacent NON-noncharacters, a block
interior point, and mid-plane finals. Mutation variants: blind
detector, U+FDD0-block miss, over-eager detector; fault injection:
`ENCODING_FUZZ_FAULT=nonchars-miss-fdd0|nonchars-overeager` (one per
target).

**Still open upstream (option b path):** whether core should align the
implementations or document the undefined-on-invalid stance in the
`wp_has_noncharacters()` docblock. That needs a decision from the
function author (Trac discussion). Note for whoever picks that up: if
core aligns on PCRE semantics (false on any ill-formed input), the mb
reference oracle and its battery must be extended for ill-formed input
too — removing the valid-only gate alone is NOT sufficient, since the
reference throws on ill-formed input by design.

## 3. code_point_to_utf8_bytes — exhaust, don't fuzz

`WP_HTML_Decoder::code_point_to_utf8_bytes()`
(`src/wp-includes/html-api/class-wp-html-decoder.php:426`) has a domain
of ~1.1M values. Write a standalone script (or slow-group PHPUnit test)
asserting equality with `mb_chr( $cp, 'UTF-8' )` for every code point
0x0–0x10FFFF, including expected behavior for surrogates and
out-of-range values (check what the function documents; `mb_chr`
returns `false` for surrogates — decide the comparison accordingly).
Runs in seconds; total coverage; done forever. Note this class is
loaded from `html-api/`, so the fuzzer bootstrap (`lib/Bootstrap.php`)
needs to require it (it has no dependencies beyond the token map — if
it pulls more, load only for this check).

## Verification / definition of done

- `php tools/encoding-fuzz/tests/harness-smoke.php` passes, including
  new broken-variant detections for every added check.
- A fault-injection variant per new target in `lib/Targets.php`
  (`ENCODING_FUZZ_FAULT=...`) exercises worker → replay → minimize end
  to end.
- `php tools/encoding-fuzz/runner.php --lanes 4 --duration-seconds 60`
  runs clean (or findings are triaged and documented, not silenced).
- README.md oracle/check tables updated.

## Gotchas inherited from the existing harness

- All scrub/validity oracles passed a hand-computed battery; new
  oracles must too (`Oracles::battery()` pattern). iconv is excluded
  for accepting code points above U+10FFFF — don't re-add it.
- Workers run checks in-process; an infinite loop in a new target will
  trip the runner's 120s stall watchdog and record the seed. Keep that
  property: no per-case subprocesses.
- Everything must stay derivable from `(seed, case index)` — no
  `random_int()`, no time-dependent generation. Per-case chunking-type
  randomness derives from `sha256(input)` (see
  `Checks::check_chunked_scan()`).
