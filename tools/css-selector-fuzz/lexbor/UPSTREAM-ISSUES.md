# lexbor — draft upstream bug reports

Five spec-conformance bugs in liblexbor's CSS selectors support, found while
using lexbor as a differential oracle for the WordPress HTML-API CSS selector
fuzzer (`tools/css-selector-fuzz/`). Issues 1–3 were re-verified directly
against the harness on 2026-06-10; issues 4–5 surfaced during the WP
conformance-fix session and were re-verified on 2026-06-11.

- **Pinned version:** lexbor v3.0.0 (`2ae88a1c6b52`), built by
  `tools/css-selector-fuzz/lexbor/build.sh`.
- **Upstream repo:** https://github.com/lexbor/lexbor
- **Already filed upstream — do NOT refile:**
  [#368](https://github.com/lexbor/lexbor/issues/368) (class/`#id` selectors
  match ASCII case-insensitively in no-quirks documents).

## Instructions for the filing agent

1. **Re-verify at lexbor master first.** The pin is v3.0.0; any of these may
   already be fixed. Edit `build.sh` to build master (or clone/build manually)
   and re-run the repros below. Only file what still reproduces, and say in
   the report which commit you tested.
2. **Search for duplicates** before filing (suggested queries: `~=`,
   `attr-modifier`, `case insensitive modifier`, `ident code point`,
   `U+00B7`, `non-ascii`, `EOF`, `unclosed`, `simple block`,
   `case-insensitive attribute`, `querySelector`). #368 shows the
   maintainer's preferred repro style.
3. **One issue per bug.** Reduce each to a self-contained C repro (sketch
   below); maintainers should not need this repo's harness.
4. Reproduction via this repo (fast path): build the harness
   (`sh tools/css-selector-fuzz/lexbor/build.sh`), then feed it
   `base64(html) TAB base64(selector)` lines on stdin. Response lines per
   case: `R<TAB>tag<TAB>fid<TAB>ancestors` (tree rows), `M<TAB>fid` (match),
   `X<TAB>reason` (selector parse error), terminated by `D`. See
   `lib/LexborOracle.php` for a reference client and `harness.c` for the
   exact lexbor API usage (`lxb_html_document_parse`,
   `lxb_css_selectors_parse`, `lxb_selectors_find`).

Minimal C repro skeleton (adapt per issue; `harness.c` is the full reference):

```c
/* cc repro.c -llexbor */
#include <lexbor/html/html.h>
#include <lexbor/css/css.h>
#include <lexbor/selectors/selectors.h>

static lxb_status_t cb(lxb_dom_node_t *n, lxb_css_selector_specificity_t s, void *ctx) {
    (*(int *)ctx)++;
    return LXB_STATUS_OK;
}

int main(void) {
    const lxb_char_t html[] = "<!DOCTYPE html><i x=\" \"></i>";
    const lxb_char_t sel[]  = "[x~=\"\"]";
    int hits = 0;

    lxb_html_document_t *doc = lxb_html_document_create();
    lxb_html_document_parse(doc, html, sizeof(html) - 1);

    lxb_css_parser_t *parser = lxb_css_parser_create();
    lxb_css_parser_init(parser, NULL);
    lxb_selectors_t *selectors = lxb_selectors_create();
    lxb_selectors_init(selectors);

    lxb_css_selector_list_t *list =
        lxb_css_selectors_parse(parser, sel, sizeof(sel) - 1);
    if (list == NULL) { printf("selector parse error\n"); return 1; }

    lxb_selectors_find(selectors, lxb_dom_interface_node(doc),
                       list, cb, &hits);
    printf("matches: %d\n", hits); /* spec: 0 */
    return 0;
}
```

---

## Issue 1 — `[x~=""]` matches whitespace-only attribute values

Per Selectors Level 4, `[att~=val]` with an empty `val` never matches:

> If "val" is the empty string, it will never represent anything.
> — https://www.w3.org/TR/selectors-4/#attribute-representation (§6.1)

lexbor instead matches elements whose attribute value consists only of
whitespace, suggesting its list-splitting yields an empty token for
whitespace-only values. Verified at v3.0.0 (`data-fid="a"` on the element):

| document                  | selector  | lexbor    | spec / Chrome 149 |
|---------------------------|-----------|-----------|-------------------|
| `<i x=" ">` (space)       | `[x~=""]` | matches ❌ | no match          |
| `<i x="&#9;">` (tab)      | `[x~=""]` | matches ❌ | no match          |
| `<i x="">`                | `[x~=""]` | no match ✅ | no match          |
| `<i x="a b">`             | `[x~=""]` | no match ✅ | no match          |
| `<i x="a b">` (control)   | `[x~=a]`  | matches ✅ | matches           |

Chrome 149 (`document.querySelectorAll`) returns no match for all `[x~=""]`
rows (verified 2026-06-10 via Playwright during the WordPress fix review).

## Issue 2 — uppercase `I`/`S` attribute-selector modifiers rejected

Selectors Level 4 §6.3 defines the modifiers explicitly as case-insensitive:

> ...adding the identifier `i` (or `I`) ... adding the identifier `s` (or `S`) ...
> — https://www.w3.org/TR/selectors-4/#attribute-case

lexbor parses the lowercase forms but reports a selector parse error for the
uppercase forms. Verified at v3.0.0:

| selector     | lexbor        | spec    |
|--------------|---------------|---------|
| `[x=abc i]`  | parses ✅      | parses  |
| `[x=abc I]`  | parse error ❌ | parses  |
| `[x=abc s]`  | parses ✅      | parses  |
| `[x=abc S]`  | parse error ❌ | parses  |

