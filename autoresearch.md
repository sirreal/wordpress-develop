# Autoresearch: HTML Tag Processor Performance

## Objective
Optimize `WP_HTML_Tag_Processor::next_token()` tokenization throughput on html-standard.html (~large real-world HTML). The benchmark iterates all tokens with no modifications — purely read-only tokenization speed.

## Metrics
- **Primary**: mean execution time (ms, lower is better) via `hyperfine`
- **Secondary**: peak memory (bytes, lower is better) via `/usr/bin/time -l`

## How to Run
`./autoresearch.sh` — runs hyperfine, outputs `METRIC mean_ms=number` lines.

## Files in Scope
- `src/wp-includes/html-api/class-wp-html-tag-processor.php` — main parser, all hot path methods
- `src/wp-includes/html-api/class-wp-html-attribute-token.php` — attribute token object (6 props, allocated per attr)
- `src/wp-includes/html-api/class-wp-html-span.php` — span object (2 props, allocated on dup attrs)
- `src/wp-includes/html-api/class-wp-html-text-replacement.php` — text replacement (3 props, not in hot path for read-only)

## Off Limits
- Test files
- `bench.php` and `bootstrap-html-api.php`
- Any file outside `src/wp-includes/html-api/`

## Constraints
- PHPUnit tests must pass: `./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml --stop-on-error --stop-on-failure --stop-on-warning --stop-on-defect`
- No new dependencies
- stddev and outliers from hyperfine must remain acceptable
- Changes must preserve all existing behavior

## What's Been Tried

### Baseline: ~699ms

### Wins (cumulative, all committed)
1. **Replace per-attribute function call loop with skip_attributes_and_find_closer()** — eliminates parse_next_attribute(false) calls. Single method scans for `>` handling quoted values.
2. **Inline after_tag() into base_class_next_token()** — removes method call overhead per token.
3. **Inline fast paths for text nodes and regular tags** — handles the two most common token types (text ~378K, tags ~646K) directly in base_class_next_token, falling through to full parse_next_tag() only for complex tokens.
4. **Direct byte comparisons for single-char strspn** — replace strspn for single-character checks with direct `===` comparisons.
5. **Cache doc_length as instance variable** — avoid strlen() per token.
6. **Fast path for '>' immediately after tag name** — skip attribute scanning for tags like `</div>`, `<br>`.
7. **Defer property resets to type-specific return paths** — text nodes only reset tag-related properties, tags only reset text-related properties.
8. **Tag name length filter before special element check** — special elements have lengths 3,5,6,7,8. Tags of other lengths return immediately without calling get_tag().
9. **Reorder checks: length before strspn** — many common tags eliminated by cheap integer comparison before the strspn function call.
10. **Optimize attribute scanner for common name="value" pattern** — check for `=` and quote char directly after attribute name, avoiding two strspn() calls that typically return 0.
11. **Inline single-space and '>' checks in attribute scanner loop** — replace strspn for whitespace between attributes with direct byte comparisons for single-space (most common) and '>' (tag closer).
12. **Remove redundant STATE_COMPLETE check** — $at >= $doc_length bounds check handles this case.
13. **Remove text_node_classification write from tag fast path** — never read for tag tokens.
14. **Use null text_starts_at for tags** — allows removing text_length=0 write. get_modifiable_text() returns '' on null text_starts_at.
15. **Avoid redundant bytes_already_parsed property read** — use local $was_at for $at when no lexical updates.
16. **Remove attribute_scan_from property** — compute scan position as tag_name_starts_at + tag_name_length on demand in ensure_attributes_parsed(). Eliminates property and 3 writes.
17. **Remove attributes_parsed write from text nodes** — all callers of ensure_attributes_parsed() guard with STATE_MATCHED_TAG check, so the flag is never read for non-tag tokens.
18. **Short-circuit closing tags before after_tag_match** — closing tags never need special element processing. Return early using local $is_closer instead of reading property through the shared label.
19. **Move closer check out of after_tag_match** — both fast path and full_parse path return early for closers. after_tag_match now only handles openers, eliminating is_closing_tag read.
20. **Skip strpos when at '<'** — check for '<' at current position before calling strpos(). Tags (~63% of tokens) start at '<' and skip the function call entirely.
21. **Remove text_starts_at null write for tags** — use bounds check (text_starts_at < token_starts_at) in get_modifiable_text() to detect stale text instead of proactively nulling.
22. **Restructure get_tag() for state-based dispatch** — check STATE_MATCHED_TAG first instead of null check on tag_name_starts_at. Allows skipping tag_name null writes for text nodes (~756K writes eliminated).
23. **Replace attributes_parsed boolean with version-based staleness check** — use attributes_parsed_at integer compared against token_starts_at. Eliminates ~646K attributes_parsed=false writes per parse iteration.
24. **Pre-filter special element length in fast path before goto** — check tag name length (3,5,6,7,8) before goto after_tag_match. Tags with lengths 1,2,4 (88% of all tags: a, p, br, li, span, code, etc.) return immediately.
25. **Merge STATE_INCOMPLETE_INPUT check into bounds check** — remove dedicated parser_state read at loop start. Set bytes_already_parsed=doc_length on incomplete input so the existing bounds check handles it. Eliminates 1 property read per token.

### Current: ~316ms (54.8% faster)

