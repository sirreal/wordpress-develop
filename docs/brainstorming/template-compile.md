# Template Compile Mode

**Date:** 2026-02-04
**Status:** Brainstorming

---

## Two Modes of Template Usage

### 1. Flush Mode (current)
- One-shot rendering, like `::sprintf`
- Parse and render in a single pass

### 2. Compile Mode (new idea)
- A `compile()` method that:
  - Captures placeholder offsets
  - Returns a compiled/prepared template
  - Accepts an array of replacements at render time
  - Can be rendered multiple times efficiently from a single compilation

**Use case:** When you need to render the same template structure repeatedly with different data, avoid re-parsing the template each time.
