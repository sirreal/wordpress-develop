# Implementation Plan: Attribute Handling and Noah's Ark Clause

## Checklist

- [ ] Step 1: Add `$attributes` property to WP_HTML_Token
- [ ] Step 2: Add attribute capture helper to WP_HTML_Processor
- [ ] Step 3: Capture attributes when pushing formatting elements
- [ ] Step 4: Clone attributes during reconstruction
- [ ] Step 5: Implement virtual attribute access in get_attribute()
- [ ] Step 6: Implement virtual attribute access in get_attribute_names_with_prefix()
- [ ] Step 7: Write unit tests for attribute handling
- [ ] Step 8: Add element identity comparison helpers
- [ ] Step 9: Implement Noah's Ark clause in push()
- [ ] Step 10: Write unit tests for Noah's Ark
- [ ] Step 11: Remove Noah's Ark skip from html5lib test file
- [ ] Step 12: Run html5lib tests and validate
- [ ] Step 13: Final cleanup and validation

---

## Step 1: Add `$attributes` property to WP_HTML_Token

**Objective:** Extend the token class to store attributes for active formatting elements.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-token.php`:

1. Add new public property after `$on_destroy`:

```php
/**
 * Attributes associated with this token.
 *
 * For formatting elements in the active formatting elements list,
 * this stores the attributes as they were when the element was created.
 * Used for reconstruction and Noah's Ark duplicate detection.
 *
 * Keys are lowercase attribute names, values are decoded strings
 * or `true` for boolean attributes.
 *
 * @since 6.8.0
 *
 * @var array<string, string|true>|null
 */
public $attributes = null;
```

**Test requirements:** No tests yet - this is infrastructure.

**Integration with previous work:** Builds on existing WP_HTML_Token class.

**Demo:** After this step:
```php
$token = new WP_HTML_Token( 'bookmark', 'B', false );
$token->attributes = array( 'class' => 'bold' );
assert( $token->attributes['class'] === 'bold' );
```

---

## Step 2: Add attribute capture helper to WP_HTML_Processor

**Objective:** Create a method to capture all attributes from the current token.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-processor.php`:

Add new private method (near other helper methods):

```php
/**
 * Captures all attributes from the current token as an array.
 *
 * Returns an associative array with lowercase attribute names as keys
 * and decoded attribute values as values. Boolean attributes have
 * the value `true`.
 *
 * @since 6.8.0
 *
 * @return array<string, string|true> Attribute name-value pairs.
 */
private function get_current_token_attributes(): array {
    $attributes = array();
    $names = $this->get_attribute_names_with_prefix( '' );

    if ( null === $names ) {
        return $attributes;
    }

    foreach ( $names as $name ) {
        $attributes[ $name ] = $this->get_attribute( $name );
    }

    return $attributes;
}
```

**Test requirements:** Will be tested indirectly through Step 7.

**Integration with previous work:** Uses existing `get_attribute_names_with_prefix()` and `get_attribute()`.

**Demo:** After this step, the method exists but isn't called yet.

---

## Step 3: Capture attributes when pushing formatting elements

**Objective:** Store attributes on tokens before pushing to active formatting elements list.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-processor.php`:

Find all three locations where formatting elements are pushed (search for `active_formatting_elements->push`):

1. Line ~2769 (for `<a>` tags)
2. Line ~2790 (for `b`, `big`, `code`, `em`, `font`, `i`, `s`, `small`, `strike`, `strong`, `tt`, `u`)
3. Line ~2806 (for `<nobr>`)

Update each location from:
```php
$this->state->active_formatting_elements->push( $this->state->current_token );
```

To:
```php
$this->state->current_token->attributes = $this->get_current_token_attributes();
$this->state->active_formatting_elements->push( $this->state->current_token );
```

**Test requirements:** Will be tested in Step 7.

**Integration with previous work:** Uses method from Step 2.

**Demo:** After this step:
```php
$processor = WP_HTML_Processor::create_fragment( '<b class="bold">text' );
$processor->next_tag( 'B' );
// Internally, the token now has attributes stored
```

---

## Step 4: Clone attributes during reconstruction

**Objective:** Copy stored attributes to newly created tokens during reconstruction.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-processor.php`:

Modify `create_element_for_formatting_token()`:

1. **Remove** the bail check for attributes (the `if ( $entry_bookmark->length > $min_length )` block)

