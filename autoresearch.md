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

### Current: ~330ms (52.8% faster)

### Dead Ends
- **First-letter bitwise OR + 7 comparisons** — replacing strspn('iIlLnNpPsStTxX',...) was WORSE (655→605ms regression). PHP bitwise string OR creates allocation; 7 comparisons slower than one C-level strspn.
- **substr_compare for special element names** — replacing get_tag()+switch with substr_compare+switch-on-length showed no measurable improvement. The special element check is already rare (filtered by length + first letter). Added code complexity for zero gain.
- **Simplified closer detection** — removing ternary `$is_closer ? 1 : 0` by computing $tag_at incrementally. Neutral result.
- **Local vars for after_tag_match** — passing tag_length/tag_at as locals through the goto label. Neutral — property reads are hot in PHP's cache.
- **Pass $at parameter to skip_attributes_and_find_closer** — extra function parameter overhead cancels the property write/read savings.

### Architecture Notes
- **Token distribution**: ~646K tags, ~378K text nodes, ~247K attributes across ~1M tokens in html-standard.html
- **Text-tag alternation**: Most tokens alternate text→tag→text→tag. The strpos skip optimization exploits this — tags start at '<' so no search is needed.
- **PHP overhead dominates**: At 330ms / 1M tokens = 330ns/token. Property reads (~20-30ns each), property writes (~20-30ns), method dispatch (~50-100ns) are the main costs.
- **next_token()→base_class_next_token() dispatch**: ~1M extra method calls, but cannot be eliminated because get_updated_html() needs the base implementation.
- **Remaining writes per token**: text nodes ~7 writes, tags ~8 writes. Total ~8M writes per benchmark run at ~20ns each = ~160ms (48% of total).
