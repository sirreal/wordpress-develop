# HTML5 Specification: Active Formatting Elements & Adoption Agency

## Sources

- [HTML Standard - WHATWG](https://html.spec.whatwg.org/)
- [Adoption Agency Algorithm Adjustment Commit](https://github.com/whatwg/html/commit/22ce3c31d8054c154042fd07150318a99ecc3e1b)
- [Issue #9559: Confusion about adoption agency algorithm](https://github.com/whatwg/html/issues/9559)
- [Issue #10525: Adoption agency algorithm ambiguity](https://github.com/whatwg/html/issues/10525)

---

## Reconstruct the Active Formatting Elements Algorithm

From the HTML5 spec (section 13.2.4.3):

```
When reconstruction is required, the user agent must perform these steps:

1. If no entries exist in the active formatting elements list, stop.

2. If the last entry is a marker or an element currently in the
   stack of open elements, stop.

3. Let entry be the most recently added element in the list.

4. REWIND: If no entries precede entry, jump to CREATE.
   Otherwise, move entry back one position.
   If this entry is neither a marker nor in the stack of open elements,
   repeat REWIND.

5. ADVANCE: Move entry forward one position in the list.

6. CREATE: "Create an element for the token for which entry was created"
   in the current node's context, then add it to:
   - the stack of open elements
   - the list of active formatting elements

7. Replace the entry for entry in the list with an entry for new element.

8. If entry is not the last entry, return to ADVANCE. Otherwise, stop.
```

**Key insight**: This algorithm requires the ability to:
- Walk backwards through the active formatting elements list (REWIND)
- Walk forwards through the list (ADVANCE)
- Create new elements for previously-seen tokens
- Replace entries in the list

---

## Adoption Agency Algorithm

The adoption agency algorithm handles misnested formatting elements like:
- `<b><i></b></i>`
- `<a><p></a></p>`
- `<b><p></b></p>`

### High-Level Structure

```
1. Let subject be the tag name of the end tag token

2. If current node has tag name = subject AND is not in the list of
   active formatting elements, then pop and return

3. OUTER LOOP (max 8 iterations):

   a. Let formatting element be the last element in the active formatting
      elements list (between end and last marker) with tag name = subject

   b. If no such element exists:
      → Return and act as "any other end tag"

   c. If formatting element is not in the stack of open elements:
      → Remove from active formatting elements and return

   d. If formatting element is not in scope:
      → Parse error, return

   e. Let furthest block be the topmost node in the stack BELOW
      formatting element that is in the "special" category

   f. If no furthest block:
      → Pop all nodes up to and including formatting element
      → Remove formatting element from active formatting elements
      → Return

   g. Let common ancestor be the element immediately above
      formatting element in the stack

   h. Let bookmark be the position of formatting element in the
      active formatting elements list

   i. INNER LOOP (node starts at furthest block, max 3 iterations):

      - Let node be the element immediately above node in the stack
      - If inner loop counter > 3 AND node is in active formatting elements:
        → Remove node from active formatting elements
      - If node is not in active formatting elements:
        → Remove node from stack of open elements
        → Continue to next iteration
      - If node is the formatting element:
        → Break inner loop

      - Create new element with same token as node
      - Replace entry for node in active formatting elements
      - Replace entry for node in stack of open elements
      - If last node = furthest block, move bookmark to after new element
      - Append last node to new element
      - Set last node = new element

   j. Insert last node at appropriate place (either in common ancestor
      or foster parent location if in table context)

   k. Create new element for formatting element's token

   l. Move all children of furthest block to new element

   m. Append new element to furthest block

   n. Remove formatting element from active formatting elements

   o. Insert new element at bookmark position in active formatting elements

   p. Remove formatting element from stack of open elements

   q. Insert new element below furthest block in stack of open elements
```

### Key Operations Required

1. **Walking the stack of open elements** in both directions
2. **Walking the active formatting elements** in both directions
3. **Creating new elements** for existing tokens
4. **Reparenting nodes** - moving nodes from one parent to another
5. **Tracking bookmarks** in the active formatting elements list
6. **Foster parenting** - special insertion for table contexts

---

## WordPress HTML Processor Current Limitations

Based on `bail()` calls in `class-wp-html-processor.php`:

### Active Formatting Elements (line 5903)
```php
$this->bail( 'Cannot reconstruct active formatting elements when advancing and rewinding is required.' );
```
**Cause**: The reconstruct algorithm requires walking backwards then forwards through the list.

### Adoption Agency - "Any Other End Tag" (line 6148)
```php
$this->bail( 'Cannot run adoption agency when "any other end tag" is required.' );
```
**Cause**: When no formatting element is found, needs to fall back to different handling.

### Adoption Agency - Common Ancestor (line 6200)
```php
$this->bail( 'Cannot extract common ancestor in adoption agency algorithm.' );
```
**Cause**: The algorithm found a furthest block but can't proceed with the reparenting.

### Adoption Agency - Looping (line 6203)
```php
$this->bail( 'Cannot run adoption agency when looping required.' );
```
**Cause**: The outer loop or inner loop needs to run multiple iterations.

### Foster Parenting (lines 3271, 3452)
```php
$this->bail( 'Foster parenting is not supported.' );
```
**Cause**: Content in tables that needs to be "fostered" outside the table structure.

---

## Test Coverage Analysis

From html5lib-tests, tests affected by these limitations:

| Limitation | Test Count |
|------------|------------|
| Foster parenting | 95 |
| Cannot extract common ancestor | 43 |
| Cannot reconstruct (advancing/rewinding) | 29 |
| Cannot run adoption agency ("any other end tag") | 7 |
| **Total related tests** | **174** |

Key test files:
- `adoption01.dat` - Basic adoption agency test cases
- `adoption02.dat` - More complex adoption scenarios
- `tests*.dat` - Various tests that trigger these paths

---

## Example Test Cases

### From adoption01.dat

**Input**: `<a><p></a></p>`
**Expected output**:
```
<html>
  <head>
  <body>
    <a>
    <p>
      <a>
```

**Explanation**: The `</a>` triggers adoption agency. The `<a>` is duplicated inside `<p>`.

### From adoption02.dat

**Input**: `<b>1<i>2<p>3</b>4`
**Expected output**:
```
<html>
  <head>
  <body>
    <b>
      "1"
      <i>
        "2"
    <i>
      <p>
        <b>
          "3"
        "4"
```

**Explanation**: `</b>` triggers adoption agency. `<i>` gets split across the boundary.
