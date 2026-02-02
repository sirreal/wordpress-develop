# Detailed Design: Reconstruct Active Formatting Elements

## Overview

This document describes the implementation of the "reconstruct the active formatting elements" algorithm in `WP_HTML_Processor`. This algorithm is called when the parser needs to reopen formatting elements that were opened in the current body, cell, or caption but haven't been explicitly closed.

Currently, the implementation bails when reconstruction requires advancing and rewinding through the list. This work will complete the algorithm to enable 29 additional html5lib tests to pass.

---

## Detailed Requirements

### Functional Requirements

1. **Full algorithm implementation**: Implement the complete reconstruct active formatting elements algorithm per the HTML5 specification
2. **Rewind phase**: Walk backwards through the active formatting elements list to find the starting point
3. **Advance phase**: Walk forwards through the list, creating elements for each entry
4. **Element creation**: Create new `WP_HTML_Token` instances for reconstructed elements
5. **List replacement**: Replace entries in the active formatting elements list with newly created elements
6. **Stack integration**: Push reconstructed elements onto the stack of open elements

### Non-Functional Requirements

1. **No regressions**: All 1087 currently passing tests must continue to pass
2. **Adoption agency compatibility**: Design should enable future adoption agency algorithm work
3. **Performance**: Avoid unnecessary allocations or iterations
4. **Code style**: Follow WordPress PHP coding standards

### Success Criteria

- **Goal**: All 29 tests currently skipped due to "Cannot reconstruct active formatting elements when advancing and rewinding is required" should pass
- **Acceptable**: Incremental progress with some tests passing, clear documentation of remaining gaps

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    WP_HTML_Processor                         │
├─────────────────────────────────────────────────────────────┤
│  reconstruct_active_formatting_elements()                    │
│    │                                                         │
│    ├── Check if list is empty → return false                │
│    ├── Check if last entry is marker/in stack → return false│
│    │                                                         │
│    ├── REWIND: Walk backwards to find start point           │
│    │     └── Uses: active_formatting_elements->walk_up()    │
│    │                                                         │
│    ├── ADVANCE + CREATE: Walk forward creating elements     │
│    │     ├── create_element_for_token()  [NEW]              │
│    │     ├── insert_html_element()                          │
│    │     └── active_formatting_elements->replace_at() [NEW] │
│    │                                                         │
│    └── Return true (elements were reconstructed)            │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│           WP_HTML_Active_Formatting_Elements                 │
├─────────────────────────────────────────────────────────────┤
│  Existing methods:                                           │
│    - push(), remove_node(), contains_node()                 │
│    - walk_up(), walk_down(), current_node()                 │
│    - clear_up_to_last_marker(), insert_marker()             │
│                                                              │
│  New methods needed:                                         │
│    - get_at(index): Get entry at specific index             │
│    - replace_at(index, token): Replace entry at index       │
│    - index_of(token): Find index of a token                 │
└─────────────────────────────────────────────────────────────┘
```

---

## Components and Interfaces

### 1. WP_HTML_Active_Formatting_Elements Extensions

New methods to support index-based access:

```php
/**
 * Gets the entry at a specific index in the list.
 *
 * @param int $index Zero-based index from the start of the list.
 * @return WP_HTML_Token|null The token at that index, or null if out of bounds.
 */
public function get_at( int $index ): ?WP_HTML_Token {
    return $this->stack[ $index ] ?? null;
}

/**
 * Replaces the entry at a specific index with a new token.
 *
 * @param int           $index Zero-based index from the start of the list.
 * @param WP_HTML_Token $token The new token to place at that index.
 * @return bool Whether the replacement was successful.
 */
public function replace_at( int $index, WP_HTML_Token $token ): bool {
    if ( $index < 0 || $index >= count( $this->stack ) ) {
        return false;
    }
    $this->stack[ $index ] = $token;
    return true;
}

/**
 * Finds the index of a token in the list.
 *
 * @param WP_HTML_Token $token The token to find.
 * @return int|null The index, or null if not found.
 */