2. **Add** attribute cloning before returning the new token:

```php
/*
 * Clone attributes from the original entry.
 * This ensures reconstructed elements have the same attributes
 * as the token for which they were created.
 */
if ( null !== $entry->attributes ) {
    $new_token->attributes = $entry->attributes;
}

return $new_token;
```

**Test requirements:** Will be tested in Step 7.

**Integration with previous work:** Modifies existing reconstruction method from Iteration 1.

**Demo:** After this step, reconstructed elements have attributes, but they're not yet accessible via `get_attribute()`.

---

## Step 5: Implement virtual attribute access in get_attribute()

**Objective:** Make reconstructed elements expose their attributes via the standard API.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-processor.php`:

The processor already overrides `get_attribute()`. Add virtual attribute check at the beginning:

```php
public function get_attribute( $name ) {
    /*
     * For reconstructed elements with virtual attributes,
     * return the stored attribute value.
     */
    if (
        isset( $this->state->current_token ) &&
        null !== $this->state->current_token->attributes
    ) {
        $comparable = strtolower( $name );
        if ( array_key_exists( $comparable, $this->state->current_token->attributes ) ) {
            return $this->state->current_token->attributes[ $comparable ];
        }
        // Virtual element has no other attributes beyond what's stored
        return null;
    }

    // Standard attribute lookup from source HTML
    return parent::get_attribute( $name );
}
```

**Note:** If the processor doesn't already override `get_attribute()`, you'll need to add this override.

**Test requirements:** Will be tested in Step 7.

**Integration with previous work:** Extends existing attribute access.

**Demo:** After this step:
```php
$processor = WP_HTML_Processor::create_fragment( '<p><b class="bold">text<p>more' );
// Navigate to reconstructed B in second paragraph
// ...
$processor->get_attribute( 'class' ); // Returns 'bold'
```

---

## Step 6: Implement virtual attribute access in get_attribute_names_with_prefix()

**Objective:** Make reconstructed elements list their attribute names via the standard API.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-processor.php`:

Override or modify `get_attribute_names_with_prefix()`:

```php
public function get_attribute_names_with_prefix( $prefix ): ?array {
    /*
     * For reconstructed elements with virtual attributes,
     * return matching attribute names from stored attributes.
     */
    if (
        isset( $this->state->current_token ) &&
        null !== $this->state->current_token->attributes
    ) {
        if ( $this->is_tag_closer() ) {
            return null;
        }

        $comparable = strtolower( $prefix );
        $matches = array();

        foreach ( array_keys( $this->state->current_token->attributes ) as $name ) {
            if ( str_starts_with( $name, $comparable ) ) {
                $matches[] = $name;
            }
        }

        return $matches;
    }

    return parent::get_attribute_names_with_prefix( $prefix );
}
```

**Test requirements:** Will be tested in Step 7.

**Integration with previous work:** Extends existing attribute name access.

**Demo:** After this step:
```php
$processor->get_attribute_names_with_prefix( '' ); // Returns ['class'] for reconstructed element
```

---

## Step 7: Write unit tests for attribute handling

**Objective:** Validate attribute capture, cloning, and access for reconstructed elements.

**Implementation guidance:**

Update `tests/phpunit/tests/html-api/wpHtmlProcessorReconstructActiveFormattingElements.php`:

Add tests:

