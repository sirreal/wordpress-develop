# Handoff: extend the UTF-8 encoding fuzzer with three new targets

## Status

All three sections DONE. The host fuzzer (`tools/encoding-fuzz/`) is
complete and working on branch `fuzz-encoder`; read its `README.md`
first. ~570k cases had run clean against the original targets before
this work started.

## Goal

Round out coverage of `src/wp-includes/compat-utf8.php` (plus one
html-api encoder) by adding:

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

## 3. code_point_to_utf8_bytes — DONE; upstream finding documented

Implemented as `tools/encoding-fuzz/tests/code-point-to-utf8-exhaustive.php`
(standalone, not wired into `Bootstrap.php` — the class is required
only by this script, which parses cleanly with no other dependencies;
loading html-api code into every fuzz worker would buy nothing).
Every code point 0x0–0x10FFFF plus out-of-range probes, compared
against the pure-arithmetic `Generator::encode_code_point()` (the
independent oracle) with an additional `mb_chr( $cp, 'UTF-8' )`
consistency cross-check (the implementation is itself mb_chr-backed;
the cross-check would expose a bug shared between implementation and
arithmetic encoder). Surrogates and out-of-range values yield U+FFFD
as documented. Runs in ~0.4s, passes on PHP 8.4.21. The harness smoke
test executes it and proves its detection fires via the
`ENCODING_FUZZ_FAULT=codepoint-surrogate-qmark` broken variant.

**Upstream finding (real bug — an unreleased trunk REGRESSION, not
fixed here):** the implementation is `mb_chr( $code_point )` with NO
explicit encoding, so it inherits `mb_internal_encoding()` — which
WordPress sets from `blog_charset` (`wp_set_internal_encoding()`,
`src/wp-includes/load.php`). On a non-UTF-8 site it returns raw legacy
bytes for mappable code points (e.g. `"\xE9"` for U+00E9 under
ISO-8859-1) while still returning UTF-8 U+FFFD for invalid ones,
contradicting its docblock. Aggravating facts for the upstream report:

- Introduced by [62424] (#65342, `@since 7.1.0`, unreleased): the
  6.6.0 original was a pure-arithmetic encoder that always emitted
  UTF-8 regardless of mbstring state. Fix-before-release territory.
- WP's own `_mb_chr()` polyfill in `compat.php` documents
  `@param "UTF-8"|null $encoding Must be 'UTF-8' or null` and treats
  null as UTF-8 — so mbstring-less hosts always emit UTF-8 while
  mbstring hosts follow `blog_charset`. Same WordPress, divergent
  output by extension presence.
- Named character references decode through the UTF-8 token map
  regardless: on a latin1 site `&eacute;` → UTF-8 `C3 A9` but
  `&#233;` → latin1 `E9` in the same decoded string. There is no
  intentional-behavior steelman; output is mixed-encoding either way.
- The same commit silently changed `code_point_to_utf8_bytes( 0 )`
  from `U+FFFD` to `"\0"` (the old guard was `$code_point <= 0`).
  Callers are unaffected (`&#0;` is intercepted earlier) and the new
  behavior matches the docblock, but it belongs in the same report.

One-line fix: `mb_chr( $code_point, 'UTF-8' )`. The script pins the
current buggy behavior as a labeled KNOWN ISSUE check so the stance
cannot silently go stale; update or remove the pin when fixed.

## Verification / definition of done

All verified 2026-06-10 on PHP 8.4.21:

- `php tools/encoding-fuzz/tests/harness-smoke.php` passes, including
  broken-variant detections for every added check (seventeen mutation
  classes plus the exhaustive script's surrogate fault).
- Fault-injection variants per new target
  (`ENCODING_FUZZ_FAULT=encode-cp1252|decode-per-byte|nonchars-miss-fdd0|nonchars-overeager`)
  exercised worker → replay → minimize end to end; artifacts now record
  the fault name and `pcre_u` in environment metadata. The script-local
  `codepoint-surrogate-qmark` fault is proven via the smoke test's
  subprocess run (the exhaustive script never enters the worker
  pipeline).
- `php tools/encoding-fuzz/runner.php --lanes 4 --duration-seconds 60`
  ran clean (32,000 cases, 0 failures, 0 stalled, final tree). Findings
  that were
  triaged and documented rather than silenced: the legacy
  `utf8_decode()` divergence (§1), the `wp_has_noncharacters()`
  ill-formed-input divergence (§2), the `code_point_to_utf8_bytes()`
  internal-encoding regression and the #63863 test bug (§§1, 3).
- README.md oracle/check tables updated (Encode/Decode/Nonchars).

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
