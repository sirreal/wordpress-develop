# Legacy UTF-8 Helper Divergence Report

## Scope

This report covers the current `utf8-survey` checkout and the handoff in
`encoding-fuzzer/handoffs/legacy-utf8-divergence-survey.md`.

No production code was changed for this survey. The throwaway runner lived at
`/private/tmp/legacy_utf8_divergence_survey.php` and reused the generator and
oracle battery from the adjacent `encoding-fuzzer/tools/encoding-fuzz/`
checkout. It loaded this checkout's `compat.php`, `compat-utf8.php`,
`utf8.php`, and `formatting.php` with minimal stubs.

The generated pass was deterministic: case `N` used
`new EncodingFuzz\Prng( "legacy-utf8-divergence:N" )`. The exact command was:

```sh
php /private/tmp/legacy_utf8_divergence_survey.php 3000000 256 > /private/tmp/legacy_utf8_divergence_survey_results.json
```

For auditability, a cleaned-up copy of the throwaway runner was committed in
`700d7c8c910f` (`Charset: Add legacy UTF-8 survey runner`) and removed in the
follow-up commit after this report recorded that provenance.

Important current-branch note: the handoff describes
`wp_check_invalid_utf8()` as PCRE-based. That is historically correct, but this
checkout already contains the 6.9-era rewrite from `d1e7f5625b`, so the current
implementation now calls `wp_is_valid_utf8()` and `wp_scrub_utf8()` when
`blog_charset` is UTF-8.

## Environment

- PHP: 8.4.21
- Extensions present: `mbstring`, `intl`
- PCRE Unicode support: yes
- Generated pass: 3,000,000 inputs, 366,593,389 bytes, max generated input
  size 256 bytes
- Generator strategies: random bytes, random ASCII, valid UTF-8,
  mutated-valid UTF-8, atom splices, latin1-ish text, UTF-16 bytes,
  ASCII fast paths, repeated motifs
- Oracle battery: `EncodingFuzz\Oracles::battery()`

`wp_check_invalid_utf8()` caches the first `is_utf8_charset()` result in a
static. To compare UTF-8 and non-UTF-8 charset behavior in one process, the
runner used an equivalent uncached copy of the current implementation for that
matrix. This models first-call behavior in a fresh request under each charset.

## Aggregate Results

| Measurement | Count |
| --- | ---: |
| Generated inputs | 3,000,000 |
| Strict-valid inputs | 1,128,174 |
| Strict-invalid inputs | 1,871,826 |
| `seems_utf8()` accepted strict-invalid input | 92,007 |
| `seems_utf8()` rejected strict-invalid input | 1,779,819 |
| `seems_utf8()` rejected strict-valid input | 0 |
| `wp_check_invalid_utf8( $s, false )` returned `''` for invalid UTF-8 under UTF-8 charset | 1,871,826 |
| `wp_check_invalid_utf8( $s, true )` matched `wp_scrub_utf8( $s )` under UTF-8 charset | 1,871,826 |
| `wp_check_invalid_utf8( $s, true )` mismatched `wp_scrub_utf8( $s )` under UTF-8 charset | 0 |
| `wp_check_invalid_utf8()` passed invalid bytes through under ISO-8859-1 charset | 1,871,826 |

`seems_utf8()` accepted strict-invalid inputs in exactly these buckets:

| Divergence class | Generated examples |
| --- | ---: |
| UTF-16 surrogate sequence | 21,051 |
| Code point above `U+10FFFF` | 14,770 |
| Obsolete 5-byte sequence | 6,850 |
| Obsolete 6-byte sequence | 6,764 |
| Overlong 2-byte sequence | 14,686 |
| Overlong 3-byte sequence | 13,775 |
| Overlong 4-byte sequence | 14,111 |

No generated class showed `wp_check_invalid_utf8( $s, true )` diverging from
`wp_scrub_utf8( $s )` when `blog_charset` was UTF-8.

## Divergence Matrix

All byte strings are hex. `R` means one `U+FFFD` replacement character, encoded
as `EF BF BD`. `same` means the original byte string is returned unchanged.