```php
/**
 * Tests that reconstructed formatting elements preserve their attributes.
 *
 * @ticket [ticket_number]
 */
public function test_reconstructed_element_preserves_single_attribute() {
    $processor = WP_HTML_Processor::create_fragment( '<p><b class="bold">text<p>more' );

    // Navigate past first paragraph and its contents
    $this->assertTrue( $processor->next_tag( 'P' ) );
    $this->assertTrue( $processor->next_tag( 'B' ) );

    // Navigate to second paragraph (triggers reconstruction)
    $this->assertTrue( $processor->next_tag( 'P' ) );

    // The reconstructed B should have the class attribute
    $this->assertSame(
        array( 'HTML', 'BODY', 'P', 'B' ),
        $processor->get_breadcrumbs()
    );

    // Find the reconstructed B and check its attribute
    $this->assertTrue( $processor->next_tag( 'B' ) );
    $this->assertSame( 'bold', $processor->get_attribute( 'class' ) );
}

/**
 * Tests that reconstructed elements preserve multiple attributes.
 *
 * @ticket [ticket_number]
 */
public function test_reconstructed_element_preserves_multiple_attributes() {
    $processor = WP_HTML_Processor::create_fragment(
        '<p><font size="4" color="red">text<p>more'
    );

    $processor->next_tag( 'P' );
    $processor->next_tag( 'FONT' );
    $processor->next_tag( 'P' );
    $processor->next_tag( 'FONT' );

    $this->assertSame( '4', $processor->get_attribute( 'size' ) );
    $this->assertSame( 'red', $processor->get_attribute( 'color' ) );
}

/**
 * Tests that get_attribute_names_with_prefix works for reconstructed elements.
 *
 * @ticket [ticket_number]
 */
public function test_reconstructed_element_lists_attribute_names() {
    $processor = WP_HTML_Processor::create_fragment(
        '<p><b id="x" class="y">text<p>more'
    );

    $processor->next_tag( 'P' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'P' );
    $processor->next_tag( 'B' );

    $names = $processor->get_attribute_names_with_prefix( '' );
    $this->assertContains( 'id', $names );
    $this->assertContains( 'class', $names );
}

/**
 * Tests that reconstructed elements without attributes work correctly.
 *
 * @ticket [ticket_number]
 */
public function test_reconstructed_element_without_attributes() {
    $processor = WP_HTML_Processor::create_fragment( '<p><b>text<p>more' );

    $processor->next_tag( 'P' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'P' );
    $processor->next_tag( 'B' );

    $this->assertNull( $processor->get_attribute( 'class' ) );
    $this->assertSame( array(), $processor->get_attribute_names_with_prefix( '' ) );
}
```

**Test requirements:** Run with `WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter Reconstruct`

**Integration with previous work:** Extends existing reconstruct tests.

**Demo:** After this step:
```bash
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter Reconstruct
# All attribute-related tests pass
```

---

## Step 8: Add element identity comparison helpers

**Objective:** Create methods to compare elements for Noah's Ark duplicate detection.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-active-formatting-elements.php`:

Add two new private static methods:

```php
/**
 * Determines if two tokens represent the same formatting element.
 *
 * Two elements are considered identical if they have the same:
 * - Tag name
 * - Namespace
 * - Attributes (names, namespaces, and values)
 *
 * @since 6.8.0
 *
 * @param WP_HTML_Token $a First token.
 * @param WP_HTML_Token $b Second token.
 * @return bool Whether the tokens represent identical formatting elements.
 */
private static function elements_have_same_identity( WP_HTML_Token $a, WP_HTML_Token $b ): bool {
    // Tag name must match.
    if ( $a->node_name !== $b->node_name ) {
        return false;
    }

    // Namespace must match.
    if ( $a->namespace !== $b->namespace ) {
        return false;
    }

    // Attributes must match.
    return self::attributes_are_equal(
        $a->attributes ?? array(),
        $b->attributes ?? array()
    );
}

/**
 * Determines if two attribute arrays are equal.
 *
 * Comparison is case-insensitive for names (keys are already lowercase),
 * exact for values, and order-independent.
 *
 * @since 6.8.0
 *
 * @param array $a First attributes array.
 * @param array $b Second attributes array.
 * @return bool Whether the attributes are equal.
 */
private static function attributes_are_equal( array $a, array $b ): bool {
    // Different count means different attributes.
    if ( count( $a ) !== count( $b ) ) {
        return false;
    }

    // Empty arrays are equal.
    if ( 0 === count( $a ) ) {
        return true;
    }

    // Compare each attribute (keys already lowercase from capture).
    foreach ( $a as $name => $value ) {
        if ( ! array_key_exists( $name, $b ) ) {
            return false;
        }
        if ( $value !== $b[ $name ] ) {
            return false;
        }
    }

    return true;
}
```

**Test requirements:** Will be tested indirectly via Step 10.

**Integration with previous work:** New methods in existing class.

**Demo:** After this step, comparison helpers exist but aren't used yet.

---

## Step 9: Implement Noah's Ark clause in push()

**Objective:** Limit duplicate formatting elements to 3 when pushing to the list.

**Implementation guidance:**

Edit `src/wp-includes/html-api/class-wp-html-active-formatting-elements.php`:

Replace the `push()` method:

