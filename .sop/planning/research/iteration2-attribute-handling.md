# Research: Attribute Handling and Noah's Ark Clause

## Overview

This research documents findings for implementing attribute handling in active formatting element reconstruction and the Noah's Ark clause.

---

## 1. Current Code Analysis

### WP_HTML_Token Structure

The `WP_HTML_Token` class currently stores:
- `bookmark_name` - reference to position in HTML source
- `node_name` - tag name (uppercase)
- `has_self_closing_flag` - boolean
- `namespace` - 'html', 'svg', or 'math'
- `integration_node_type` - for integration points
- `on_destroy` - cleanup callback

**Key finding:** No current storage for attributes on the token object.

### Active Formatting Elements Push

Formatting elements are pushed to the active formatting elements list in `WP_HTML_Processor` at three locations (lines 2769, 2790, 2806):
- `<a>` tags
- Formatting tags: `b`, `big`, `code`, `em`, `font`, `i`, `s`, `small`, `strike`, `strong`, `tt`, `u`
- `<nobr>` tags

At push time, `$this->state->current_token` is pushed, and the processor has access to all current attributes via `get_attribute()` and `get_attribute_names_with_prefix('')`.

### Current Reconstruction Limitation

The `create_element_for_formatting_token()` method (line 5984) currently checks if an element has attributes by comparing bookmark span length to minimum tag length. If attributes exist, it calls `bail()`:

```php
if ( $entry_bookmark->length > $min_length ) {
    $this->bail( 'Cannot reconstruct active formatting element with attributes.' );
}
```

### Attribute Access Pattern

In `WP_HTML_Tag_Processor::get_attribute()`:
1. Checks parser state (`STATE_MATCHED_TAG`)
2. Looks up attribute in `$this->attributes` array (populated during parsing)
3. Reads value from HTML source using `substr()`

**Key insight:** Virtual/reconstructed elements have no source HTML, so standard attribute access won't work.

---

## 2. HTML5 Specification

### Push onto the List of Active Formatting Elements

From https://html.spec.whatwg.org/multipage/parsing.html#push-onto-the-list-of-active-formatting-elements:

> "If there are already three elements in the list of active formatting elements after the last marker, if any, or anywhere in the list if there are no markers, that have the same tag name, namespace, and attributes as element, then remove the earliest such element from the list of active formatting elements."

### Attribute Comparison (Noah's Ark)

> "For these purposes, the attributes must be compared as they were when the elements were created by the parser; two elements have the same attributes if all their parsed attributes can be paired such that the two attributes in each pair have identical names, namespaces, and values (the order of the attributes does not matter)."

**Key points:**
- Threshold is 3 (not configurable)
- Compares: tag name + namespace + attributes
- Attribute comparison: names, namespaces, values must match
- Order does not matter
- Must use attributes "as they were when created"

### Reconstruct the Active Formatting Elements

From https://html.spec.whatwg.org/multipage/parsing.html#reconstruct-the-active-formatting-elements:

> "Create an element for the token for which the element entry was created"

This means reconstructed elements must have the same attributes as the original token.

---

## 3. Test Case Analysis

### Tests Requiring Attribute Handling (8 tests)

Currently failing with "Cannot reconstruct active formatting element with attributes":

| Test | HTML Pattern | Key Attributes |
|------|--------------|----------------|
| tests23/line0001 | `<font size=4><font color=red>...<p>X` | size, color |
| tests23/line0041 | `<font size=4>` repeated | size |
| tests23/line0069 | `<font size=4>` variations | size |
| tests23/line0101 | `<font size=4 id=a>` | size, id |
| tests26/line0001 | `<a href=...>` | href |
| tests26/line0263 | `<code x` (incomplete) | x |
| adoption01/line0159 | `<s id="A"><b id="B">` | id |
| tricky01/line0078 | `<font size="7">` | size |

### Test Requiring Noah's Ark (1 explicit skip)

`adoption01/line0318`: `<p><b><b><b><b><p>x`