public function index_of( WP_HTML_Token $token ): ?int {
    foreach ( $this->stack as $index => $item ) {
        if ( $token->bookmark_name === $item->bookmark_name ) {
            return $index;
        }
    }
    return null;
}
```

### 2. Reconstruct Algorithm Implementation

Updated `reconstruct_active_formatting_elements()` in `WP_HTML_Processor`:

```php
private function reconstruct_active_formatting_elements(): bool {
    $afe = $this->state->active_formatting_elements;

    // Step 1: If there are no entries, nothing to reconstruct.
    if ( 0 === $afe->count() ) {
        return false;
    }

    // Step 2: If last entry is marker or in stack, nothing to reconstruct.
    $last_entry = $afe->current_node();
    if (
        'marker' === $last_entry->node_name ||
        $this->state->stack_of_open_elements->contains_node( $last_entry )
    ) {
        return false;
    }

    // Step 3: Let entry be the last element.
    $entry_index = $afe->count() - 1;

    // Step 4-6: REWIND - find where to start.
    while ( $entry_index > 0 ) {
        --$entry_index;
        $entry = $afe->get_at( $entry_index );

        // Stop if we hit a marker or element in the stack.
        if (
            'marker' === $entry->node_name ||
            $this->state->stack_of_open_elements->contains_node( $entry )
        ) {
            // Step 7: Advance back one position.
            ++$entry_index;
            break;
        }
    }

    // Steps 7-10: ADVANCE and CREATE
    $last_index = $afe->count() - 1;
    while ( $entry_index <= $last_index ) {
        $entry = $afe->get_at( $entry_index );

        // Step 8: Create an element for the token.
        $new_element = $this->create_element_for_formatting_token( $entry );

        // Push onto stack of open elements.
        $this->insert_html_element( $new_element );

        // Step 9: Replace the entry in the list.
        $afe->replace_at( $entry_index, $new_element );

        // Step 10: If not at last entry, continue advancing.
        ++$entry_index;
    }

    return true;
}
```

### 3. Element Creation for Formatting Tokens

New helper method to create elements for previously-seen formatting tokens:

```php
/**
 * Creates a new element token for a formatting element entry.
 *
 * This creates a "virtual" element that represents a reconstructed
 * formatting element. It uses the same tag name as the original
 * but gets a new bookmark.
 *
 * @param WP_HTML_Token $entry The active formatting element entry.
 * @return WP_HTML_Token The newly created element token.
 */
private function create_element_for_formatting_token( WP_HTML_Token $entry ): WP_HTML_Token {
    // Create a virtual bookmark for this reconstructed element.
    $bookmark_name = $this->bookmark_token();

    // The bookmark points to the current token's position (where reconstruction happens).
    $here = $this->bookmarks[ $this->state->current_token->bookmark_name ];
    $this->bookmarks[ $bookmark_name ] = new WP_HTML_Span( $here->start, 0 );

    // Create new token with same tag name.
    $new_token = new WP_HTML_Token(
        $bookmark_name,
        $entry->node_name,
        false  // Reconstructed elements don't have self-closing flag
    );

    // Copy namespace if needed (formatting elements are always HTML).
    $new_token->namespace = 'html';

    return $new_token;
}
```

---

## Data Models

### WP_HTML_Token (existing, unchanged)

```php
class WP_HTML_Token {
    public $bookmark_name;       // string|null - Reference to position in HTML
    public $node_name;           // string - Tag name (uppercase) or special value
    public $has_self_closing_flag; // bool
    public $namespace;           // string - 'html', 'svg', or 'math'
    public $integration_node_type; // string|null
    public $on_destroy;          // callable|null
}
```

### Active Formatting Elements List (internal array)

The list stores `WP_HTML_Token` instances. Entries can be:
- **Formatting elements**: Tokens with uppercase `node_name` (e.g., "B", "I", "A")
- **Markers**: Tokens with `node_name === 'marker'`

---

## Error Handling

### Current Behavior (bail)

The current implementation throws `WP_HTML_Unsupported_Exception` via `bail()`. After this change:

1. **No more bail for basic reconstruction**: The algorithm will complete normally
2. **Potential remaining bail points**: If unforeseen edge cases are discovered, bail may still be used temporarily with specific error messages

### Edge Cases

1. **Empty list**: Return `false` immediately (already handled)
2. **Marker at end**: Return `false` (already handled)
3. **All entries in stack**: Return `false` (already handled)
4. **Single entry not in stack**: Create one element, return `true`
5. **Multiple entries**: Rewind to find start, advance creating elements

---

## Testing Strategy

### Unit Tests

Create `tests/phpunit/tests/html-api/wpHtmlProcessorReconstructActiveFormattingElements.php`:

```php
/**
 * @group html-api
 */
