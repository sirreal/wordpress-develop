# Handoff: property-based tests for WP_Token_Map

## Status

Not started. **This class HAS existing tests — explore them before
acting**: `tests/phpunit/tests/wp-token-map/wpTokenMap.php` (8 test
methods). Read that file first and map what is already covered; do not
duplicate it. As of commit `3cc3e64765` the existing coverage includes:
construction validation, over-long word rejection, round-trip through
`to_array()`, round-trip through `precomputed_php_source_table()` /
`from_precomputed_table()`, longest-match-first behavior, short words
(shorter than the group key length), reading at an offset, and a sweep
over all HTML5 named references. The *gap* is adversarial/generated
token sets and randomized probes — the existing tests use a handful of
hand-picked fixtures.

## Why property tests, not a continuous fuzzer

`WP_Token_Map` (`src/wp-includes/class-wp-token-map.php`) is a static
data structure with a free, trivially-correct reference implementation:
a linear scan over the source array. No external oracle, no subprocess,
deterministic. That shape belongs in PHPUnit (fast, runs in CI forever)
rather than a CPU-burning fuzz loop. The production-critical instance
(`$html5_named_character_reference`) additionally gets exercised
transitively by the WP_HTML_Decoder fuzzer lane.

## Properties to test

Against a naive reference (`contains`: `in_array` with optional
`strcasecmp`; `read_token`: try every word sorted by length descending,
return first prefix match):

1. `contains( $word, $case_sensitivity )` ≡ reference, for every word
   in the set, every prefix of a word, every word with one byte
   appended/removed/changed, and random probes.
2. `read_token( $text, $offset )` ≡ reference (token AND
   `$matched_token_byte_length`), at every offset of generated
   documents that embed tokens, near-tokens, and token prefixes.
3. Greedy longest-match: when one word is a prefix of another and both
   could match, the longer wins (generate nested-prefix families
   deliberately: `a`, `ab`, `abc`, …).
4. Round-trips on *generated* maps (the existing tests round-trip
   fixtures): `from_array( to_array() )` preserves behavior;
   `eval`'d `precomputed_php_source_table()` →
   `from_precomputed_table()` preserves behavior. Compare behavior
   (all probes), not just array equality.
5. Case-insensitive mode with non-ASCII bytes: PHP's `strcasecmp` is
   byte/locale-ASCII; verify the class and reference agree on what
   "ascii-case-insensitive" means for bytes ≥ 0x80 (the docblock says
   ASCII case only — pin that).

## Generated token sets — where bugs would live

Deterministic seeds (`mt_srand` with fixed seed, or reuse
`tools/encoding-fuzz/lib/Prng.php`), sets of 1–200 words drawn from:

- words shorter than `$key_length` (the "small words" storage path),
  exactly `$key_length`, and up to the 256-byte limit
- nested prefix families
- words sharing the same `$key_length`-byte group key
- bytes: ASCII letters both cases, digits, `;`, high bytes ≥ 0x80,
  multibyte UTF-8 sequences, and NUL — wait for what existing tests
  cover regarding NUL; if undefined, document rather than assert
- `$key_length` values 1 and 2 (and whatever range the class accepts)

Probe documents: concatenations of set words, prefixes, near-misses,
random bytes, at random offsets.

## Practical notes

- Match the existing test file's conventions (data providers, group
  annotations). New file suggested:
  `tests/phpunit/tests/wp-token-map/wpTokenMapProperties.php` with a
  `@group token-map` annotation consistent with the existing file.
- Keep runtime sane for CI: a few thousand generated probes per
  property, fixed seed so failures are reproducible. Print the seed
  and the serialized token set in assertion messages so a failure is
  immediately actionable.
- `precomputed_php_source_table()` round-trip uses `eval` — the
  existing test already does this; follow its pattern.
- If a property fails, minimize by hand (sets are small) and add the
  minimal case as a fixed regression test alongside the property.

## Definition of done

New property test file passing under
`vendor/bin/phpunit --group token-map` (or this repo's equivalent:
`npm run test:php -- --group token-map`), covering the five properties
on generated sets, with documented seeds, no duplication of the eight
existing tests, and any discovered divergence filed/minimized rather
than worked around.
