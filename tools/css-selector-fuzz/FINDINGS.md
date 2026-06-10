# CSS Selector Fuzzer — Findings

Run: branch `html-css-fuzz` @ `6ebbcc2fe4`, PHP 8.4.21. ~3600 deterministic
seeds, 0 crashes/timeouts. Three distinct, reproduced WordPress-core correctness
bugs in the new HTML-API CSS selector support. Every selector below is valid,
supported CSS that the API mis-handles **without** reporting lack of support.

Reproduce any case: `php tools/css-selector-fuzz/replay.php --selector '<sel>' [--html '<html>']`.

---

## Bug 1 — Identity escapes mis-decode after a multibyte character (mis-parse)

**Invariant:** `ast-mismatch` (57 hits, the dominant signature).

`WP_CSS_Selector_Parser_Matcher::consume_escaped_codepoint()` decodes a
non-hex ("identity") escape with:

```php
$codepoint_char = mb_substr( $input, $offset, 1, 'UTF-8' );
```

`$offset` is a **byte** offset (threaded by reference through the whole
selector-string parse), but `mb_substr()`'s 2nd argument is a **character**
index. The two diverge by one per multibyte continuation byte seen earlier in
the string, so an identity escape preceded by any multibyte content decodes the
**wrong codepoint** (reads N characters too far right, N = preceding continuation bytes).

Minimal reproduction (second selector's type should be `sup`):

| selector | parsed context type |
|---|---|
| `#abc,\sup #x`  | `sup` ✅ |
| `#Ü,\sup #x`    | `uup` ❌ |
| `#ÜÜ,\sup #x`   | `pup` ❌ |
| `#ÜÜÜ,\sup #x`  | `" up"` ❌ |

Hex escapes (`\75 `) use byte-correct `substr` and are unaffected; only the
non-hex identity-escape branch is wrong. Depending on what wrong codepoint is
produced this also causes spurious parse failures (a valid selector returns
`null`).

**Fix direction:** read the next codepoint by byte offset, e.g.
`mb_substr( substr( $input, $offset ), 0, 1, 'UTF-8' )`, or decode the UTF-8
lead byte length from `$input[$offset]` directly.

---

## Bug 2 — Empty-value `^=` `*=` `$=` match everything instead of nothing (mis-match)

**Invariant:** `match-mismatch-html` / `match-mismatch-tag` (12 hits).

Per Selectors-4, `[attr^=""]`, `[attr*=""]`, `[attr$=""]` match **nothing** (an
empty operand never matches). `WP_CSS_Attribute_Selector::matches()` instead:

- `^=`: `substr_compare($attr,'',0,0) === 0` → always 0 → matches any element with the attribute.
- `*=`: `strpos($attr,'') === 0` (PHP) → matches any element with the attribute.
- `$=`: matches elements whose attribute value is exactly `""`.

`~=` is handled correctly (returns nothing for an empty/whitespace operand).

Reproduction against `<i x="">…</i><b x="abc">…</b><u>…</u>`:

| selector | WP matches | spec |
|---|---|---|
| `[x^=""]` | `I, B` | none |
| `[x*=""]` | `I, B` | none |
| `[x$=""]` | `I`    | none |
| `[x~=""]` | none ✅ | none |

**Fix direction:** in `matches()`, return `false` for `^= $= *=` (and `~=`) when
`'' === $this->value`, before the `substr_compare`/`strpos` calls. (This also
removes a `substr_compare` negative-length edge with very short attribute values.)

---

## Bug 3 — Off-by-one length guard rejects `[name=x]` at end of string (false reject)

**Invariant:** `parse-expectation` (1 hit; valid selector → `null`).

`WP_CSS_Attribute_Selector::parse()` guards "need at least `=x]` remaining":

```php
// need to match at least `=x]` at this point
if ( $updated_offset + 3 >= strlen( $input ) ) {
    return null;
}
```

`>=` is off by one: it also rejects the exact-fit case where `=x]` **is** the
remaining tail. This rejects a valid attribute selector that uses the exact-match
`=` operator with a single-character **unquoted** value when its `]` is the last
character of the selector string.

| selector | result |
|---|---|
| `[a=b]`        | `null` ❌ |
| `div.x[y=z]`   | `null` ❌ |
| `[a=bb]`       | parsed ✅ (2-char value) |
| `[a="b"]`      | parsed ✅ (quoted) |
| `[a^=b]`       | parsed ✅ (2-char operator) |
| `[a=b].c`      | parsed ✅ (trailing content) |

**Fix direction:** change `>=` to `>` (need `strlen - $updated_offset >= 3`).

---

## Fuzzer status

Implemented and validated: deterministic seeds, seed-based replay, generative
6-bucket selector generation, independent reference matcher, ~18 invariants,
process-isolated runner, self-check suite. `php tools/css-selector-fuzz/tests/self-check.php`
passes; see `README.md` for usage. No fuzzer-side (oracle/generator) defects
surfaced in 3600 seeds — all failures are the three target bugs above.