| Input class | Minimal bytes | `wp_is_valid_utf8()` | `seems_utf8()` | `wp_check_invalid_utf8( false )`, UTF-8 charset | `wp_check_invalid_utf8( true )`, UTF-8 charset | `wp_check_invalid_utf8()`, non-UTF-8 charset |
| --- | --- | --- | --- | --- | --- | --- |
| ASCII | `41` | accept | accept | same | same | same |
| Valid 2-byte lower edge | `C2 80` | accept | accept | same | same | same |
| Valid 3-byte lower edge | `E0 A0 80` | accept | accept | same | same | same |
| Valid 4-byte upper edge | `F4 8F BF BF` | accept | accept | same | same | same |
| Noncharacter `U+FFFE` | `EF BF BE` | accept | accept | same | same | same |
| Replacement character `U+FFFD` | `EF BF BD` | accept | accept | same | same | same |
| Lone continuation | `80` | reject | reject | `''` | `R` | same |
| Invalid `FE`/`FF` lead | `FE` | reject | reject | `''` | `R` | same |
| Truncated 2-byte sequence | `C2` | reject | reject | `''` | `R` | same |
| Truncated 3-byte sequence | `E2 8C` | reject | reject | `''` | `R` | same |
| Truncated 4-byte sequence | `F1 80 80` | reject | reject | `''` | `R` | same |
| Overlong 2-byte sequence | `C0 80` | reject | accept | `''` | `R R` | same |
| Overlong 3-byte sequence | `E0 80 80` | reject | accept | `''` | `R R R` | same |
| Overlong 4-byte sequence | `F0 80 80 80` | reject | accept | `''` | `R R R R` | same |
| UTF-16 surrogate sequence | `ED A0 80` | reject | accept | `''` | `R R R` | same |
| Above `U+10FFFF`, `F4` form | `F4 90 80 80` | reject | accept | `''` | `R R R R` | same |
| Above `U+10FFFF`, `F5` form | `F5 80 80 80` | reject | accept | `''` | `R R R R` | same |
| Obsolete 5-byte sequence | `F8 80 80 80 80` | reject | accept | `''` | `R R R R R` | same |
| Obsolete 6-byte sequence | `FC 80 80 80 80 80` | reject | accept | `''` | `R R R R R R` | same |
| Valid text plus overlong bytes | `41 C0 80 5A` | reject | accept | `''` | `41 R R 5A` | same |

## Divergence Classes

### `seems_utf8()` accepts overlong encodings

Representative inputs: `C0 80`, `E0 80 80`, `F0 80 80 80`.

Classification: accidental if the caller expects valid UTF-8; historically
load-bearing only as a loose structural heuristic.

Evidence:

- `src/wp-includes/formatting.php` says the function checks whether the string
  "fits a UTF-8 model", not whether it is well-formed UTF-8.
- Core Trac #38044 was specifically opened to make `seems_utf8()` RFC 3629
  compliant and calls out overlong acceptance as a defect:
  https://core.trac.wordpress.org/ticket/38044
- Commit `bb6ed3ba22` introduced `wp_is_valid_utf8()` and deprecated
  `seems_utf8()` instead of tightening the old function in place.

Impact:

Replacing `seems_utf8()` with `wp_is_valid_utf8()` is behavior-changing for
saved data containing these bytes: old code reports "yes"; strict validation
reports "no".

### `seems_utf8()` accepts UTF-16 surrogate encodings

Representative input: `ED A0 80`.

Classification: accidental. Surrogate halves are not Unicode scalar values and
are rejected by the strict validator and by the fuzzer battery.

Evidence:

- Trac #38044 explicitly names surrogate acceptance as part of the RFC 3629
  compliance problem: https://core.trac.wordpress.org/ticket/38044
- The current `wp_is_valid_utf8()` docblock gives surrogate halves as invalid
  examples.

Impact:

Same as overlongs: `wp_is_valid_utf8()` is the correct replacement for
validation, but it is not a byte-for-byte-compatible replacement.

### `seems_utf8()` accepts code points above `U+10FFFF`

Representative inputs: `F4 90 80 80`, `F5 80 80 80`.

Classification: accidental. The code accepts any `F0`-`F7` lead followed by
three continuation bytes, but modern UTF-8 stops at `F4 8F BF BF`.

Evidence:

- The current `wp_is_valid_utf8()` docblock defines well-formed UTF-8 as
  excluding characters above the representable range.
- Trac #38044 frames the replacement around RFC 3629 compliance, whose range is
  `U+0000..U+10FFFF`.

Impact:

Strict replacement rejects bytes that the legacy heuristic accepted. Treat this
as a migration break for data-validation callers.

### `seems_utf8()` accepts obsolete 5- and 6-byte forms