```php
/**
 * Pushes a node onto the stack of active formatting elements.
 *
 * @since 6.4.0
 *
 * @see https://html.spec.whatwg.org/#push-onto-the-list-of-active-formatting-elements
 *
 * @param WP_HTML_Token $token Push this node onto the stack.
 */
public function push( WP_HTML_Token $token ) {
    /*
     * Noah's Ark clause: Limit to 3 identical formatting elements.
     *
     * > If there are already three elements in the list of active formatting
     * > elements after the last marker, if any, or anywhere in the list if
     * > there are no markers, that have the same tag name, namespace, and
     * > attributes as element, then remove the earliest such element from
     * > the list of active formatting elements.
     *
     * @see https://html.spec.whatwg.org/#push-onto-the-list-of-active-formatting-elements
     */
    $dominated_count = 0;
    $earliest_match_index = null;

    // Walk backwards, counting matches until we hit a marker.
    for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
        $entry = $this->stack[ $i ];

        // Markers stop the search.
        if ( 'marker' === $entry->node_name ) {
            break;
        }

        // Check if this entry matches the token being pushed.
        if ( self::elements_have_same_identity( $token, $entry ) ) {
            ++$dominated_count;
            $earliest_match_index = $i;
        }
    }

    // If 3 identical elements exist, remove the earliest.
    if ( $dominated_count >= 3 && null !== $earliest_match_index ) {
        array_splice( $this->stack, $earliest_match_index, 1 );
    }

    // Add element to the list of active formatting elements.
    $this->stack[] = $token;
}
```

**Test requirements:** Will be tested in Step 10.

**Integration with previous work:** Uses helpers from Step 8, replaces existing push() with @todo.

**Demo:** After this step, Noah's Ark is active.

---

## Step 10: Write unit tests for Noah's Ark

**Objective:** Validate Noah's Ark duplicate limiting behavior.

**Implementation guidance:**

Add to or create `tests/phpunit/tests/html-api/wpHtmlProcessorReconstructActiveFormattingElements.php`:

```php
/**
 * Tests Noah's Ark clause limits identical elements to 3.
 *
 * @ticket [ticket_number]
 */
public function test_noahs_ark_limits_identical_elements_to_three() {
    // Four identical <b> tags, only 3 should be reconstructed
    $processor = WP_HTML_Processor::create_fragment( '<p><b><b><b><b><p>X' );

    // Navigate past first paragraph with 4 B elements
    $processor->next_tag( 'P' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'B' );

    // Navigate to second paragraph
    $processor->next_tag( 'P' );

    // Breadcrumbs should show only 3 B elements reconstructed
    $breadcrumbs = $processor->get_breadcrumbs();
    $b_count = count( array_filter( $breadcrumbs, fn( $tag ) => 'B' === $tag ) );

    $this->assertSame( 3, $b_count, 'Noah\'s Ark should limit to 3 identical formatting elements' );
}

/**
 * Tests that elements with different attributes are not considered identical.
 *
 * @ticket [ticket_number]
 */
public function test_noahs_ark_different_attributes_are_different_elements() {
    // Four <b> elements with different classes - all should be reconstructed
    $processor = WP_HTML_Processor::create_fragment(
        '<p><b class="a"><b class="b"><b class="c"><b class="d"><p>X'
    );

    $processor->next_tag( 'P' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'B' );
    $processor->next_tag( 'P' );

    // All 4 should be reconstructed since they have different attributes
    $breadcrumbs = $processor->get_breadcrumbs();
    $b_count = count( array_filter( $breadcrumbs, fn( $tag ) => 'B' === $tag ) );

    $this->assertSame( 4, $b_count, 'Elements with different attributes should all be reconstructed' );
}

/**
 * Tests that Noah's Ark respects markers.
 *
 * @ticket [ticket_number]
 */
public function test_noahs_ark_respects_markers() {
    // Markers (from table cells) reset the duplicate count
    // This test may need adjustment based on current table support
}

/**
 * Tests element identity comparison with various attribute combinations.
 *
 * @ticket [ticket_number]
 */
public function test_noahs_ark_attribute_comparison() {
    // Same tag, same attributes (same order) - should match
    // Same tag, same attributes (different order) - should match
    // Same tag, different attribute values - should not match
    // Same tag, different attribute count - should not match
}
```

**Test requirements:** Run with `WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter noahs_ark`

