# Implement Attribute Handling and Noah's Ark Clause

## Objective

Implement attribute handling for active formatting element reconstruction and the Noah's Ark clause in the WordPress HTML API. This enables reconstructed formatting elements to preserve their original attributes and limits duplicate formatting elements to 3 per identical tag+attribute combination.

## Key Requirements

### Attribute Handling
- Add `$attributes` property to `WP_HTML_Token` class
- Capture all attributes when pushing formatting elements to the active formatting elements list
- Clone attributes from original entry when reconstructing elements
- Override `get_attribute()` to return virtual attributes for reconstructed elements
- Override `get_attribute_names_with_prefix()` for reconstructed elements

### Noah's Ark Clause
- Implement in `WP_HTML_Active_Formatting_Elements::push()` method
- When pushing, count matching elements (same tag, namespace, attributes) after last marker
- If 3 identical elements exist, remove the earliest before adding new one
- Attribute comparison: case-insensitive names, exact value match, order-independent

## Files to Modify

1. `src/wp-includes/html-api/class-wp-html-token.php` - Add `$attributes` property
2. `src/wp-includes/html-api/class-wp-html-processor.php` - Attribute capture, cloning, virtual access
3. `src/wp-includes/html-api/class-wp-html-active-formatting-elements.php` - Noah's Ark logic
4. `tests/phpunit/tests/html-api/wpHtmlProcessorReconstructActiveFormattingElements.php` - Unit tests
5. `tests/phpunit/tests/html-api/wpHtmlProcessorHtml5lib.php` - Remove Noah's Ark skip

## Acceptance Criteria

- [ ] Reconstructed elements expose attributes via `get_attribute()`
- [ ] Reconstructed elements list attributes via `get_attribute_names_with_prefix()`
- [ ] Noah's Ark limits identical formatting elements to 3
- [ ] All existing tests pass (no regressions)
- [ ] 8 attribute-related html5lib tests pass
- [ ] 1 Noah's Ark html5lib test passes (adoption01/line0318)

## Test Commands

```bash
# Run all html-api tests
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api

# Run html5lib tests
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api-html5lib-tests
```

## Detailed Design

See `.sop/planning/design/detailed-design.md` for complete architecture, code examples, and implementation details.

## Implementation Plan

See `.sop/planning/implementation/plan.md` for the 13-step checklist with detailed guidance for each step.
