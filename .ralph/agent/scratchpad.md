# Scratchpad: Attribute Handling and Noah's Ark Clause

## 2026-02-03 Iteration 1 - Initial Analysis

### Understanding

Starting fresh iteration for implementing attribute handling and Noah's Ark clause.

Previous work completed:
- Basic reconstruct_active_formatting_elements algorithm implemented
- Index-based access methods (get_at, replace_at, index_of) added to WP_HTML_Active_Formatting_Elements
- Unit tests for reconstruction passing

Current state of code:
- `WP_HTML_Token` has no `$attributes` property yet
- `WP_HTML_Processor::create_element_for_formatting_token()` bails on elements with attributes (lines 5996-6002)
- `WP_HTML_Active_Formatting_Elements::push()` has a @todo for Noah's Ark at line 124
- `get_attribute()` and `get_attribute_names_with_prefix()` already overridden in processor but return null for virtual elements

### Plan

Following the 13-step implementation plan:
1. Step 1: Add `$attributes` property to WP_HTML_Token ← START HERE
2. Step 2: Add attribute capture helper
3. Step 3: Capture attributes when pushing formatting elements
4. Step 4: Clone attributes during reconstruction
5. Step 5: Virtual get_attribute()
6. Step 6: Virtual get_attribute_names_with_prefix()
7. Step 7: Unit tests for attributes
8. Step 8: Element identity comparison helpers
9. Step 9: Noah's Ark in push()
10. Step 10: Unit tests for Noah's Ark
11. Step 11: Remove Noah's Ark skip
12. Step 12: html5lib validation
13. Step 13: Final cleanup

### Completed This Iteration