**Integration with previous work:** Tests Noah's Ark implementation from Step 9.

**Demo:** After this step:
```bash
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter noahs_ark
# All Noah's Ark tests pass
```

---

## Step 11: Remove Noah's Ark skip from html5lib test file

**Objective:** Enable the Noah's Ark test case in the html5lib test suite.

**Implementation guidance:**

Edit `tests/phpunit/tests/html-api/wpHtmlProcessorHtml5lib.php`:

Remove this line from the `SKIP_TESTS` array:

```php
'adoption01/line0318' => 'Unimplemented: Noah\'s Ark clause to limit duplicate formatting elements is not implemented.',
```

**Test requirements:** The test should now pass instead of being skipped.

**Integration with previous work:** Enables integration test.

**Demo:** After this step:
```bash
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter "adoption01/line0318"
# Test passes instead of being skipped
```

---

## Step 12: Run html5lib tests and validate

**Objective:** Verify all target tests pass and no regressions occur.

**Implementation guidance:**

Run the full test suite:

```bash
# Full html-api test suite
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api

# html5lib tests specifically
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api-html5lib-tests
```

**Expected results:**

| Metric | Before | After |
|--------|--------|-------|
| Passing tests | 1105 | 1114+ |
| Skipped tests | 402 | ~393 |

**Target tests that should now pass:**

1. tests23/line0001
2. tests23/line0041
3. tests23/line0069
4. tests23/line0101
5. tests26/line0001
6. tests26/line0263
7. adoption01/line0159
8. adoption01/line0318 (Noah's Ark)
9. tricky01/line0078

If any tests fail:
1. Identify the specific test case
2. Analyze expected vs actual output
3. Debug and fix the implementation
4. Re-run tests

**Test requirements:** All tests pass, no regressions.

**Integration with previous work:** Validates entire implementation.

**Demo:** After this step:
```
Tests: 1507, Assertions: 1114, Skipped: 393
(or similar improvement)
```

---

## Step 13: Final cleanup and validation

**Objective:** Ensure code quality and prepare for review.

**Implementation guidance:**

1. **Code review checklist:**
   - [ ] All new methods have proper PHPDoc comments with `@since 6.8.0`
   - [ ] Code follows WordPress PHP coding standards
   - [ ] No debug code or temporary comments remain
   - [ ] Remove any `@todo` comments that are now resolved

2. **Run coding standards check:**
   ```bash
   composer phpcs
   ```

3. **Final test runs:**
   ```bash
   # Full html-api test suite
   WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api

   # html5lib tests
   WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api-html5lib-tests
   ```

4. **Review changed files:**
   - `src/wp-includes/html-api/class-wp-html-token.php`
   - `src/wp-includes/html-api/class-wp-html-processor.php`
   - `src/wp-includes/html-api/class-wp-html-active-formatting-elements.php`
   - `tests/phpunit/tests/html-api/wpHtmlProcessorReconstructActiveFormattingElements.php`
   - `tests/phpunit/tests/html-api/wpHtmlProcessorHtml5lib.php`

**Test requirements:** All tests pass, no PHP warnings or notices.

**Integration with previous work:** Final validation of entire implementation.

**Demo:** After this step:
- Clean diff showing all changes
- Test results showing improvement
- Ready for code review

---

## Summary

| Step | Description | Key Files |
|------|-------------|-----------|
| 1 | Add attributes property to token | class-wp-html-token.php |
| 2 | Add attribute capture helper | class-wp-html-processor.php |
| 3 | Capture attributes on push | class-wp-html-processor.php |
| 4 | Clone attributes on reconstruct | class-wp-html-processor.php |
| 5 | Virtual get_attribute() | class-wp-html-processor.php |
| 6 | Virtual get_attribute_names_with_prefix() | class-wp-html-processor.php |
| 7 | Unit tests for attributes | wpHtmlProcessorReconstructActiveFormattingElements.php |
| 8 | Element comparison helpers | class-wp-html-active-formatting-elements.php |
| 9 | Noah's Ark in push() | class-wp-html-active-formatting-elements.php |
| 10 | Unit tests for Noah's Ark | wpHtmlProcessorReconstructActiveFormattingElements.php |
| 11 | Remove Noah's Ark skip | wpHtmlProcessorHtml5lib.php |
| 12 | html5lib validation | N/A |
| 13 | Final cleanup | All modified files |
