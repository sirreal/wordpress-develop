# Implementation Plan: Reconstruct Active Formatting Elements

## Checklist

- [ ] Step 1: Add index-based access methods to WP_HTML_Active_Formatting_Elements
- [ ] Step 2: Write unit tests for the reconstruct algorithm
- [ ] Step 3: Implement the REWIND phase
- [ ] Step 4: Implement element creation for formatting tokens
- [ ] Step 5: Implement the ADVANCE phase and complete the algorithm
- [ ] Step 6: Run html5lib tests and fix edge cases
- [ ] Step 7: Final validation and cleanup

---

## Step 1: Add index-based access methods to WP_HTML_Active_Formatting_Elements

**Objective:** Extend the active formatting elements class with methods needed for index-based traversal and replacement.

**Implementation guidance:**

Add three new methods to `src/wp-includes/html-api/class-wp-html-active-formatting-elements.php`:

1. `get_at( int $index ): ?WP_HTML_Token` - Returns the entry at a specific index
2. `replace_at( int $index, WP_HTML_Token $token ): bool` - Replaces entry at index
3. `index_of( WP_HTML_Token $token ): ?int` - Finds index of a token by bookmark name

These methods provide clean access to the internal `$stack` array without exposing it directly.

**Test requirements:**

Create tests in a new file or add to existing active formatting elements tests:
- Test `get_at()` returns correct element at each position
- Test `get_at()` returns null for out-of-bounds index
- Test `replace_at()` successfully replaces an entry
- Test `replace_at()` returns false for invalid index
- Test `index_of()` finds correct index
- Test `index_of()` returns null for non-existent token

**Integration with previous work:** N/A - this is the first step.

**Demo:** After this step, you can demonstrate:
```php
$afe = new WP_HTML_Active_Formatting_Elements();
$token1 = new WP_HTML_Token( 'b1', 'B', false );
$token2 = new WP_HTML_Token( 'b2', 'I', false );
$afe->push( $token1 );
$afe->push( $token2 );

// Demonstrate index access
assert( $afe->get_at( 0 )->node_name === 'B' );
assert( $afe->get_at( 1 )->node_name === 'I' );
assert( $afe->index_of( $token2 ) === 1 );

// Demonstrate replacement
$token3 = new WP_HTML_Token( 'b3', 'STRONG', false );
$afe->replace_at( 0, $token3 );
assert( $afe->get_at( 0 )->node_name === 'STRONG' );
```

---

## Step 2: Write unit tests for the reconstruct algorithm

**Objective:** Create failing tests that define the expected behavior of the reconstruct algorithm before implementing it.

**Implementation guidance:**

Create `tests/phpunit/tests/html-api/wpHtmlProcessorReconstructActiveFormattingElements.php` with tests for:

1. **Single formatting element reconstruction**
   - Input: `<p><b>Bold<p>More`
   - Verify: Second `<p>` has `<b>` in breadcrumbs

2. **Multiple nested formatting elements**
   - Input: `<p><b><i>Text<p>More`
   - Verify: Second `<p>` has both `<b>` and `<i>` in breadcrumbs (in correct order)

3. **Marker stops reconstruction**
   - Input with table cell (which inserts marker)
   - Verify: Formatting before marker is not reconstructed after it

4. **Element already in stack (no reconstruction needed)**
   - Input: `<p><b>Text</b>More`
   - Verify: No reconstruction occurs, breadcrumbs are correct

5. **Empty list (no reconstruction needed)**
   - Input: `<p>Plain text`
   - Verify: No reconstruction occurs

**Test requirements:** Tests should initially fail (red phase of TDD), then pass after Steps 3-5.

**Integration with previous work:** Uses the methods from Step 1.

**Demo:** After this step, you can run:
```bash
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api --filter Reconstruct
```
Tests will fail, demonstrating the expected behavior is not yet implemented.

---

## Step 3: Implement the REWIND phase

**Objective:** Implement the backwards traversal that finds where reconstruction should start.

**Implementation guidance:**

In `src/wp-includes/html-api/class-wp-html-processor.php`, modify `reconstruct_active_formatting_elements()`:

1. Keep existing early-return checks (empty list, last entry is marker/in stack)
2. After those checks, instead of calling `bail()`:
   - Initialize `$entry_index` to `count() - 1`
   - Loop backwards while `$entry_index > 0`:
     - Decrement index
     - Get entry at that index
     - If entry is marker OR in stack of open elements, increment index and break
3. Store the final `$entry_index` as the starting point for the ADVANCE phase
4. For now, add a temporary `bail()` before the ADVANCE phase with message indicating rewind is complete

**Test requirements:**

Add a test that verifies rewind finds correct starting point:
- Mock or inspect internal state to verify correct index is found
- Test with various configurations of markers and stack elements

**Integration with previous work:** Uses `get_at()` from Step 1.

**Demo:** After this step:
- The algorithm no longer bails immediately
- It correctly identifies where to start reconstruction
- A new, more specific bail message appears: "REWIND complete, ADVANCE not yet implemented"

---

## Step 4: Implement element creation for formatting tokens

**Objective:** Create the helper method that produces new element tokens for reconstructed formatting elements.

**Implementation guidance:**

Add new private method `create_element_for_formatting_token( WP_HTML_Token $entry ): WP_HTML_Token`:

1. Generate a new bookmark name using `$this->bookmark_token()`
2. Create a bookmark span pointing to current token's position (zero-length span)
3. Create new `WP_HTML_Token` with:
   - The new bookmark name
   - Same `node_name` as the entry
   - `has_self_closing_flag = false`
4. Set namespace to 'html' (formatting elements are always HTML)
5. Return the new token

This follows the pattern used in `insert_virtual_node()`.

**Test requirements:**

Test the helper method:
- Verify created token has correct node_name
- Verify created token has a valid bookmark
- Verify created token has html namespace
- Verify multiple calls create distinct bookmarks

**Integration with previous work:** Will be called by the ADVANCE phase in Step 5.

**Demo:** After this step, you can demonstrate element creation:
```php
// Inside processor context
$entry = new WP_HTML_Token( 'orig', 'B', false );
$new_element = $this->create_element_for_formatting_token( $entry );
assert( $new_element->node_name === 'B' );
assert( $new_element->bookmark_name !== 'orig' );
assert( $new_element->namespace === 'html' );
```

---

## Step 5: Implement the ADVANCE phase and complete the algorithm

**Objective:** Complete the reconstruct algorithm by implementing the forward traversal that creates and inserts elements.

**Implementation guidance:**

Continue in `reconstruct_active_formatting_elements()` after the REWIND phase:

1. Remove the temporary bail from Step 3
2. Loop from `$entry_index` to `count() - 1`:
   - Get entry at current index using `get_at()`
   - Call `create_element_for_formatting_token()` to create new element
   - Call `insert_html_element()` to push onto stack of open elements
   - Call `replace_at()` to update the active formatting elements list
   - Increment index
3. Return `true` to indicate reconstruction occurred

**Test requirements:**

The tests from Step 2 should now pass:
- Single element reconstruction
- Multiple nested elements
- Marker boundary respected
- Correct breadcrumbs after reconstruction

Run full test suite to check for regressions:
```bash
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api
```

**Integration with previous work:**
- Uses `get_at()`, `replace_at()` from Step 1
- Uses REWIND logic from Step 3
- Uses `create_element_for_formatting_token()` from Step 4

**Demo:** After this step:
```php
$processor = WP_HTML_Processor::create_fragment( '<p><b>Bold<p>More' );
$processor->next_tag( 'P' );
$processor->next_tag( 'B' );
$processor->next_tag( 'P' );
// Breadcrumbs now include reconstructed B
assert( $processor->get_breadcrumbs() === array( 'HTML', 'BODY', 'P', 'B' ) );
```

---

## Step 6: Run html5lib tests and fix edge cases

**Objective:** Validate implementation against the html5lib test suite and fix any discovered issues.

**Implementation guidance:**

1. Run the html5lib test suite:
   ```bash
   ./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml
   ```

2. Compare results to baseline:
   - Previously: 1087 passing, 421 skipped
   - Target: 29 fewer skipped tests (those with "Cannot reconstruct" message)

3. For any remaining failures:
   - Identify the specific test case
   - Analyze expected vs actual output
   - Determine if it's a reconstruction issue or unrelated
   - Fix or document as out of scope

4. Common edge cases to watch for:
   - Reconstruction at document boundaries
   - Interaction with specific insertion modes
   - Multiple consecutive reconstructions

**Test requirements:**

- All previously passing tests still pass (no regressions)
- At least some of the 29 reconstruction-related tests now pass
- Any remaining skips have clear, documented reasons

**Integration with previous work:** Validates all previous steps working together.

**Demo:** After this step, show test results:
```
Before: Tests: 1508, Assertions: 1087, Skipped: 421
After:  Tests: 1508, Assertions: 1116, Skipped: 392  (example improvement)
```

---

## Step 7: Final validation and cleanup

**Objective:** Ensure code quality, documentation, and prepare for review.

**Implementation guidance:**

1. **Code review checklist:**
   - All new methods have proper PHPDoc comments
   - Code follows WordPress PHP coding standards
   - No debug code or temporary comments remain

2. **Documentation:**
   - Update any relevant inline documentation
   - Ensure `@since` tags are correct for new methods

3. **Final test runs:**
   ```bash
   # Full html-api test suite
   WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api

   # html5lib tests specifically
   ./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml
   ```

4. **Commit preparation:**
   - Review all changed files
   - Ensure changes are minimal and focused
   - Prepare clear commit message

**Test requirements:**

- All tests pass
- No PHP warnings or notices
- Code coverage maintained or improved

**Integration with previous work:** Final validation of entire implementation.

**Demo:** After this step:
- Clean diff showing all changes
- Test results showing improvement
- Ready for code review

---

## Summary

| Step | Description | Key Files | Tests |
|------|-------------|-----------|-------|
| 1 | Index-based access methods | class-wp-html-active-formatting-elements.php | Unit tests for new methods |
| 2 | Write reconstruct tests | wpHtmlProcessorReconstructActiveFormattingElements.php | Failing tests (TDD red) |
| 3 | REWIND phase | class-wp-html-processor.php | Partial algorithm working |
| 4 | Element creation helper | class-wp-html-processor.php | Helper method tests |
| 5 | ADVANCE phase | class-wp-html-processor.php | All unit tests pass (TDD green) |
| 6 | html5lib validation | N/A | Integration test improvements |
| 7 | Cleanup | All modified files | Final validation |