Note for browser comparison: Chrome 149 had not shipped the `s` modifier at
all (throws SyntaxError), so compare `I` against Chrome and `S` against the
spec text / Firefox.

## Issue 3 — non-ASCII ident code points below U+00F8 rejected

CSS Syntax Level 3 defines the non-ASCII ident code points to include
U+00B7 and U+00C0–U+00D6 / U+00D8–U+00F6:

> non-ASCII ident code point: U+00B7, U+00C0 to U+00D6, U+00D8 to U+00F6,
> U+00F8 to U+037D, ...
> — https://www.w3.org/TR/css-syntax-3/#non-ascii-ident-code-point

lexbor's table appears to start at U+00F8: code points in the earlier ranges
are rejected both in ident-start and non-start positions. Verified at v3.0.0
(raw UTF-8 selectors; class attribute contains the same characters):

| selector  | codepoint(s)        | lexbor        | spec    |
|-----------|---------------------|---------------|---------|
| `.über`   | U+00FC (≥ U+00F8)   | parses ✅      | parses  |
| `.øx`     | U+00F8 (boundary)   | parses ✅      | parses  |
| `.Über`   | U+00DC (U+00D8–F6)  | parse error ❌ | parses  |
| `.a·b`    | U+00B7 (non-start)  | parse error ❌ | parses  |
| `.÷x`     | U+00F7 (excluded)   | parse error ✅ | error   |

The U+00F7 row is a control: the division sign is correctly NOT an ident code
point, so lexbor's boundary is off by exactly the U+00B7 / U+00C0–U+00F6
ranges. Workaround used by this fuzzer: hex-escape all non-ASCII (`\dc ber`
parses fine), which is why this surfaces only with raw multibyte selectors.

## Issue 4 — EOF does not auto-close an open attribute selector block

Per CSS Syntax Level 3, tokenization auto-closes unterminated simple blocks
at the end of input (a parse error, but the block is returned), and an
unterminated string at EOF returns the string token:

> \<EOF-token\>: This is a parse error. Return the block.
> — https://www.w3.org/TR/css-syntax-3/#consume-simple-block (§5.4.8)

> EOF: This is a parse error. Return the \<string-token\>.
> — https://www.w3.org/TR/css-syntax-3/#consume-string-token (§4.3.5)

So `[att=val` is the same selector as `[att=val]`, and `[att="a b` carries
the string value `a b`. lexbor reports a selector parse error for every
EOF-truncated attribute selector. Verified at v3.0.0 against
`<div att="val">` / `<div att="a b">`:

| selector        | lexbor        | spec / Chrome 149 |
|-----------------|---------------|-------------------|
| `[att]`         | parses ✅      | parses            |
| `[att=val]`     | parses ✅      | parses            |
| `[att`          | parse error ❌ | parses, matches   |
| `[att=val`      | parse error ❌ | parses, matches   |
| `[att="a b`     | parse error ❌ | parses, matches   |
| `[att=val i`    | parse error ❌ | parses, matches   |
| `div[att`       | parse error ❌ | parses, matches   |
| `[att=`         | parse error ✅ | error (grammar)   |
| `[att~`         | parse error ✅ | error (grammar)   |
| `[`             | parse error ✅ | error (grammar)   |
| `[att=val, div` | parse error ✅ | error (comma is inside the open block) |

The last four rows are controls: truncation inside the selector *grammar*
(matcher without value, lone bracket) is invalid even after auto-close, and
lexbor correctly rejects those. Chrome 149 (`document.querySelectorAll`)
accepts and rejects exactly per the table (verified 2026-06-10 via
Playwright). Note lexbor's escape handling at EOF is fine — `.foo\` parses
as class `foo\u{FFFD}` per §4.3.7 — the gap is specifically the simple-block
auto-close.

## Issue 5 — HTML's case-insensitive attribute value list not implemented

HTML defines 46 attributes (`type`, `rel`, `lang`, `dir`, `media`,
`hreflang`, `http-equiv`, ...) whose values must match ASCII
case-insensitively in attribute selectors on an HTML element when the
selector has no `i`/`s` modifier:

> Attribute selectors on an HTML element in an HTML document must treat the
> values of attributes with the following names as ASCII case-insensitive: …
> — https://html.spec.whatwg.org/multipage/semantics-other.html#case-sensitivity-of-selectors

lexbor matches all attribute values case-sensitively unless the selector
carries an explicit `i`. Verified at v3.0.0 against
`<a rel="NOFOLLOW">` (`e1`), `<a rel="nofollow">` (`e2`),
`<i data-x="ABC">` (`e3`):

| selector           | lexbor       | spec / Chrome 149 |
|--------------------|--------------|-------------------|
| `[rel=nofollow]`   | `e2` only ❌  | `e1` and `e2`     |
| `[rel=NOFOLLOW]`   | `e1` only ❌  | `e1` and `e2`     |
| `[rel=nofollow i]` | `e1`, `e2` ✅ | `e1` and `e2`     |
| `[rel=nofollow s]` | `e2` only ✅  | `e2` only         |
| `[data-x=abc]`     | no match ✅   | no match (unlisted attribute) |

The last three rows are controls: explicit modifiers work, and attributes
outside the list stay case-sensitive. Chrome 149 agrees with the spec column
(verified 2026-06-10 via Playwright), with one scoping caveat the report
should mention: the spec restricts the rule to elements in the HTML
namespace, but Chrome also folds on SVG-namespace elements
(`<svg><a type="text">` matches `[type=TEXT]`), so an implementation true
to the spec letter would scope by element namespace. This may be framed as
a feature request rather than a bug if lexbor considers document-language
selector rules out of scope for its selectors module — but lexbor is an
HTML engine and browsers uniformly implement the folding, so matching
against HTML documents diverges from every browser without it.
