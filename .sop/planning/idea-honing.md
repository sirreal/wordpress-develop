# Requirements Clarification

This document captures the Q&A process for refining the active format reconstruction and reparenting support feature.

---

## Q1: Which limitation should be the primary focus?

The research identified four main areas where the processor currently bails:

1. **Reconstruct active formatting elements** (29 tests) - Requires advancing/rewinding through the list
2. **Adoption agency - common ancestor extraction** (43 tests) - The full reparenting algorithm
3. **Adoption agency - "any other end tag"** (7 tests) - Fallback handling
4. **Foster parenting** (95 tests) - Table content reparenting

Which of these should be the primary focus for this work?

**A1:** Reconstruct active formatting elements (option 1) - the 29 tests requiring advancing/rewinding through the list.

---

## Q2: What is the constraint on modifying existing data structures?

The current `WP_HTML_Active_Formatting_Elements` class has:
- `push()` - add to end
- `remove_node()` - remove specific node
- `walk_up()` - iterate from end to start
- `walk_down()` - iterate from start to end
- `current_node()` - get last element
- `clear_up_to_last_marker()` - clear to marker

The reconstruct algorithm needs to:
- Track a position/cursor within the list
- Move backwards (rewind) and forwards (advance)
- Replace entries at specific positions

Should we:
1. **Extend the existing class** with new methods for cursor-based traversal
2. **Refactor the class** to use a different internal structure if needed
3. **Minimize changes** - work within existing capabilities as much as possible

**A2:** Any approach is acceptable. The tradeoffs should be considered and a reasonable decision made based on the requirements. No artificial constraints on modifying existing structures.

---

## Q3: How should success be measured?

The html5lib test suite currently skips 29 tests with the message:
"Cannot reconstruct active formatting elements when advancing and rewinding is required."

For this work, should success be defined as:

1. **All 29 tests passing** - Full implementation of the reconstruct algorithm
2. **Incremental progress** - Some subset of tests passing, with clear documentation of remaining gaps
3. **No regressions** - The 1087 currently passing tests must continue to pass, plus progress on the 29

**A3:** The goal is all 29 tests passing (option 1), but incremental progress with no regressions (options 2 + 3) describes successful, acceptable progress. The 1087 currently passing tests must continue to pass.

---

## Q4: Are there constraints on the element creation mechanism?

The reconstruct algorithm requires creating new elements for tokens that were previously seen. Looking at the current code, `insert_html_element()` creates elements for the *current* token.

The spec says: "Create an element for the token for which the element entry was created."

This means we need to:
- Store enough information with each active formatting element entry to recreate it later
- Or have a mechanism to "replay" a token

The current `WP_HTML_Token` stored in the active formatting elements has:
- `node_name` (tag name)
- `bookmark_name` (reference to position in HTML)
- `has_self_closing_flag`

Does the implementation need to support reconstructing elements with their original attributes, or is tag-name-only reconstruction acceptable as a starting point?

**A4:** The specification and its "Noah's Ark clause" (limiting to 3 duplicate formatting elements based on tag name AND attributes) will require attributes to be cloned and preserved. This is essential for full compliance, but starting with tag-name-only reconstruction is an acceptable first step.

---

## Q5: How should reconstructed elements integrate with the breadcrumb system?

The HTML Processor uses a breadcrumb system to track the path from root to current node. When elements are reconstructed:

1. They don't exist in the original HTML source
2. They need bookmarks for the processor to function
3. They need to appear in the breadcrumb trail

Options:
1. **Virtual bookmarks** - Create synthetic bookmark names for reconstructed elements (e.g., "reconstructed-1", "reconstructed-2")
2. **Reuse original bookmarks** - Point reconstructed elements to the original element's position in the HTML
3. **New bookmark type** - Introduce a distinct concept for reconstructed/virtual elements

Which approach aligns with the existing architecture?

**A5:** Either virtual bookmarks (option 1) or a new bookmark type (option 3) would be appropriate. Option 2 (reusing original bookmarks) is not suitable. This requires investigation and exploration during implementation to determine which approach best fits the existing architecture.

---

## Q6: What is the relationship between this work and the adoption agency algorithm?

The reconstruct active formatting elements algorithm is called from many places, but notably it's also used within the adoption agency algorithm.

Given that:
- Adoption agency has 43 tests blocked by "common ancestor" issues
- Adoption agency has 7 tests blocked by "any other end tag" issues
- Some adoption agency tests may also require reconstruct

Should this work:
1. **Focus purely on reconstruct** - Get the 29 reconstruct-specific tests passing, leave adoption agency for later
2. **Enable adoption agency progress** - Design with awareness that adoption agency will build on this work
3. **Include simple adoption agency cases** - If reconstruct unlocks some adoption agency tests, include them in scope

**A6:** Enable adoption agency progress (option 2) - Design with awareness that adoption agency will build on this work. The reconstruct implementation should lay groundwork for future adoption agency work, even if adoption agency tests aren't in scope now.

---

## Q7: Testing approach during development?

The html5lib test suite provides comprehensive coverage but runs 1500+ tests. During development:

1. **Use html5lib tests only** - Run the full suite or filter by file (e.g., `adoption01.dat`)
2. **Write targeted unit tests** - Create specific PHP unit tests for the reconstruct algorithm in isolation
3. **Both** - Unit tests for algorithm correctness, html5lib for integration validation

Which approach do you prefer?

**A7:** Both approaches. Write targeted unit tests for algorithm correctness, use html5lib for integration validation.

Test commands:
- Fast html-api tests: `WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api`
- html5lib tests only: `./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml`

---

