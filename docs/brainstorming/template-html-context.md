# Template HTML Context

**Date:** 2026-02-04
**Status:** Brainstorming

---

## HTML Context Constraints

### Problem
The HTML processor cannot normalize certain templates like `<td>` because `<td>` cannot exist outside of a `<table>` element. Context matters for valid HTML.

### Potential Solutions
- Use HTML processor instead of TAG processor
- Add private methods for rendering nested templates
- Processor creates a **fragment parser** at the template replacement location
  - Fragment parser handles the child template with proper parent context