class Tests_HtmlApi_WpHtmlProcessorReconstructActiveFormattingElements extends WP_UnitTestCase {

    /**
     * Test that simple formatting elements are reconstructed.
     *
     * Input:  <p><b>Bold<p>More
     * Result: The <b> should be reconstructed in the second <p>
     */
    public function test_reconstructs_single_formatting_element() {
        $processor = WP_HTML_Processor::create_fragment( '<p><b>Bold<p>More' );

        // Navigate to second paragraph's text
        $this->assertTrue( $processor->next_tag( 'P' ) );
        $this->assertTrue( $processor->next_tag( 'B' ) );
        $this->assertTrue( $processor->next_tag( 'P' ) );

        // The breadcrumbs should show B was reconstructed
        $this->assertSame(
            array( 'HTML', 'BODY', 'P', 'B' ),
            $processor->get_breadcrumbs()
        );
    }

    /**
     * Test that nested formatting elements are reconstructed in order.
     */
    public function test_reconstructs_nested_formatting_elements() {
        $processor = WP_HTML_Processor::create_fragment( '<p><b><i>Nested<p>More' );

        $this->assertTrue( $processor->next_tag( 'P' ) );
        $this->assertTrue( $processor->next_tag( 'B' ) );
        $this->assertTrue( $processor->next_tag( 'I' ) );
        $this->assertTrue( $processor->next_tag( 'P' ) );

        // Both B and I should be reconstructed
        $this->assertSame(
            array( 'HTML', 'BODY', 'P', 'B', 'I' ),
            $processor->get_breadcrumbs()
        );
    }

    /**
     * Test that markers prevent reconstruction across boundaries.
     */
    public function test_marker_stops_reconstruction() {
        // TD inserts a marker
        $processor = WP_HTML_Processor::create_fragment(
            '<table><tr><td><b>Bold<p>More',
            '<body>'
        );

        // Navigate into the table cell
        // ... test that B is reconstructed within the cell
    }
}
```

### Integration Tests (html5lib)

Run the html5lib test suite to verify:

```bash
./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml
```

Monitor specifically for:
- Tests previously skipped with "Cannot reconstruct active formatting elements" message
- No regressions in currently passing tests

---

## Appendices

### A. Technology Choices

| Choice | Decision | Rationale |
|--------|----------|-----------|
| Virtual bookmarks | Use existing `bookmark_token()` pattern | Consistent with `insert_virtual_node()` |
| Index-based access | Add `get_at()`, `replace_at()` to list class | Clean API, avoids exposing internal array |
| No attribute cloning (initial) | Tag-name only reconstruction | Simplifies initial implementation; attributes can be added later |

### B. Research Findings Summary

- The HTML5 spec's reconstruct algorithm has REWIND and ADVANCE phases
- The current `WP_HTML_Active_Formatting_Elements` class has walking methods but no index-based access
- The `insert_virtual_node()` method shows the pattern for creating elements without source HTML
- 29 html5lib tests are blocked by this limitation

### C. Alternative Approaches Considered

1. **Cursor-based traversal**: Add a cursor to the active formatting elements class
   - Rejected: More complex than needed; index-based access is simpler

2. **Expose internal array**: Make `$stack` public or add `get_stack()` method
   - Rejected: Breaks encapsulation; index methods are cleaner

3. **Iterator with state**: Use PHP iterators with position tracking
   - Rejected: More complex; simple index math suffices

### D. Future Work (Out of Scope)

1. **Attribute cloning**: Store and clone attributes for Noah's Ark compliance
2. **Adoption agency algorithm**: Will use reconstruct but needs additional reparenting support
3. **Foster parenting**: Separate feature for table content handling
