# Project Summary: Attribute Handling and Noah's Ark Clause

## Iteration 2 - Building on Reconstruct Active Formatting Elements

## Artifacts Created

```
.sop/planning/
├── rough-idea.md                           # Updated with iteration 2 goals
├── idea-honing.md                          # Requirements Q&A (16 questions total)
├── research/
│   ├── html5-spec-algorithms.md            # From iteration 1
│   └── iteration2-attribute-handling.md    # NEW: Research for this iteration
├── design/
│   └── detailed-design.md                  # UPDATED: Full design with attributes + Noah's Ark
├── implementation/
│   └── plan.md                             # UPDATED: 13-step implementation plan
└── summary.md                              # This document
```

## Design Overview

**Goal:** Implement attribute handling for active formatting element reconstruction and the Noah's Ark clause to enable 9 additional html5lib tests to pass.

**Approach:**
1. Add `$attributes` property to `WP_HTML_Token` to store attributes
2. Capture attributes when pushing formatting elements to the list
3. Clone attributes during reconstruction
4. Override `get_attribute()` and `get_attribute_names_with_prefix()` for virtual attribute access
5. Implement Noah's Ark clause in `push()` method with element identity comparison

**Key Components:**

| Component | Changes |
|-----------|---------|
| WP_HTML_Token | New `$attributes` property |
| WP_HTML_Processor | Attribute capture, cloning, virtual access |
| WP_HTML_Active_Formatting_Elements | Noah's Ark logic, identity comparison |

## Implementation Plan Overview

| Step | Description | Outcome |
|------|-------------|---------|
| 1 | Add `$attributes` property to token | Storage infrastructure |
| 2 | Add attribute capture helper | Capture method ready |
| 3 | Capture attributes on push | Attributes stored |
| 4 | Clone attributes on reconstruct | Attributes preserved |
| 5 | Virtual get_attribute() | API access working |
| 6 | Virtual get_attribute_names_with_prefix() | Full API support |
| 7 | Unit tests for attributes | TDD validation |
| 8 | Element identity comparison helpers | Noah's Ark foundation |
| 9 | Noah's Ark in push() | Duplicate limiting active |
| 10 | Unit tests for Noah's Ark | TDD validation |
| 11 | Remove Noah's Ark skip | Enable integration test |
| 12 | html5lib validation | Full test suite passes |
| 13 | Final cleanup | Production ready |

## Success Criteria

| Criterion | Target |
|-----------|--------|
| Attribute handling tests | 8 previously-skipped tests pass |
| Noah's Ark test | 1 previously-skipped test passes |
| No regressions | 1105 currently passing tests still pass |
| API complete | `get_attribute()` works on reconstructed elements |

## Test Commands

```bash
# Fast html-api tests
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api

# html5lib tests only
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --group html-api-html5lib-tests

# Specific test filters
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter Reconstruct
WP_TESTS_SKIP_INSTALL=1 ./vendor/bin/phpunit --filter noahs_ark
```

## Target Tests

These tests should pass after implementation:

1. tests23/line0001 - `<font>` with size/color attributes
2. tests23/line0041 - Multiple `<font size=4>` tags
3. tests23/line0069 - `<font size=4>` variations
4. tests23/line0101 - `<font size=4 id=a>` with multiple attributes
5. tests26/line0001 - `<a href=...>` tag
6. tests26/line0263 - `<code x` (incomplete attribute)
7. adoption01/line0159 - `<s id="A"><b id="B">` adoption agency
8. adoption01/line0318 - Noah's Ark clause (`<b><b><b><b>`)
9. tricky01/line0078 - `<font size="7">` in tricky context

## Next Steps

1. Review the detailed design at `.sop/planning/design/detailed-design.md`
2. Follow the implementation checklist at `.sop/planning/implementation/plan.md`
3. Begin with Step 1: Add `$attributes` property to WP_HTML_Token

## Key Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Feature order | Attribute handling first | Noah's Ark depends on attribute comparison |
| Attribute storage | Property on token | Simple, matches spec |
| Attribute format | `array<string, string\|true>` | Matches existing API |
| Noah's Ark location | In `push()` method | Encapsulation |
| Attribute fidelity | Name/value only | Spec compliance sufficient |

## Areas for Future Refinement

1. **Attribute namespaces** - Foreign content may need namespace tracking
2. **Adoption agency** - This work enables future adoption agency implementation
3. **Performance** - Consider caching if attribute comparison becomes bottleneck