Expected behavior:
- First `<p>`: 4 nested `<b>` elements
- Second `<p>`: Only 3 `<b>` elements reconstructed (Noah's Ark removed the oldest)

### Tests Requiring Both Features

Tests like `tests23/line0001` test BOTH:
1. Attribute handling (font has `size` and `color` attributes)
2. Noah's Ark (multiple identical formatting elements)

---

## 4. Implementation Options Analysis

### Option A: Store Attributes at Push Time

**Approach:** When pushing to active formatting elements, capture all current attributes as an array on the token.

```php
// In push() or before calling push()
$token->attributes = $this->get_all_attributes(); // New method
$this->state->active_formatting_elements->push( $token );
```

**Pros:**
- Clean separation - attributes captured once at push time
- Matches spec: "attributes as they were when created"
- Simple to implement

**Cons:**
- Adds memory overhead to WP_HTML_Token
- Need to modify WP_HTML_Token class to add `$attributes` property

### Option B: Re-read from Bookmark

**Approach:** When reconstructing, seek to the original bookmark and re-read attributes.

**Pros:**
- No extra storage needed
- Uses existing parsing infrastructure

**Cons:**
- Requires processor repositioning (complex)
- Virtual nodes (already reconstructed) have no source to read from
- Doesn't work for nodes that were themselves reconstructed

### Option C: Store Bookmark + Attributes Separately

**Approach:** Create a new class or data structure for active formatting entries that includes both token and attributes.

**Pros:**
- Keeps WP_HTML_Token unchanged
- Clear ownership of attribute data

**Cons:**
- More complex refactoring
- Changes interface of active formatting elements list

### Recommendation

**Option A (Store Attributes at Push Time)** is recommended because:
1. It directly matches the spec requirement for attributes "as they were when created"
2. It's the simplest to implement
3. It handles the case of reconstructed elements being pushed (they already have attributes stored)
4. Memory overhead is minimal (only formatting elements, not all tokens)

---

## 5. Virtual Attribute Access

For reconstructed elements to expose their attributes via `get_attribute()`:

### Current Flow
```
get_attribute('class')
  → Check parser_state
  → Look up in $this->attributes (from source HTML)
  → Return value
```

### Proposed Flow
```
get_attribute('class')
  → Check if current element is reconstructed (has virtual attributes)
  → If virtual: return from token->attributes
  → Else: standard flow (from source HTML)
```

### Implementation Approach

1. Add `$attributes` property to `WP_HTML_Token` (null by default)
2. When pushing formatting element, capture attributes
3. In `get_attribute()`, check `$this->state->current_token->attributes` first
4. In `create_element_for_formatting_token()`, copy attributes from entry to new token

---

## 6. Noah's Ark Implementation

### Where to Implement

The check should happen in `WP_HTML_Active_Formatting_Elements::push()`:

```php
public function push( WP_HTML_Token $token ) {
    // Noah's Ark: Count matching elements after last marker
    $match_count = 0;
    $earliest_match_index = null;

    // Walk backwards to find matches and markers
    for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
        $entry = $this->stack[ $i ];

        // Stop at marker
        if ( 'marker' === $entry->node_name ) {
            break;
        }

        // Check if same tag name, namespace, and attributes
        if ( $this->elements_match( $token, $entry ) ) {
            $match_count++;
            $earliest_match_index = $i;
        }
    }

    // If 3 already exist, remove the earliest
    if ( $match_count >= 3 && null !== $earliest_match_index ) {
        array_splice( $this->stack, $earliest_match_index, 1 );
    }

    $this->stack[] = $token;
}
```

### Attribute Comparison Method

```php
private function elements_match( WP_HTML_Token $a, WP_HTML_Token $b ): bool {
    // Tag name must match
    if ( $a->node_name !== $b->node_name ) {
        return false;
    }

    // Namespace must match
    if ( $a->namespace !== $b->namespace ) {
        return false;
    }

    // Attributes must match (order-independent)
    return $this->attributes_match( $a->attributes ?? [], $b->attributes ?? [] );
}

private function attributes_match( array $a, array $b ): bool {
    // Different count = different attributes
    if ( count( $a ) !== count( $b ) ) {
        return false;
    }

    // Normalize keys to lowercase for comparison
    $a_normalized = [];
    foreach ( $a as $name => $value ) {
        $a_normalized[ strtolower( $name ) ] = $value;
    }

    $b_normalized = [];
    foreach ( $b as $name => $value ) {
        $b_normalized[ strtolower( $name ) ] = $value;
    }

    // Check each attribute
    foreach ( $a_normalized as $name => $value ) {
        if ( ! array_key_exists( $name, $b_normalized ) ) {
            return false;
        }
        if ( $value !== $b_normalized[ $name ] ) {
            return false;
        }
    }

    return true;
}
```

---

## 7. Test Commands

```bash
# Fast html-api tests
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api

# html5lib tests only
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api-html5lib-tests

# Specific test file
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter tests23
```

---

## 8. Summary of Findings

### Target Tests
- **8 tests** blocked by attribute reconstruction
- **1 test** blocked by Noah's Ark clause
- Some tests require BOTH features

### Recommended Approach
1. **Attribute Storage:** Add `$attributes` property to `WP_HTML_Token`, populate at push time
2. **Attribute Access:** Check for virtual attributes in `get_attribute()` before standard lookup
3. **Noah's Ark:** Implement in `push()` method with element/attribute comparison helper

### Dependencies
- Attribute handling must be implemented before Noah's Ark (Noah's Ark needs attributes for comparison)
- This aligns with the chosen approach (attribute handling first, then Noah's Ark)
