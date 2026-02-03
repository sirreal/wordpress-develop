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

# Iteration 2: Attribute Handling and Noah's Ark Clause

---

## Q8: What is the relationship between these two features?

The two goals for this iteration are:
1. **Attribute handling** - Clone attributes when reconstructing formatting elements
2. **Noah's Ark clause** - Limit duplicate formatting elements to 3 (by tag name AND attributes)

These are related since Noah's Ark requires comparing attributes to determine if elements are "duplicates."

Should we:
1. **Implement together** - Both features in one pass, since Noah's Ark depends on having attribute data available
2. **Attribute handling first** - Get attribute cloning working, then add Noah's Ark as a follow-up
3. **Noah's Ark first** - Implement the duplicate limiting logic, then enhance with full attribute comparison

Which approach makes sense?

**A8:** Attribute handling first (option 2). Get attribute cloning working, then add Noah's Ark as a follow-up.

---

## Q9: How should attributes be stored for active formatting elements?

Currently, `WP_HTML_Token` stored in active formatting elements has:
- `bookmark_name` - reference to position in HTML source
- `node_name` - tag name
- `has_self_closing_flag`
- `namespace`

For attribute cloning, we need to store attribute data. Options:

1. **Store attribute snapshot at push time** - When adding to active formatting elements, capture current attributes as an array/object on the token
2. **Re-read from bookmark** - Use the existing bookmark to read attributes from the original source position when needed
3. **New data structure** - Create a separate attribute storage mechanism linked to the token

The spec says "create an element for the token for which the element entry was created" - suggesting we need the attributes as they were when the element was first seen.

Which storage approach fits best with the existing architecture?

**A9:** Unknown - all options should be considered and explored during research/implementation. The tradeoffs of each approach need to be evaluated against the existing architecture.

---

## Q10: What attribute data needs to be preserved?

When cloning attributes for reconstruction, we need to determine what exactly to store. Attributes have:
- Name (e.g., `class`, `href`, `style`)
- Value (e.g., `"bold"`, `"https://example.com"`)
- Potentially: case sensitivity, quote style, ordering

For spec compliance, should we:

1. **Name and value only** - Store a simple key-value mapping (normalized)
2. **Preserve ordering** - Maintain the order attributes appeared in the source
3. **Preserve exact representation** - Keep quote style, casing, whitespace as in source

What level of fidelity is needed?

**A10:** Name and value only (option 1). Store a simple key-value mapping with normalized attribute data. Exact representation (quote style, ordering, casing) is not required.

---

## Q11: How should reconstructed elements apply cloned attributes?

When a formatting element is reconstructed, the new element needs to have the cloned attributes. The current `create_element_for_formatting_token()` creates a virtual element with a zero-length bookmark.

For applying attributes to reconstructed elements:

1. **Virtual attributes** - Store attributes on the token object itself, make them accessible via a new API
2. **Synthetic HTML source** - Generate HTML-like source text that can be parsed for attributes
3. **Direct attribute injection** - Bypass normal attribute reading and inject directly when the element is created

How should the reconstructed element expose its attributes to callers (e.g., `$processor->get_attribute('class')`)?

**A11:** Virtual attributes (option 1). Store attributes on the token object itself and make them accessible via a new API. The processor's `get_attribute()` method would need to check for virtual attributes when the current element is a reconstructed one.

---

## Q12: What is the threshold for the Noah's Ark clause?

The HTML5 spec's "Noah's Ark" clause limits duplicate formatting elements. The spec says:

> "If there are already three elements in the list of active formatting elements after the last marker, if any, or anywhere in the list if there is no marker, that have the same tag name, namespace, and attributes as element, then remove the earliest such element from the list."

So the threshold is **3** - if adding a 4th identical element, remove the oldest one.

Is this threshold (3) fixed per the spec, or should it be configurable for any reason?

**A12:** No, it should not be configurable. The threshold of 3 is fixed per the HTML5 specification.

---

## Q13: How should attribute equality be determined for Noah's Ark?

The Noah's Ark clause requires determining if two elements have "the same attributes." This means comparing:
- Same set of attribute names
- Same values for each attribute

Questions about the comparison:

1. **Case sensitivity** - Should attribute names be compared case-insensitively (standard for HTML)?
2. **Value comparison** - Exact string match, or normalized (trimmed whitespace, etc.)?
3. **Order independence** - `class="a" id="b"` equals `id="b" class="a"`?

What are the requirements for attribute equality comparison?

**A13:** All three considerations apply:
- **Case insensitive** - Attribute names compared case-insensitively (HTML standard)
- **Exact value match** - Values compared as exact strings
- **Order independent** - `class="a" id="b"` equals `id="b" class="a"`
- **Note:** Duplicate attributes are ignored per HTML parsing rules; only the first occurrence of an attribute name is recognized.

---

## Q14: Where should the Noah's Ark check be performed?

The Noah's Ark clause triggers when pushing a formatting element onto the active formatting elements list. Currently this happens via:
- `WP_HTML_Active_Formatting_Elements::push()`

The check needs to:
1. Look at existing entries (after last marker, or entire list if no marker)
2. Find elements with same tag name, namespace, and attributes
3. If 3 already exist, remove the earliest one before adding the new one

Should the Noah's Ark logic be:

1. **Inside `push()` method** - The list class handles it internally
2. **In the processor before calling `push()`** - Logic lives in `WP_HTML_Processor`
3. **New dedicated method** - e.g., `push_with_noahs_ark_check()` or similar

Where should this logic live?

**A14:** To be determined during implementation. Evaluate the tradeoffs of each approach against the existing architecture and choose the most appropriate location.

---

## Q15: Are there specific html5lib tests that target these features?

The previous iteration identified 29 tests blocked by "Cannot reconstruct active formatting elements when advancing and rewinding is required."

For this iteration:
- Are there additional tests currently skipped that specifically require attribute handling?
- Are there tests that exercise the Noah's Ark clause?

Should we identify these tests upfront to use as success criteria, or discover them as we implement?

**A15:** Identify them upfront. Research should include scanning the html5lib test suite and current skip reasons to find tests that specifically require attribute handling and/or Noah's Ark clause support. These will serve as success criteria.

---

## Q16: Success criteria for this iteration?

Building on the previous iteration's criteria (no regressions, incremental progress acceptable), what defines success for this iteration?

1. **Attribute handling complete** - Reconstructed elements have correct attributes accessible via `get_attribute()`
2. **Noah's Ark implemented** - Duplicate limiting works per spec
3. **Test improvements** - Specific number of previously-skipped tests now pass
4. **All of the above**

What are the success criteria?

**A16:** All of the above (option 4):
1. Attribute handling complete - Reconstructed elements have correct attributes accessible via `get_attribute()`
2. Noah's Ark implemented - Duplicate limiting works per spec (threshold of 3)
3. Test improvements - Previously-skipped tests that require these features now pass
4. No regressions - All currently passing tests continue to pass

---

**Requirements clarification complete.** Proceeding to research phase.