### Dead Ends
- **First-letter bitwise OR + 7 comparisons** — replacing strspn('iIlLnNpPsStTxX',...) was WORSE. PHP bitwise string OR creates allocation; 7 comparisons slower than one C-level strspn.
- **substr_compare for special element names** — no measurable improvement. The special element check is already rare.
- **Simplified closer detection** — removing ternary `$is_closer ? 1 : 0` by computing $tag_at incrementally. Neutral.
- **Local vars for after_tag_match** — passing tag_length/tag_at as locals through the goto label. Neutral.
- **Pass $at parameter to skip_attributes_and_find_closer** — extra function parameter overhead cancels savings.
- **Add strspn first-letter check to fast path filter** — adding strspn('iIlLnNpPsStTxX') alongside the length filter. Neutral — length filter already catches 88% of tags.
- **Conditional text_node_classification write** — `if (TEXT_IS_GENERIC !== $this->text_node_classification)` before writing. Neutral — the conditional read costs the same as the write.
- **1-byte text node lookahead** — check `$html[$at+1] === '<'` before calling strpos. WORSE (~15ms regression). The extra branch on every text path hurts; strpos with memchr is already very fast for single bytes.
- **Length-3 first-letter filter in fast path** — for len=3 tags, check first letter against p/P/x/X (only PRE/XMP are special). Neutral — extra comparisons offset the savings from avoiding after_tag_match for ~74K div tags.
- **Single boolean has_pending_updates flag** — replace `classname_updates || lexical_updates` (2 reads) with a single boolean. Too invasive: 16+ modification sites need `$this->has_pending_updates = true`. Correctness concerns with clearing the flag.
- **Defer classname_updates check** — only check lexical_updates in hot loop, defer classname conversion. Incorrect: classname conversion requires current tag's attributes; deferring past cursor advance would use wrong attributes.

### Architecture Notes
- **Token distribution**: ~646K tags (325K openers, 321K closers), ~378K text nodes, ~247K attributes, 1 other, across ~1M tokens in html-standard.html
- **Tag name length distribution**: len=1: 184K (28%), len=2: 211K (33%), len=3: 75K (12%), len=4: 174K (27%), len=5+: 4K (0.6%). Length filter catches 88% of tags.
- **Attribute distribution**: ~517K tags without attributes, ~129K with attributes (~20%)
- **Text node length**: 73K are 1 byte, 22K are 2 bytes, 30K are 3 bytes, etc. Most are short (whitespace between tags).
- **Text-tag alternation**: Most tokens alternate text→tag→text→tag. The strpos skip optimization exploits this — tags start at '<' so no search is needed.
- **PHP overhead dominates**: At 316ms / 1M tokens = 316ns/token (per pass, 3 passes). Property reads (~5-10ns each), property writes (~10-15ns), method dispatch (~10-20ns for JIT-optimized private calls).
- **next_token()→base_class_next_token() dispatch**: ~1M extra method calls, cannot be eliminated because get_updated_html() needs the base implementation.
- **Remaining property reads per token (hot path start)**: bytes_already_parsed, classname_updates, lexical_updates, html, doc_length = 5 reads.
- **Remaining property writes per token**: text nodes ~7, tags ~7. Total ~7M writes per benchmark pass.
- **Protected properties constrain optimization**: parser_state and text_node_classification are protected (read directly by WP_HTML_Processor subclass). Cannot defer or version-gate these without changing the subclass, which is off-limits.
- **after_tag() is dead code**: the method exists but is never called (fully inlined into base_class_next_token). Could be removed, but cosmetic.

### Unexplored Ideas
- **Stack operations on_push/on_pop callbacks** — the HTML processor's open_elements stack has push/pop callbacks that fire during tree-building. These are not in scope for the tag processor benchmark, but if the benchmark changes to use the HTML processor, these callbacks could be significant overhead.
- **Bookmark on_destroy callback** — bookmarks have cleanup behavior. Not in hot path for read-only benchmark.
- **Lazy token_length computation** — token_length = bytes_already_parsed - token_starts_at for all fast-path tokens. Could eliminate 1 write per token (~1M writes/pass). But read sites are numerous and some (special elements, bookmarks) set token_length independently. Would need to change all read sites.
- **Lazy is_closing_tag computation** — derive from html[token_starts_at+1] === '/'. Saves 1 write per tag but adds 2 property reads + 1 byte access per read (many read sites including subclass).
- **Integer state constants** — replace string parser_state constants with integers for faster comparison. But parser_state is protected and used by external code with string comparisons.
- **Packed tag name properties** — store tag_name_starts_at and tag_name_length in a single 64-bit int. Saves 1 write, adds shift/mask to reads. Only useful if reads are rare (true for fast-path-filtered tags).
- **Static variable caching for $html/$doc_length** — cache across method calls. Saves ~1 property read/call. Shared across instances (problematic for multi-instance usage).
- **Deferred property writes with lazy flush** — store pending token data, only write to properties when external code reads them. Saves all property writes for read-only benchmark. Requires flush checks in all getter methods. Protected properties can't be deferred.
- **Eliminate classname_updates read in hot loop** — both classname_updates and lexical_updates are always empty in the benchmark. Replacing 2 array truthiness checks with a single boolean flag would save 1 read/token, but requires setting the flag in 16+ update methods.
