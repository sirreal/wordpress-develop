# Implement Reconstruct Active Formatting Elements Algorithm

## Objective

Complete the `reconstruct_active_formatting_elements()` method in `WP_HTML_Processor` to enable the HTML parser to properly handle misnested formatting elements per the HTML5 specification.

## Key Requirements

- Add index-based access methods to `WP_HTML_Active_Formatting_Elements`:
  - `get_at(int $index): ?WP_HTML_Token`
  - `replace_at(int $index, WP_HTML_Token $token): bool`
  - `index_of(WP_HTML_Token $token): ?int`

- Implement the full reconstruct algorithm with REWIND and ADVANCE phases:
  - REWIND: Walk backwards through the list to find the starting point
  - ADVANCE: Walk forwards creating new elements and updating the list

- Create helper method `create_element_for_formatting_token()` for virtual element creation
  - Follow the pattern used in `insert_virtual_node()`
  - Use `bookmark_token()` to generate virtual bookmarks

- Tag-name-only reconstruction initially (attribute cloning is future work)

## Acceptance Criteria

- [ ] All 1087 currently passing html-api tests continue to pass (no regressions)
- [ ] Tests previously skipped with "Cannot reconstruct active formatting elements when advancing and rewinding is required" now pass
- [ ] New unit tests cover the reconstruct algorithm behavior
- [ ] Code follows WordPress PHP coding standards
- [ ] All new methods have proper PHPDoc comments with `@since` tags

## Test Commands

```bash
# Fast html-api tests
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api

# html5lib tests
./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml
```

## Reference Documents

- **Detailed Design:** `.sop/planning/design/detailed-design.md`
- **Implementation Plan:** `.sop/planning/implementation/plan.md`
- **Research:** `.sop/planning/research/html5-spec-algorithms.md`

## Key Files to Modify

- `src/wp-includes/html-api/class-wp-html-active-formatting-elements.php`
- `src/wp-includes/html-api/class-wp-html-processor.php`

## Key Files to Create

- `tests/phpunit/tests/html-api/wpHtmlProcessorReconstructActiveFormattingElements.php`
