# Handoff: independent fuzzer for WP_HTML_Decoder

## Status

Not started. This is a NEW fuzzer, separate from `tools/encoding-fuzz/`
(UTF-8 functions) and from the `html-api-fuzz` branch (whole-tree
parser comparison). Reuse the architecture of `tools/encoding-fuzz/` —
deterministic `(seed, case)` generation, oracle startup battery,
worker/runner/replay/minimize CLIs, mutation-tested harness smoke test —
but as its own tool directory (suggested: `tools/html-decoder-fuzz/`).

## Target

`WP_HTML_Decoder` in `src/wp-includes/html-api/class-wp-html-decoder.php`:

- `decode_text_node( $text )`
- `decode_attribute( $text )`
- `read_character_reference( $context, $text, $at, &$match_byte_length )`
- `attribute_starts_with( $haystack, $search, $case_sensitivity )`

This is security-relevant code: decoded attribute values feed
`javascript:` URL detection via `attribute_starts_with`. Existing unit
tests are thin (`tests/phpunit/tests/html-api/wpHtmlDecoder.php`, 4 test
methods) — fuzzing has real headroom here.

Dependency note: the named-reference path uses `WP_Token_Map` and the
`$html5_named_character_reference` map
(`src/wp-includes/html-api/html5-named-character-references.php`).
A decoder fuzzer transitively exercises both.

## Oracle

`Dom\HTMLDocument` (lexbor, PHP 8.4+) — the same oracle the
`html-api-fuzz` branch uses for tree comparison:

- Text context: parse `<!DOCTYPE html><body><div>PAYLOAD</div>`, read
  the div's `textContent`; compare with `decode_text_node( PAYLOAD )`.
- Attribute context: parse `<div title="PAYLOAD">`, read
  `getAttribute('title')`; compare with `decode_attribute( PAYLOAD )`.

Do NOT use `html_entity_decode( ENT_HTML5 )` as the primary oracle: it
does not implement the WHATWG attribute-context rules (named reference
without semicolon followed by `=` or alphanumeric must NOT decode in
attributes) and will drown the run in false divergences. It MAY serve
as a third opinion on the text context only, gated by a known-answer
battery like `Oracles::battery()` in the encoding fuzzer — verify
empirically before trusting it, including C1-control numeric reference
remapping (`&#x80;` → U+20AC etc.).

## Confounders the harness must neutralize

The oracle is a full HTML parser; the target is a pure decoder. The
generator must avoid payload bytes the parser treats specially, or the
comparison measures parser behavior instead of decoding:

- `<`, `>`, `&` followed by structure-breaking content — escape `<` as
  text? No: restrict generated payloads to never contain raw `<`; `&`
  is the whole point and is fine in both contexts.
- Quote characters in the attribute payload — generate with `"` 
  excluded (or swap quote style per case), since it terminates the
  attribute in the oracle document but not in `decode_attribute()`.
- CR / CRLF: the HTML parser normalizes `\r` and `\r\n` to `\n` before
  tokenization; the decoder does not. Either exclude `\r` from payloads
  or pre-normalize before comparison — decide once, document it.
- NUL bytes: parser replaces U+0000 with U+FFFD in some contexts /
  drops in others; the decoder has its own documented NUL handling
  (see existing test `test_character_reference_with_null_byte...`).
  Probably exclude raw NUL from oracle-compared cases and cover NUL
  via fixed regression vectors instead.
- Invalid UTF-8 payload bytes: lexbor may scrub them before the
  tokenizer sees them. Start with valid-UTF-8 payloads only; invalid
  bytes inside character references (`&am\xC0p;`) are a later, careful
  extension.

## Generator: entity grammar, not byte noise

Weighted mix targeting the reference-matching state machine:

- Named references from the real token map: exact (`&amp;`), without
  semicolon (`&amp`), longest-match ambiguity (`&not` vs `&notin;` —
  the map is greedy-longest), case variants (`&AMP` vs `&amp`),
  truncations (`&am`), nonexistent lookalikes (`&ampx;`).
- The attribute-context discriminator: no-semicolon named reference
  followed by `=`, by alphanumerics, by `;` later in the string —
  decode in text, not in attribute.
- Numeric: decimal and hex, mixed case `x`/`X`, leading zeros (many),
  value classes: ASCII, C1 controls 0x80–0x9F (windows-1252 remap
  table), surrogates, noncharacters, > 0x10FFFF, huge (overflow
  arithmetic), zero, missing digits (`&#;`, `&#x;`).
- Adjacency and boundaries: references back to back, reference at
  string start/end, `&` at end of input, references split by the
  string boundary at every prefix length (truncation sweep).
- Plain text with multibyte UTF-8 around references (offset arithmetic).

Each case is `(context, payload)`; derive both from the PRNG.

## Checks

1. Differential vs oracle in both contexts (primary).
2. `read_character_reference()` consistency: decoding the whole string
   by repeated `read_character_reference` + literal spans must equal
   `decode()` output, and `$match_byte_length` must always advance.
3. `attribute_starts_with( $haystack, $search )` agrees with
   `str_starts_with( decode_attribute( $haystack ), $search )` for
   ASCII search strings, both case sensitivities.
4. Output is valid UTF-8 (reuse `mb_check_encoding`).
5. Idempotence does NOT hold for decoding (`&amp;amp;` decodes to
   `&amp;`) — do not add it; add instead: decoding text with no `&`
   is identity.

## Harness requirements (carry over from encoding fuzzer)

- Known-answer startup battery for the oracle path (hand-computed
  WHATWG expectations, including the C1 remap and no-semicolon
  attribute rules) — if the local `Dom\HTMLDocument` fails it, abort
  loudly.
- Mutation-tested smoke test: broken decoder variants (skip C1 remap,
  decode no-semicolon refs in attributes, off-by-one match length)
  must be caught before the fuzzer is trusted.
- Failure artifacts self-contained (base64 input + context + expected/
  got), replay + signature-preserving minimizer.
- Note `html-api-fuzz` branch precedent: its `attributes-entities`
  generator profile and oracle handling are prior art worth reading
  (`tools/html-api-fuzz/lib/Generator.php` on that branch).

## Definition of done

Smoke test green (including broken-variant detection), a 5-minute
multi-lane run either clean or with triaged findings, README with the
oracle-confounder decisions documented.
