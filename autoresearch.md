# Autoresearch: HTML Tag Processor Performance

## Objective

Optimize `WP_HTML_Processor::next_token()` tokenization throughput on html-standard.html (~large real-world HTML). The benchmark iterates all tokens with no modifications — purely read-only tokenization speed.

## Metrics

-   **Primary**: mean execution time (ms, lower is better) via `hyperfine`
-   **Secondary**: peak memory (bytes, lower is better) via `/usr/bin/time -l`

## How to Run

`./autoresearch.sh` — runs hyperfine, outputs `METRIC mean_ms=number` lines.

## Files in Scope

-   `src/wp-includes/html-api/class-wp-html-processor.php` — HTML parser
-   `src/wp-includes/html-api/class-wp-html-tag-processor.php` — HTML syntax parser
-   `src/wp-includes/html-api/class-wp-html-attribute-token.php` — attribute token object (6 props, allocated per attr)
-   `src/wp-includes/html-api/class-wp-html-span.php` — span object (2 props, allocated on dup attrs)

## Off Limits

-   Test files
-   `bench.php` and `bootstrap-html-api.php`
-   Any file outside `src/wp-includes/html-api/`

## Constraints

-   PHPUnit tests must pass: `./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml --stop-on-error --stop-on-failure --stop-on-warning --stop-on-defect`
-   No new dependencies
-   stddev and outliers from hyperfine must remain acceptable
-   Changes must preserve all existing behavior

## What's Been Tried

### Baseline: 2453ms mean (stddev 40ms)

### Wins (cumulative, all committed)

1. **Cache `strlen($this->html)` in `$this->html_length`** — Replaced all `strlen($this->html)` calls in hot paths with cached property. Negligible on its own (strlen is O(1) in PHP), but eliminates function call overhead.

2. **Convert recursive `next_visitable_token()` to iterative loop + index pointer** — Replaced `array_shift()` with index-based access, replaced recursive calls with `continue`. 2453→2386 (~2.7%)

3. **Remove duplicate `after_tag()` call** — `parse_next_tag()` called `after_tag()` but was only called from `base_class_next_token()` which already calls it. Removed redundant call. Also guarded update-flushing logic with emptiness checks. 2386→2282 (~4.4%)

4. **Use local variables in `parse_next_attribute()`** — Cached `$this->html` and `$this->bytes_already_parsed` in local vars, inlined `skip_whitespace()`. Marginal.

5. **Optimize `expects_closer()` with lookup table** — Replaced `in_array()` + `is_void()` with `isset()` on a const array. Added early returns for `#text`, `#comment`. 2282→2204 (~3.4%)

6. **Cache `get_tag()` result** — Avoid redundant `substr + strtoupper` when `get_tag()` is called multiple times per token (from `step()`, `step_in_body()`, `get_token_name()`). 2204→2132 (~3.3%)

7. **Optimize `$op` construction in all step_in_* methods** — Replace `get_token_type()` + conditional sigil with direct `parser_state` check. Eliminates method call and string interpolation. 2132→2108 (~1.1%)

8. **Fast-path `subdivide_text_appropriately()`** — Skip null/whitespace detection when text starts with a regular character. Marginal.

9. **Replace `in_array` with direct comparisons in `step()` foreign content check** — Avoid temporary array allocation. Also converted `bookmark_token()` to return null on failure instead of throwing.

10. **Use int bookmark names** — Avoid int-to-string conversion per token by passing counter directly. ~14ms.

### Current: 1925ms mean (stddev 30ms) — 21.5% improvement

11. **Optimize tag name parsing with direct char check + single strcspn** — Replace `strspn()` + `strcspn()` combo for tag name detection with direct character range comparison. Move bounds check before character access. ~50ms.

12. **Read token name from current_token->node_name** — In all step_in_* methods, read `$this->state->current_token->node_name` instead of calling `get_token_name()`. Avoids method call + switch per token. ~30ms.

13. **Pre-compute $op string once in step()** — The operation string (`+DIV`, `-DIV`, `#text`) was recomputed in every step_in_* method. Compute once in step() and store as property. Marginal but removes 55 lines of redundant code.

14. **Use parent::is_tag_closer() directly in step()** — During step(), current_element is always null so the overridden is_tag_closer() virtual check always falls through. Skip the dispatch. Marginal.

15. **Inline expects_closer() checks in hot-path loops** — Replace method calls with inline property checks and isset() lookup in both next_visitable_token() and step(). ~50ms.

16. **Add is_pop boolean to stack events, merge pop handling** — Pre-computed boolean on WP_HTML_Stack_Event replaces string comparison per event. Merged two separate is_pop blocks into one. ~10ms.

17. **Inline get_token_name() for tags and text in step()** — Fast-path matched tags (call get_tag() directly) and text nodes (return '#text' immediately), avoiding method call + switch dispatch. ~40ms.

### Dead Ends

- **Inline `skip_whitespace()`** — No improvement; PHP optimizes short function calls well.
- **`call_user_func` → direct closure invocation** — No improvement in PHP 8.5.
- **Fast-path no-attribute tags** — Added branch overhead without enough benefit.
- **Replace `is_callable` with `null !==` in WP_HTML_Token destructor** — Made things slightly worse.
- **Remove redundant `$this->namespace = 'html'` in WP_HTML_Token constructor** — Made things slightly worse (combined with destructor change).
- **Defer `$this->attributes = array()` from after_tag() to ensure_attributes_parsed()** — Empty arrays are cheap in PHP 8 (shared empty array via COW). No improvement.
- **Replace WP_HTML_Span bookmarks with packed integers** — External code (interactivity API, block-template.php) accesses `$bookmark->start` and `$bookmark->length` directly. Can't change format.
- **Replace `count() > 0` with truthiness check in after_tag()** — `count()` on PHP arrays is O(1), negligible overhead.
- **Reorder `$parse_in_current_insertion_mode` to check namespace first** — Within noise.
- **Optimize text-tag boundary strspn check** — Fires less frequently than tag parsing; within noise.

### Architecture Notes

- ~1,077,000 tokens in html-standard.html (~1.8μs/token)
- Each token creates: WP_HTML_Token + WP_HTML_Span (bookmark) + 1-2 WP_HTML_Stack_Event + N WP_HTML_Attribute_Token
- Object allocations are a significant remaining bottleneck but deeply embedded in the architecture
- `strpos`/`strspn`/`strcspn` are C-implemented and already fast; the overhead is in PHP-level logic around them
- The insertion mode dispatch (big switch in step()) is a fixed cost that's hard to reduce
- External code depends on WP_HTML_Span bookmark format — can't pack bookmarks into integers
- WP_HTML_Token destructor changes (is_callable → null !==, call_user_func → direct invocation) surprisingly hurt performance

### Unexplored Ideas

- **Object pooling for WP_HTML_Stack_Event** — reuse event objects instead of allocating new ones
- **Combined token+event object** — merge WP_HTML_Token and WP_HTML_Stack_Event to reduce allocations
- **Pre-scanned tag name table** — for known HTML elements, use a lookup instead of substr+strtoupper
- **Avoid WP_HTML_Token allocation for reprocessed tokens** — skip constructor when reprocessing same token
- **Cache current_node() result** — avoid calling end($this->stack) multiple times per step
