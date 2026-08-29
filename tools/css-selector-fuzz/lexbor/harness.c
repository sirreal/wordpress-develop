/*
 * lexbor differential harness for the CSS selector fuzzer.
 *
 * Reads one case per line from stdin:
 *
 *     base64(html) "\t" base64(selector) "\n"
 *
 * For each case, parses the HTML with lexbor, parses the selector with the
 * lexbor CSS selectors module, runs lxb_selectors_find over the whole
 * document, and emits:
 *
 *     R "\t" TAG "\t" FID "\t" ANC1,ANC2,...   one per element, document
 *                                              pre-order; ancestors are
 *                                              nearest-first uppercase tags
 *     M "\t" FID                               one per match, in find order
 *     X "\t" parse                             selector did not parse
 *     X "\t" html                              html did not parse
 *     D                                        end of case (then flush)
 *
 * FID is the element's data-fid attribute value, or "(missing-fid:TAG)"
 * for elements without one (matching the fuzzer's placeholder convention).
 * Tags are ASCII-uppercased.
 *
 * Build: see build.sh next to this file. The script builds upstream lexbor
 * master and prints the exact commit used.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include <lexbor/html/html.h>
#include <lexbor/css/css.h>
#include <lexbor/selectors/selectors.h>

#define MAX_DEPTH 512

static unsigned char *
b64_decode(const char *in, size_t in_len, size_t *out_len)
{
    static const signed char table[256] = {
        ['A'] = 0,  ['B'] = 1,  ['C'] = 2,  ['D'] = 3,  ['E'] = 4,
        ['F'] = 5,  ['G'] = 6,  ['H'] = 7,  ['I'] = 8,  ['J'] = 9,
        ['K'] = 10, ['L'] = 11, ['M'] = 12, ['N'] = 13, ['O'] = 14,
        ['P'] = 15, ['Q'] = 16, ['R'] = 17, ['S'] = 18, ['T'] = 19,
        ['U'] = 20, ['V'] = 21, ['W'] = 22, ['X'] = 23, ['Y'] = 24,
        ['Z'] = 25, ['a'] = 26, ['b'] = 27, ['c'] = 28, ['d'] = 29,
        ['e'] = 30, ['f'] = 31, ['g'] = 32, ['h'] = 33, ['i'] = 34,
        ['j'] = 35, ['k'] = 36, ['l'] = 37, ['m'] = 38, ['n'] = 39,
        ['o'] = 40, ['p'] = 41, ['q'] = 42, ['r'] = 43, ['s'] = 44,
        ['t'] = 45, ['u'] = 46, ['v'] = 47, ['w'] = 48, ['x'] = 49,
        ['y'] = 50, ['z'] = 51, ['0'] = 52, ['1'] = 53, ['2'] = 54,
        ['3'] = 55, ['4'] = 56, ['5'] = 57, ['6'] = 58, ['7'] = 59,
        ['8'] = 60, ['9'] = 61, ['+'] = 62, ['/'] = 63,
    };

    unsigned char *out = malloc(in_len / 4 * 3 + 4);
    size_t o = 0;
    unsigned int acc = 0;
    int bits = 0;

    if (out == NULL) {
        return NULL;
    }

    for (size_t i = 0; i < in_len; i++) {
        unsigned char c = (unsigned char) in[i];
        /*
         * The PHP adapter always feeds well-formed base64_encode() output,
         * but guard anyway: skip padding/whitespace, and actually skip any
         * byte not in the alphabet ('A' legitimately maps to 0, so test the
         * byte itself, not its table value). c is unsigned, so table[c] is
         * always in bounds.
         */
        if (c == '=' || c == '\n' || c == '\r') {
            continue;
        }
        if (c != 'A' && table[c] == 0) {
            continue;
        }
        acc = (acc << 6) | (unsigned int) table[c];
        bits += 6;
        if (bits >= 8) {
            bits -= 8;
            out[o++] = (unsigned char) ((acc >> bits) & 0xFF);
        }
    }

    *out_len = o;
    return out;
}

static void
put_upper(const lxb_char_t *name, size_t len)
{
    for (size_t i = 0; i < len; i++) {
        unsigned char c = name[i];
        if (c >= 'a' && c <= 'z') {
            c = (unsigned char) (c - 'a' + 'A');
        }
        putchar(c);
    }
}

/*
 * Emit a data-fid value, replacing the framing bytes TAB / LF / CR with '?'.
 * Generated documents only ever use fids like "w12" / "e3", so this never
 * fires in practice; it guards the line-and-tab protocol against a fid that
 * contains a control char (which would otherwise desync row/match parsing on
 * the PHP side). LexborOracle applies the identical replacement when reading
 * WP's own fids, so a sanitized fid still compares equal — the worst case is
 * a benign tree-gated skip, never a false divergence.
 */
static void
put_fid_value(const lxb_char_t *value, size_t value_len)
{
    for (size_t i = 0; i < value_len; i++) {
        unsigned char c = value[i];
        putchar((c == '\t' || c == '\n' || c == '\r') ? '?' : c);
    }
}