Representative inputs: `F8 80 80 80 80`, `FC 80 80 80 80 80`.

Classification: documented historical looseness, not valid UTF-8. The
docblock warns that the function checks 5-byte sequences even though UTF-8 has a
maximum length of 4 bytes; the code also accepts 6-byte forms.

Evidence:

- The 5-byte warning was added in the 2009 cleanup associated with Trac #9692:
  https://core.trac.wordpress.org/ticket/9692
- Trac #38044 records the later decision to deprecate rather than repair this
  legacy behavior in place.

Impact:

This is the clearest documented non-strict behavior. A strict replacement is
still desirable for validation, but compatibility notes should call out the
change explicitly.

### `wp_check_invalid_utf8( $s, false )` rejects the whole string

Representative input: `41 C0 80 5A`.

Classification: intentional security behavior. Under UTF-8 charset, any invalid
span makes the default mode return `''`; it does not preserve valid surrounding
text.

Evidence:

- Trac #8767 introduced the helper in a security/XSS context and discussed the
  default empty-string behavior as the more conservative validator-like option:
  https://core.trac.wordpress.org/ticket/8767
- The current docblock documents this default mode.

Impact:

`wp_scrub_utf8()` is not a drop-in replacement for default-mode callers because
it preserves the string and inserts replacement characters. That can be a better
user experience in some contexts, but it changes escaping and sanitization
behavior.

### `wp_check_invalid_utf8( $s, true )` now scrubs with `U+FFFD`

Representative input: `C0 80` produces `R R`.

Classification: intentional current behavior. On this branch, `$strip = true`
matches `wp_scrub_utf8()` for all generated strict-invalid inputs under UTF-8
charset.

Evidence:

- Trac #63837 states the plan to rely on `wp_is_valid_utf8()` and add
  `wp_scrub_utf8()` for replacement-character scrubbing:
  https://core.trac.wordpress.org/ticket/63837
- Commit `d1e7f5625b` says the old `$strip` defect was fixed and invalid bytes
  are now replaced with `U+FFFD` for stronger security guarantees.

Impact:

For UTF-8 charset requests, `wp_scrub_utf8()` is behavior-equivalent to
`wp_check_invalid_utf8( $s, true )` except for the legacy function's
`blog_charset` gate.

### `wp_check_invalid_utf8()` passes through all bytes for non-UTF-8 charsets

Representative input under `ISO-8859-1` charset: `C0 80` returns `C0 80` in
both modes.

Classification: intentional environment sensitivity.

Evidence:

- The current docblock says the function only performs work when
  `blog_charset` is UTF-8.
- Trac #63837 calls out that the function assumes input strings are encoded
  with `blog_charset`, and says that point is inherent to how it works:
  https://core.trac.wordpress.org/ticket/63837

Impact:

Neither `wp_is_valid_utf8()` nor `wp_scrub_utf8()` is a drop-in replacement
where the caller intentionally wants `blog_charset`-dependent passthrough.

## Current Core Callers

### `seems_utf8()`

No production callers remain in this checkout. The only in-tree production
reference found by `rg` is the function definition itself.

Migration guidance:

- For validation callers, `wp_is_valid_utf8()` is the intended replacement, but
  it is behavior-changing for overlongs, surrogates, above-range code points,
  and 5/6-byte forms.
- For charset-guessing callers, `wp_is_valid_utf8()` is not a semantic drop-in.
  Such callers should make the heuristic explicit instead of using
  `seems_utf8()`.

### `esc_js()`

Current call: `wp_check_invalid_utf8( $text )`.

Migration guidance:

- `wp_is_valid_utf8()` is not a drop-in; it returns a boolean and does not
  produce escaped text.
- `wp_scrub_utf8()` is behavior-changing; invalid input would be preserved with
  `U+FFFD` instead of blanked before JavaScript escaping.
- Keep `wp_check_invalid_utf8()` unless the security model is explicitly
  changed from whole-string rejection to scrubbing.

### `esc_html()`

Current call: `wp_check_invalid_utf8( $text )`.

Migration guidance:

- `wp_scrub_utf8()` is behavior-changing but may be a future product decision if
  preserving partially valid display text is preferred.
- It is not a drop-in for current behavior because default-mode
  `wp_check_invalid_utf8()` returns `''` for any invalid UTF-8 under UTF-8
  charset and passes raw bytes through under non-UTF-8 charset.

### `esc_attr()`

Current call: `wp_check_invalid_utf8( $text )`.

