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

### Current: 1372ms mean (stddev 26ms) — 44.1% improvement

11. **Optimize tag name parsing with direct char check + single strcspn** — Replace `strspn()` + `strcspn()` combo for tag name detection with direct character range comparison. Move bounds check before character access. ~50ms.

12. **Read token name from current_token->node_name** — In all step_in_* methods, read `$this->state->current_token->node_name` instead of calling `get_token_name()`. Avoids method call + switch per token. ~30ms.

13. **Pre-compute $op string once in step()** — The operation string (`+DIV`, `-DIV`, `#text`) was recomputed in every step_in_* method. Compute once in step() and store as property. Marginal but removes 55 lines of redundant code.

14. **Use parent::is_tag_closer() directly in step()** — During step(), current_element is always null so the overridden is_tag_closer() virtual check always falls through. Skip the dispatch. Marginal.

15. **Inline expects_closer() checks in hot-path loops** — Replace method calls with inline property checks and isset() lookup in both next_visitable_token() and step(). ~50ms.

16. **Add is_pop boolean to stack events, merge pop handling** — Pre-computed boolean on WP_HTML_Stack_Event replaces string comparison per event. Merged two separate is_pop blocks into one. ~10ms.

17. **Inline get_token_name() for tags and text in step()** — Fast-path matched tags (call get_tag() directly) and text nodes (return '#text' immediately), avoiding method call + switch dispatch. ~40ms.

18. **Cache current_node on open elements stack** — Maintain a cached reference updated on push/pop/remove_node. Avoids calling `end()` on every `current_node()` access. ~40ms.

19. **Optimize push/pop handlers with parent::is_tag_closer()** — Use `parent::is_tag_closer()` instead of `$this->is_tag_closer()` to skip is_virtual() dispatch chain. Cache current_token in local variable. ~50ms.

20. **Skip change_parsing_namespace() for HTML-namespace tokens** — Avoid calling the method when the namespace is already 'html'. Marginal.

21. **Remove redundant isset in provenance computation** — When is_virtual is false, current_token is guaranteed set. Marginal.

22. **Remove unused operation property assignment** — The string operation property is dead code since all checks use is_pop boolean. Marginal.

23. **Pass boolean is_pop directly to stack event constructor** — Replace string comparison `self::POP === $operation` with a direct boolean parameter. ~30ms.

24. **Skip stack operations for non-element tokens** — Non-element tokens (text, comments) are always immediately popped from the stack on the next step(). Skip the actual stack push/pop and create the event directly. Also skip adding them to breadcrumbs (they cancel out). ~110ms.

25. **Fast-path text nodes in step() for IN_BODY mode** — Inline the text node handling from step_in_body() directly in step(). Avoids method call, variable assignments, and switch dispatch. ~40ms.

26. **Inline event creation for fast-path text nodes** — Create the stack event directly in the fast path instead of going through insert_html_element(). ~20ms.

27. **Skip bookmark creation for fast-path text tokens** — Text tokens don't need bookmarks for read-only tokenization. Skip bookmark_token(), set_bookmark(), and WP_HTML_Span allocation. Create lightweight WP_HTML_Token with no bookmark. ~65ms.

28. **Inline get_adjusted_current_node() in step()** — Replace method call with inline logic. For full parsers, just calls current_node(). ~20ms.

29. **Inline is_tag_closer() in step()** — Make is_closing_tag protected and inline the check. For start tags, short-circuits on is_closing_tag=false. ~12ms.

30. **Fast bookmark creation** — Skip state checks, array_key_exists, and count() overflow guard in set_bookmark. Since bookmarks use monotonically increasing integer names, overflow can't happen. ~14ms.

31. **Defer current_op past text fast path** — Skip op string computation for fast-pathed text tokens. Marginal.

32. **Move text fast path before tag-specific computations** — Place text node fast path right after token parsing, inside the subdivide_text_appropriately block. Skips adjusted_current_node, is_matched_tag, is_closer, is_start_tag, and token_name ternary chain for text tokens. ~24ms.

33. **Inline bookmark_token() in step()** — Replace method call with inline code. Marginal.

34. **Inline has_self_closing_flag() in step()** — Make token_starts_at and token_length protected. For non-matched tags, short-circuits. For matched tags, avoids method call. ~35ms.

35. **Inline get_tag() in step()** — Make tag_name_starts_at, tag_name_length, tag_name_cache protected. Inline the strtoupper(substr()) computation, compute token_name first, use cached value for BR check. ~25ms.

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
- **Eliminate WP_HTML_Stack_Event allocation** — use parallel arrays instead of objects for event queue
- **Replace WP_HTML_Stack_Event with struct-of-arrays** — Use 3 parallel arrays (eq_tokens, eq_is_pop, eq_is_virtual) instead of WP_HTML_Stack_Event objects. No measurable improvement; PHP allocates small objects efficiently
- **Fast-path comments in step()** — No comments in html-standard.html; adds branch overhead with no benefit
- **Skip has_self_closing_flag() for HTML namespace** — Added namespace check costs same as the method call; no improvement
- **Cache stack_of_open_elements reference** — PHP property chains already well-optimized; no improvement
- **Cache op strings with ??=** — Hash table lookup costs more than short string concatenation
- **Defer current_op past text fast path** — Text tokens don't concatenate (not matched tags); saving is just one pointer assignment
- **Skip bookmark creation for comment tokens** — same approach as text tokens
- **Fast-path comments in step()** — similar to text fast-path; comments in IN_BODY are always simple insert+return
- **Cache stack_of_open_elements reference** — avoid repeated property access chain
- **Avoid WP_HTML_Token allocation for text tokens** — reuse a single text token object
