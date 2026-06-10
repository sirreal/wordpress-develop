# lexbor — draft upstream bug reports

Three spec-conformance bugs in liblexbor's CSS selectors support, found while
using lexbor as a differential oracle for the WordPress HTML-API CSS selector
fuzzer (`tools/css-selector-fuzz/`). All three were re-verified directly
against the harness on 2026-06-10.

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
   `U+00B7`, `non-ascii`). #368 shows the maintainer's preferred repro style.
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