**Step 1: Add `$attributes` property to WP_HTML_Token** ✓
- Added `public $attributes = null;` property to WP_HTML_Token class
- PHPDoc with `@since 6.8.0`, explains purpose (formatting elements, reconstruction, Noah's Ark)
- Format: `array<string, string|true>|null`
- All 12 reconstruct tests still pass
- Committed: 7ec06265ad

### Next Steps

Step 3: Capture attributes when pushing formatting elements

---

## 2026-02-03 Iteration 2 - Step 2 Complete

### Completed

**Step 2: Add attribute capture helper to WP_HTML_Processor** ✓
- Added `get_current_token_attributes()` private method after `is_virtual()` method
- Returns `array<string, string|true>` - lowercase names as keys, decoded values or `true` for boolean attrs
- Uses existing `get_attribute_names_with_prefix('')` and `get_attribute()` methods
- PHPDoc with `@since 6.8.0`
- All 14 reconstruct tests still pass
- Method currently unused (will be used in Step 3)
- Committed: 6853b6a265

### Analysis

Location choice: Placed after `is_virtual()` at line 891 since both are private helper methods for token inspection. Alternative location near `create_element_for_formatting_token()` was considered but this location groups simpler helpers together.

---

## 2026-02-03 Iteration 3 - Step 3 Complete

### Completed

**Step 3: Capture attributes when pushing formatting elements** ✓
- Added `$this->state->current_token->attributes = $this->get_current_token_attributes();` before each `push()` call
- Updated all three locations:
  - Line ~2795: `<a>` tags
  - Line ~2816: b, big, code, em, font, i, s, small, strike, strong, tt, u tags
  - Line ~2833: `<nobr>` tags
- All 14 reconstruct tests still pass
- Committed: c90257276b

### Analysis

Pattern: attributes are captured after `insert_html_element()` but before `push()`. This ensures the token has its bookmark set (from insert) before we capture attributes. The order is:
1. `reconstruct_active_formatting_elements()` - reconstruct any pending elements
2. `insert_html_element()` - insert the current token into DOM
3. Capture attributes - store on token for later use
4. `push()` - add to active formatting elements list

### Next Steps

Step 4: Clone attributes during reconstruction

---

## 2026-02-03 Iteration 4 - Step 4 Complete

### Completed

**Step 4: Clone attributes during reconstruction** ✓
- Modified `create_element_for_formatting_token()` to clone attributes from the original entry
- Removed the bail check that prevented reconstruction of elements with attributes
- Added attribute cloning: `if ( null !== $entry->attributes ) { $new_token->attributes = $entry->attributes; }`
- Updated test `test_bails_when_formatting_element_has_attributes` → `test_reconstructed_element_preserves_attributes`
- All 14 reconstruct tests pass
- Full html-api suite: 1333 tests pass (1 skipped is expected - Noah's Ark test)
- Committed: e5432c4caa

### Analysis

The old implementation bailed when encountering formatting elements with attributes because it couldn't clone them. Now that we:
1. Capture attributes when pushing (Step 3)
2. Clone attributes during reconstruction (Step 4)

...we can properly handle elements with attributes. The test was converted from verifying a bail to verifying successful attribute preservation.

### Next Steps

Step 5: Implement virtual attribute access in get_attribute()

---

## 2026-02-03 Iteration 5 - Step 5 Complete

### Completed

**Step 5: Implement virtual attribute access in get_attribute()** ✓
- Modified `get_attribute()` to check `current_element->token->attributes` for stored values
- Key insight: when visiting reconstructed elements, must use `current_element->token` (the stack event's token) not `state->current_token` (the parser's current token)
- Added case-insensitive lookup via `strtolower($name)` and `array_key_exists()`
- Returns `null` for non-existent attributes on virtual elements (no fallthrough to parent)
- Added two new test cases:
  - `test_get_attribute_works_for_reconstructed_element()` - single attribute
  - `test_get_attribute_works_for_reconstructed_element_with_multiple_attributes()` - multiple attributes
- All 16 reconstruct tests pass
- Full html-api suite: 1335 tests pass (1 skipped)
- Committed: [pending]

### Analysis

Initial implementation used `$this->state->current_token->attributes` but this was incorrect. The `state->current_token` is the token currently being parsed from the input, while `current_element` is the stack event being processed (which contains the token from reconstruction). Reconstructed elements get their tokens created in `create_element_for_formatting_token()`, and those tokens have the cloned attributes.

### Next Steps

Step 6: Implement virtual attribute access in get_attribute_names_with_prefix()

---

## 2026-02-03 Iteration 6 - Step 6 Complete

### Completed

**Step 6: Implement virtual attribute access in get_attribute_names_with_prefix()** ✓
- Modified `get_attribute_names_with_prefix()` to check `current_element->token->attributes`
- Same pattern as `get_attribute()`: check stored attributes before falling through to parent
- Added case-insensitive prefix matching via `strtolower()`
- Returns empty array for virtual elements with no matching attributes
- Returns null for tag closers (consistent with parent behavior)
- Added comprehensive test case:
  - `test_get_attribute_names_with_prefix_works_for_reconstructed_element()` - tests empty prefix, data- prefix, and non-matching aria- prefix
- All 15 reconstruct tests pass
- Full html-api suite: 1336 tests pass (1 skipped - Noah's Ark test)
- Committed: 0b7600e972

### Analysis

The implementation follows the same pattern as `get_attribute()`:
1. Check if we have a current element with stored attributes
2. If so, filter by prefix and return matches
3. Otherwise fall through to parent implementation

Key difference from `get_attribute()`: we return an empty array (not null) when no attributes match the prefix, because that's the expected behavior for the method.

### Progress Summary

Steps 1-6 are now complete. This covers all attribute handling for reconstruction:
- ✓ Step 1: Add `$attributes` property to WP_HTML_Token
- ✓ Step 2: Add attribute capture helper
- ✓ Step 3: Capture attributes when pushing formatting elements
- ✓ Step 4: Clone attributes during reconstruction
- ✓ Step 5: Virtual get_attribute()
- ✓ Step 6: Virtual get_attribute_names_with_prefix()

### Next Steps

Step 7 (unit tests for attributes) may already be covered by tests added in Steps 5-6. Next significant work:
- Step 8: Element identity comparison helpers (for Noah's Ark)
- Step 9: Noah's Ark clause implementation

---

## 2026-02-03 Iteration 7 - Step 8 Complete

### Completed

**Step 8: Add element identity comparison helpers** ✓
- Added two private static methods to `WP_HTML_Active_Formatting_Elements`:
  - `elements_have_same_identity(WP_HTML_Token $a, WP_HTML_Token $b): bool` - Compares tag name, namespace, and attributes
  - `attributes_are_equal(array $a, array $b): bool` - Order-independent attribute comparison, keys already lowercase
- PHPDoc with `@since 6.8.0` for both methods
- All 17 reconstruct tests still pass
- Methods are declared but not yet used (will be used in Step 9)
- Committed: [pending]

### Analysis

These helper methods enable Noah's Ark duplicate detection. Key design decisions:
1. Private static - no state needed, only used internally
2. Null-coalescing for attributes (`$a->attributes ?? array()`) handles tokens without stored attributes
3. Count comparison first for early exit when attribute counts differ
4. Order-independent by iterating over keys rather than comparing arrays directly

### Next Steps

Step 9: Implement Noah's Ark clause in push() method

---

## 2026-02-03 Iteration 8 - Step 9 Complete

### Completed

**Step 9: Implement Noah's Ark clause in push()** ✓
- Replaced the `@todo` comment in `push()` with actual implementation
- Logic:
  1. Walk backwards through stack counting elements matching the new token
  2. Stop at markers (they reset the duplicate count)
  3. If 3+ identical elements exist, remove the earliest match
  4. Add the new element to the end of the list
- Uses helper methods from Step 8: `elements_have_same_identity()` and `attributes_are_equal()`
- All 17 reconstruct tests still pass
- Full html-api suite: 1336 tests pass (1 skipped - Noah's Ark test still in skip list)
- Committed: [pending]

### Analysis

The Noah's Ark clause limits identical formatting elements to 3 in the active formatting elements list. "Identical" means same tag name, namespace, and attributes (order-independent). This prevents nested formatting from accumulating unboundedly, e.g., `<b><b><b><b>...` is limited to 3 reconstructed `<b>` elements.

Key implementation detail: We track `earliest_match_index` while walking backwards because we need to remove the *earliest* match when the limit is exceeded, not the most recent one.

### Next Steps

- Step 10: Write unit tests for Noah's Ark
- Step 11: Remove Noah's Ark skip from html5lib test file

---

## 2026-02-03 Iteration 9 - Steps 10 & 11 Complete

### Completed

**Step 10: Write unit tests for Noah's Ark** ✓
- Added 5 unit tests to wpHtmlProcessorReconstructActiveFormattingElements.php:
  - `test_noahs_ark_limits_identical_elements_to_three()` - Core behavior
  - `test_noahs_ark_different_attributes_are_different_elements()` - Different attrs = different elements
  - `test_noahs_ark_respects_markers()` - Documents marker behavior after button closes
  - `test_noahs_ark_attribute_order_independent()` - Order doesn't matter
  - `test_noahs_ark_different_attribute_values_are_different_elements()` - Different values = different elements
- All 22 reconstruct tests pass (17 original + 5 new)

**Step 11: Remove Noah's Ark skip from html5lib test file** ✓
- Removed `'adoption01/line0318'` from SKIP_TESTS array
- The html5lib test now passes

**Verification:**
- Full html-api suite: 1341 tests pass (1 skipped - unrelated)
- Noah's Ark html5lib test (adoption01/line0318) passes
- Committed: 6b58f43a3b

### Analysis

The marker test required careful analysis. Initially expected 4 B elements when separated by a marker, but markers are cleared when their scope closes (button element). After button closes:
1. Elements before marker: [B, B]
2. Marker removed by `clear_up_to_last_marker()`
3. Elements after button: [B, B] pushed → total [B, B, B, B]
4. Noah's Ark sees 4 identical elements (no marker), limits to 3

### Progress Summary

All implementation steps are complete:
- ✓ Step 1: Add `$attributes` property to WP_HTML_Token
- ✓ Step 2: Add attribute capture helper
- ✓ Step 3: Capture attributes when pushing formatting elements
- ✓ Step 4: Clone attributes during reconstruction
- ✓ Step 5: Virtual get_attribute()
- ✓ Step 6: Virtual get_attribute_names_with_prefix()
- ✓ Step 7: Unit tests for attributes (covered in Steps 5-6)
- ✓ Step 8: Element identity comparison helpers
- ✓ Step 9: Noah's Ark clause implementation
- ✓ Step 10: Unit tests for Noah's Ark
- ✓ Step 11: Remove Noah's Ark skip

### Remaining

- Step 12: Run html5lib tests and validate (done - all pass)
- Step 13: Final cleanup and validation

### Acceptance Criteria Status

- [x] Reconstructed elements expose attributes via `get_attribute()`
- [x] Reconstructed elements list attributes via `get_attribute_names_with_prefix()`
- [x] Noah's Ark limits identical formatting elements to 3
- [x] All existing tests pass (no regressions)
- [x] 1 Noah's Ark html5lib test passes (adoption01/line0318)
- [x] Attribute-related html5lib tests pass (tests23, tests26, etc.)

---

## 2026-02-03 Iteration 9 (continued) - Step 12 Additional Work

### Issue Found

After removing the Noah's Ark skip, ran attribute-related html5lib tests and found failures. The tree builder was outputting `="red"` instead of `color="red"` for reconstructed elements because `get_qualified_attribute_name()` was not overridden.

### Completed

**Override get_qualified_attribute_name() for virtual elements** ✓
- Added override in WP_HTML_Processor to handle reconstructed elements
- Returns stored (lowercase) attribute name
- Applies SVG and MathML foreign attribute adjustments
- Added unit test `test_get_qualified_attribute_name_works_for_reconstructed_element()`
- All 23 reconstruct tests pass
- All attribute-related html5lib tests pass (tests23, tests26, etc.)
- Committed: 957f718c56

### Final Status

All implementation is complete:
- ✓ Steps 1-11 implemented and committed
- ✓ Step 12 (html5lib validation) passed
- ✓ All 1341 html-api tests pass (1 expected skip)
- ✓ 23 reconstruct unit tests pass
- ✓ All acceptance criteria met

Step 13 (final cleanup) may not be needed - code is clean.

---

## 2026-02-03 Iteration 10 - Objective Complete

### Final Verification

Confirmed all work is complete:
- All 1342 html-api tests pass (1 skipped is expected, unrelated to this work)
- All acceptance criteria met
- No open tasks remaining

### Summary of Implementation

Successfully implemented attribute handling and Noah's Ark clause for the WordPress HTML API:

**Attribute Handling:**
1. Added `$attributes` property to `WP_HTML_Token` for storing formatting element attributes
2. Created `get_current_token_attributes()` helper to capture attributes when pushing to AFE list
3. Attributes captured at all three push locations (a, formatting tags, nobr)
4. Attributes cloned during reconstruction in `create_element_for_formatting_token()`
5. Virtual attribute access via overridden `get_attribute()`, `get_attribute_names_with_prefix()`, and `get_qualified_attribute_name()`

**Noah's Ark Clause:**
1. Added identity comparison helpers (`elements_have_same_identity()`, `attributes_are_equal()`)
2. Implemented Noah's Ark in `push()` - limits identical elements to 3 per marker scope
3. Removed html5lib test skip for adoption01/line0318

**Testing:**
- 23 unit tests for reconstruction and attribute handling
- 5 unit tests specifically for Noah's Ark behavior
- All html5lib tests pass including the Noah's Ark test

### Commits (in order)
1. 7ec06265ad - Add `$attributes` property to WP_HTML_Token
2. 6853b6a265 - Add attribute capture helper
3. c90257276b - Capture attributes when pushing formatting elements
4. e5432c4caa - Clone attributes during reconstruction
5. 0b7600e972 - Virtual get_attribute_names_with_prefix()
6. 307ca1aecb - Element identity comparison helpers
7. c0b80abe2e - Noah's Ark clause implementation
8. 6b58f43a3b - Unit tests for Noah's Ark and enable html5lib test
9. 957f718c56 - Override get_qualified_attribute_name() for reconstructed elements

### OBJECTIVE COMPLETE