static void
put_fid(lxb_dom_node_t *node)
{
    lxb_dom_element_t *element = lxb_dom_interface_element(node);
    size_t value_len = 0;
    const lxb_char_t *value = lxb_dom_element_get_attribute(
        element, (const lxb_char_t *) "data-fid", 8, &value_len);

    if (value != NULL) {
        put_fid_value(value, value_len);
        return;
    }

    size_t name_len = 0;
    const lxb_char_t *name = lxb_dom_element_qualified_name(element, &name_len);
    fputs("(missing-fid:", stdout);
    put_upper(name, name_len);
    putchar(')');
}

struct walk_state {
    const lxb_char_t *stack[MAX_DEPTH]; /* uppercase emitted on the fly */
    size_t stack_len[MAX_DEPTH];
    int depth;
};

static void
walk(lxb_dom_node_t *node, struct walk_state *state)
{
    for (lxb_dom_node_t *child = node->first_child; child != NULL;
         child = child->next) {
        if (child->type != LXB_DOM_NODE_TYPE_ELEMENT) {
            continue;
        }

        size_t name_len = 0;
        const lxb_char_t *name = lxb_dom_element_qualified_name(
            lxb_dom_interface_element(child), &name_len);

        fputs("R\t", stdout);
        put_upper(name, name_len);
        putchar('\t');
        put_fid(child);
        putchar('\t');
        for (int i = state->depth - 1; i >= 0; i--) {
            put_upper(state->stack[i], state->stack_len[i]);
            if (i > 0) {
                putchar(',');
            }
        }
        putchar('\n');

        if (state->depth < MAX_DEPTH) {
            state->stack[state->depth] = name;
            state->stack_len[state->depth] = name_len;
            state->depth++;
            walk(child, state);
            state->depth--;
        }
    }
}

static lxb_status_t
find_callback(lxb_dom_node_t *node, lxb_css_selector_specificity_t spec,
              void *ctx)
{
    (void) spec;
    (void) ctx;
    fputs("M\t", stdout);
    put_fid(node);
    putchar('\n');
    return LXB_STATUS_OK;
}

int
main(void)
{
    char *line = NULL;
    size_t line_cap = 0;
    ssize_t line_len;

    while ((line_len = getline(&line, &line_cap, stdin)) > 0) {
        char *tab = memchr(line, '\t', (size_t) line_len);
        if (tab == NULL) {
            fputs("X\tprotocol\nD\n", stdout);
            fflush(stdout);
            continue;
        }

        size_t html_len = 0;
        size_t selector_len = 0;
        unsigned char *html = b64_decode(line, (size_t) (tab - line), &html_len);
        unsigned char *selector = b64_decode(
            tab + 1, (size_t) (line + line_len - tab - 1), &selector_len);

        if (html == NULL || selector == NULL) {
            fputs("X\tprotocol\nD\n", stdout);
            fflush(stdout);
            free(html);
            free(selector);
            continue;
        }

        lxb_html_document_t *document = lxb_html_document_create();
        if (lxb_html_document_parse(document, html, html_len)
            != LXB_STATUS_OK) {
            fputs("X\thtml\nD\n", stdout);
            fflush(stdout);
            lxb_html_document_destroy(document);
            free(html);
            free(selector);
            continue;
        }

        struct walk_state state = { .depth = 0 };
        walk(lxb_dom_interface_node(document), &state);

        /*
         * Parser and selectors engine are created per case:
         * lxb_css_selector_list_destroy_memory() releases the parser's
         * whole arena, so reuse across cases is unsafe.
         */
        lxb_css_parser_t *parser = lxb_css_parser_create();
        lxb_selectors_t *selectors = lxb_selectors_create();
        if (lxb_css_parser_init(parser, NULL) != LXB_STATUS_OK
            || lxb_selectors_init(selectors) != LXB_STATUS_OK) {
            fputs("X\tinit\nD\n", stdout);
            fflush(stdout);
            lxb_selectors_destroy(selectors, true);
            lxb_css_parser_destroy(parser, true);
            lxb_html_document_destroy(document);
            free(html);
            free(selector);
            continue;
        }

        /* Report each node once even when several list branches match. */
        lxb_selectors_opt_set(selectors, LXB_SELECTORS_OPT_MATCH_FIRST);

        lxb_css_selector_list_t *list = lxb_css_selectors_parse(
            parser, selector, selector_len);

        if (parser->status != LXB_STATUS_OK || list == NULL) {
            fputs("X\tparse\n", stdout);
        }
        else {
            lxb_status_t status = lxb_selectors_find(
                selectors, lxb_dom_interface_node(document), list,
                find_callback, NULL);
            if (status != LXB_STATUS_OK) {
                fputs("X\tfind\n", stdout);
            }
            lxb_css_selector_list_destroy_memory(list);
        }

        fputs("D\n", stdout);
        fflush(stdout);

        lxb_selectors_destroy(selectors, true);
        lxb_css_parser_destroy(parser, true);
        lxb_html_document_destroy(document);
        free(html);
        free(selector);
    }

    free(line);
    return EXIT_SUCCESS;
}