Migration guidance:

- Attribute context is especially sensitive to partial decoding and downstream
  parser behavior. Keep whole-string rejection unless a dedicated security
  review approves replacement-character scrubbing.
- `wp_is_valid_utf8()` is not a drop-in output function.

### `esc_xml()`

Current call: `wp_check_invalid_utf8( $text )`.

Migration guidance:

- `wp_scrub_utf8()` would be plausible for XML generation because XML requires
  valid character data, but it changes the output contract from blanking to
  replacement.
- A direct replacement needs XML-specific review, especially because XML also
  has character restrictions beyond UTF-8 well-formedness.

### `_sanitize_text_fields()`, via `sanitize_text_field()` and `sanitize_textarea_field()`

Current call: `wp_check_invalid_utf8( $str )`.

Migration guidance:

- `wp_scrub_utf8()` is behavior-changing: stored/sanitized values that
  currently become empty would retain valid surrounding text and replacement
  characters.
- This may be user-friendlier, but it is not a drop-in. Treat it as a product
  and compatibility decision.

### `_wp_json_convert_string()`

Current fallback call: `wp_check_invalid_utf8( $input_string, true )`, only when
`mb_convert_encoding()` is unavailable.

Migration guidance:

- Under UTF-8 charset on this branch, `wp_scrub_utf8()` is behavior-equivalent
  for generated invalid inputs and is the clearer operation.
- It is still not a full drop-in because `wp_check_invalid_utf8()` preserves raw
  input when `blog_charset` is not UTF-8.
- JSON output must be UTF-8, so this is the best candidate for a targeted future
  migration away from `wp_check_invalid_utf8()`.

## Recommendations

### `seems_utf8()`: keep deprecated; do not repair in place

The function is a loose structural heuristic with no remaining production core
callers. It accepts several classes of invalid UTF-8 by design of its bit-mask
model, and changing the implementation in place would silently change external
caller behavior.

Recommended action:

- Keep the existing deprecation to `wp_is_valid_utf8()`.
- Do not include it in continuous differential fuzzing against strict UTF-8
  validation; the known divergences are permanent unless the deprecated
  function is removed or broken for compatibility.
- If docs are touched, say explicitly that it accepts overlongs, surrogates,
  above-range code points, and obsolete 5/6-byte forms. The current docblock
  mentions 5-byte sequences, but not the full divergence set.

### `wp_check_invalid_utf8()`: document and leave for default-mode callers

The current branch has already removed the historical PCRE dependency for
UTF-8 charset requests. The remaining divergences are semantic:

- default mode rejects the entire invalid string;
- strip mode scrubs with `U+FFFD`;
- all modes pass bytes through when `blog_charset` is not UTF-8.

Recommended action:

- Keep default-mode calls in escaping and sanitization until each context has an
  explicit security and compatibility decision.
- Prefer `wp_scrub_utf8()` for new code that unconditionally wants valid UTF-8
  output and does not want `blog_charset` sensitivity.
- Consider a targeted follow-up for `_wp_json_convert_string()`'s fallback path,
  because JSON wants UTF-8 and current `$strip = true` behavior already matches
  `wp_scrub_utf8()` under UTF-8 charset.

## Sources Checked

- Local function history: `git log -L :seems_utf8:src/wp-includes/formatting.php`
- Local function history: `git log -L :wp_check_invalid_utf8:src/wp-includes/formatting.php`
- Current `seems_utf8()` deprecation and `wp_is_valid_utf8()` introduction:
  commit `bb6ed3ba22`
- Current `wp_check_invalid_utf8()` / `wp_scrub_utf8()` rewrite:
  commit `d1e7f5625b`
- Trac #9692, `seems_utf8()` cleanup:
  https://core.trac.wordpress.org/ticket/9692
- Trac #8767, original `wp_check_invalid_utf8()` security refactor:
  https://core.trac.wordpress.org/ticket/8767
- Trac #38044, RFC 3629 compliance and `wp_is_valid_utf8()`:
  https://core.trac.wordpress.org/ticket/38044
- Trac #63837, `wp_check_invalid_utf8()` rewrite and `wp_scrub_utf8()`:
  https://core.trac.wordpress.org/ticket/63837
- Trac #29717, historical PCRE behavior and caller importance:
  https://core.trac.wordpress.org/ticket/29717
- Trac #63863, standardizing UTF-8 handling and fallbacks:
  https://core.trac.wordpress.org/ticket/63863
