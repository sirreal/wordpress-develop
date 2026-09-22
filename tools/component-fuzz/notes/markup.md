# Markup surface notes

`MarkupSurface` covers pure-PHP block, shortcode, and markup helper behavior with bounded generated inputs.

Primary invariants:

- `parse_blocks()` -> `serialize_blocks()` -> `parse_blocks()` preserves parsed block structure.
- `serialize_blocks()` equals the concatenation of `serialize_block()` for top-level parsed blocks.
- `has_blocks()` must be true for parser-confirmed named blocks; malformed `<!-- wp:` false positives are recorded as optimized detections.
- Local dummy shortcodes execute deterministically under `do_shortcode()`, `strip_shortcodes()` removes those registered tags, and the global shortcode registry is restored.
- `shortcode_parse_atts()` preserves simple generated key/value attributes.
- `get_shortcode_regex()` completes on bounded bracket storms without PCRE errors.
- `wp_strip_all_tags()` and sanitized `force_balance_tags()` output are idempotent.
- Excerpt/text helpers and stateless embed/oEmbed markup helpers are checked when their dependencies are loaded.

Guards:

- `do_blocks()` and `excerpt_remove_blocks()` are skipped unless `WP_Block` is available.
- `wp_trim_words()` is skipped unless its word-count/option dependencies are available.
- Deep oEmbed XML/title helpers are skipped unless the cache and option runtime are usable; lighter embed helpers still run after optional `wp-includes/embed.php` loading.
