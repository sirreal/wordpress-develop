# Foster Parenting in the HTML Processor

Status: implemented on the `html-api/foster-parenting` branch.

This document specifies how `WP_HTML_Processor` presents content which HTML's
tree construction relocates via *foster parenting*, in a streaming, single-pass
parser. It is the contract for the implementation; behavior which contradicts
this document is a defect in one of the two.

## 1. Background

When content appears inside a table context where it isn't allowed — for
example, `<table>lost<td>found` — an HTML parser inserts that content into the
document at a location *immediately before the table* (or, in one case, inside
the contents of a TEMPLATE element). The HTML Standard calls this [foster
parenting](https://html.spec.whatwg.org/#foster-parenting).

Foster parenting is a problem for a streaming parser because the relocated
content appears in the input *after* the `<table>` tag, yet belongs in the
document *before* the table element. A parser which presents nodes as it finds
them would present them out of document order; a parser which guarantees
document order cannot present the table until it knows whether anything else
will be fostered out of it, which is only knowable at the table's end tag.

The HTML Processor promises its callers a stream of nodes in **document
order**: the sequence of visited tokens forms a pre-order traversal of the
document. Callers rely on this to reason about structure from the order and
depth of what they visit (for example, "my element's subtree has ended when
the depth returns to where it started"). This promise is the design constraint
of this specification: **no mode of operation ever presents a node out of
document order unless the caller has explicitly opted into that**.

## 2. Terminology

- **Document order**: the order of a pre-order (depth-first, children after
  parent, siblings left-to-right) traversal of the final document.
- **Source order**: the order in which tokens appear in the input HTML.
- **Stack event**: a push or pop of the stack of open elements. The processor
  presents its document through these events: element openers and self-closing
  nodes are pushes; element closers are pops. One visited token corresponds to
  one presented event.
- **Present**: to surface a stack event to the caller as the matched token of
  `next_token()`.
- **Fostered node**: a node inserted via foster parenting. Its token carries a
  marker readable through `is_foster_parented()`. The descendants of a
  fostered element are *not* themselves marked: they are inserted normally,
  inside it.
- **Foster anchor**: the element which determines a fostered node's document
  location: the TABLE element it is inserted immediately before, or the
  TEMPLATE element whose contents receive it.
- **Fostered run**: a maximal contiguous sequence of stack events beginning
  with a fostered node's push and ending when every element it opened has
  popped. Because table-part tags clear the stack back to a table context
  before inserting, no non-fostered table content ever interleaves with a
  fostered run.
- **Table window**: the buffering scope described in §4, covering one
  outermost TABLE element from its push until its pop.

## 3. Modes

The processor operates in one of two modes, fixed before scanning begins.

### 3.1 Document order (default)

Every node is presented in document order. Content requiring foster parenting
is supported by deferring the presentation of table contents (§4) within a
bounded buffer. When the bound is exceeded, the buffer is flushed in order and
the parse continues; if foster parenting is required *after* that point, the
processor aborts (`get_last_error()` returns `ERROR_UNSUPPORTED`) rather than
present anything out of order.

Consequences of the bound:

- Well-formed tables of any size always parse. The bound limits *deferral*,
  not table size: an oversized table is flushed and streamed, and fails only
  if fostering is subsequently required.
- Every document which parsed before this specification still parses, with an
  identical presented stream. Every document with fostering whose relocations
  are discovered within the bound *additionally* parses, in exact document
  order. No document parses worse than before.

### 3.2 Source order (opt-in)

Enabled by calling `enable_source_order_foster_parenting()` while the
processor is in its initial ready state. Every node, including fostered
content, is presented at its source position with O(1) memory overhead and no
size bound. A fostered node is therefore presented *after* the TABLE element
it precedes in the document: the presented stream is not a pre-order
traversal. Ancestry remains exact: the breadcrumbs of every node, fostered or
not, report its document ancestors.

This mode exists for callers whose logic is order-independent (per-node
reads and in-place mutations keyed on tag names, attributes, or breadcrumbs)
and who need no table-size bound: serializers of arbitrary input, test
harnesses, fuzzers.

## 4. Document-order mode: the table window

### 4.1 Window lifecycle

- A window **opens** when a TABLE element in the HTML namespace is pushed onto
  the stack of open elements and no window is currently open. Nested tables do
  not open windows of their own; their events belong to the enclosing window.
- While a window is open, stack events are **routed** (§4.2) instead of
  presented immediately.
- A window **closes** when the pop event for its TABLE element arrives — from
  its end tag, from implicit closure (e.g. a second `<table>` start tag, end
  of input), or from adoption-agency stack reconciliation. Closing **flushes**
  the window: every deferred event, followed by the TABLE's pop, is presented
  in its deferred order. A TABLE pushed again after its pop (as adoption
  reconciliation may do) opens a new window.
- A window also flushes, without closing, on **overflow** (§4.3).

### 4.2 Routing

While a window is open, every stack event is inserted into the window's
buffer at its **document position**; nothing presents until the window
flushes. The buffer is maintained as a document-order sequence by these
insertion rules:

1. **Fostered node anchored before a TABLE**: its push inserts immediately
   before that TABLE's push event in the buffer, after any events previously
   inserted at the same anchor. When the anchor is the window's own TABLE,
   that position is the head of the buffer: such content is presented first
   at flush time, before anything of the table, matching its document
   position (everything preceding the window was presented before the window
   opened).
2. **Fostered node anchored inside a TEMPLATE's contents**: its push (and its
   run, rule 3) is held aside and inserted immediately before the TEMPLATE's
   pop event when that pop arrives. (Template contents receive fostered
   content "after its last child", which in a pre-order stream is the
   position just before the template closes. The template's pop always
   arrives before the window flushes, because the template lies within the
   window's subtree and stack unwinding is last-in-first-out.)
3. **Events inside an open fostered run** insert consecutively at their run's
   insertion point, immediately after the run's previously inserted event;
   the run ends when the fostered element which began it pops. Runs nest: a
   fostered node found inside an open fostered run opens a nested run at its
   own anchor (a TABLE opened inside a run is a valid anchor for further
   fostering, per rule 1).
4. **All other events** append to the end of the buffer.

Because insertion position always equals document position, flushing the
buffer front-to-back presents the window's contents in document order by
construction.

### 4.3 Overflow

The buffer is bounded by `WP_HTML_Processor::MAX_BUFFERED_TABLE_EVENTS`
deferred stack events per window. The bound is denominated in events — not
bytes — so that a parse replayed by `seek()` after in-place mutations, which
change byte lengths but never event counts, overflows at exactly the same
event.

When an insertion would exceed the bound, the outcome depends on where the
event belongs:

- **An append at the end of the buffer** (rule 4 — ordinary table content):
  the buffer is flushed — all deferred events are presented, in order,
  followed by the appended event — and the window is marked **overflowed**.
  Subsequent events present immediately, as if no window existed. This path
  carries no fostering: flushing plain table content early never reorders
  anything.
- **An insertion anywhere else** (rules 1–3 — fostered content): the processor
  aborts with `ERROR_UNSUPPORTED`. The event's document position precedes
  already-buffered events, so no flush can make room for it without
  presenting out of order. Likewise, if a node would be inserted via foster
  parenting while the window is already overflowed, the processor aborts: its
  anchor was presented when the buffer flushed. In both cases the error
  message names the cause (the table's deferral exceeded the buffer before
  its mis-nested content was resolved) and the remedy
  (`enable_source_order_foster_parenting()`).
- The overflowed state clears when the window closes; tables appearing later
  in the document open fresh windows.

The bound trades nothing for well-formed content: it only limits *which
fostering cases are rescued*. Mis-nested content discovered within the first
`MAX_BUFFERED_TABLE_EVENTS` events of a table — in practice, mis-nesting is
overwhelmingly discovered near the table's start — is presented in document
order; discovery later than that aborts, exactly as all fostering did before
this specification.

### 4.4 Presentation of deferred events

Deferred events reference real tokens whose source spans lie behind the
lexical cursor by the time they are presented. Presenting such an event
repositions the underlying lexer to the token's span, so that every accessor
and mutator (`get_attribute()`, `set_attribute()`, `get_modifiable_text()`,
`set_modifiable_text()`, bookmarks) behaves identically to an event presented
at parse time. A pop event presented for a real closer repositions to the
closing tag's own span. This re-lexes each deferred token once; the cost is
bounded by the buffer bound.

### 4.5 Observable equivalences

For any document containing no foster-parented content, the presented stream
in document-order mode is **identical** to the stream before this
specification existed — windows defer only the wall-clock moment at which
work happens inside `next_token()`, which is not observable through the API.

For any input, the presented stream in document-order mode is a pre-order
traversal of the document the processor reports. `get_breadcrumbs()` and
`get_current_depth()` require no special bookkeeping for fostered content in
this mode: because presentation itself is reordered, the breadcrumbs implied
by pushes and pops are already exact.

## 5. Common semantics (both modes)

### 5.1 `is_foster_parented()`

The marker is set on the same nodes in both modes; its practical reading
differs:

- Document order: "this node's source syntax lies inside table markup which
  follows it in the presented stream." A marker for serializers and tooling
  that reason about raw spans; order-based traversal needs no awareness of it.
- Source order: additionally, "this node is presented out of document order —
  it precedes, in the document, the TABLE element already presented."

### 5.2 Comments and whitespace in tables

Comment tokens in table contexts are never fostered; they are presented inside
the table (deferred with it in document-order mode). Whitespace-only character
tokens directly in a table context stay inside the table unless the same text
run continues with non-whitespace, in which case the entire run is fostered,
following the pending-table-character-tokens rules.

### 5.3 Bookmarks and `seek()`

In document-order mode, presentation order and byte order of tokens can
disagree, so `seek()` decides between walking forward and replaying from the
start by comparing **visitation order**, not byte offsets: each presented
event carries a monotonically increasing visitation index; `set_bookmark()`
records the index of the current event alongside its span. Replay is
deterministic — windows re-run and overflow at identical events (§4.3) — so a
bookmark's visitation index identifies the same node on every replay.

Bookmarks may be set on any presented token, including eagerly presented
fostered nodes and deferred-then-flushed table content.

### 5.4 Serialization and normalization

`serialize()` emits tokens in presentation order. In document-order mode the
output is normative for rescued fostering: fostered content is emitted
*physically before* the table, and re-parsing the output requires no foster
parenting at all. `normalize()` therefore now normalizes documents whose
fostering is rescued by the window, and continues to return `null` (with the
usual warning) for documents which abort.

In source-order mode, `serialize()` emits fostered content where its syntax
was found, inside the table markup; re-parsing the output relocates it to the
same document position. Adjacent text nodes with different document positions
may join when ignored syntax between them is dropped, moving whitespace
relative to the table; this is a documented limitation of source-order
serialization.

### 5.5 Chunked and truncated input

Windows persist across `paused_at_incomplete_token()`: deferral resumes when
input resumes. When a document ends while a window is open (unclosed table),
the end-of-document stack unwinding pops the window's TABLE, which flushes the
window as in §4.1.

### 5.6 Fragments without a table on the stack

Fostering with no TABLE element on the stack of open elements (possible only
in fragment parsing contexts not currently creatable) aborts, in both modes,
as an unsupported case.

### 5.7 Unchanged divergences

This specification does not change the processor's documented single-pass
divergences: the adoption agency algorithm cannot relocate already-visited
nodes, and elements removed from the stack of open elements while their
descendants remain open (a closed FORM, an A removed while not in table
scope) cannot be held open. Those divergences concern nodes visited *before*
a relocation is discovered; foster parenting concerns nodes not yet visited,
which is why exact placement is achievable here.

## 6. Public API

```php
class WP_HTML_Processor {
	/**
	 * Bound on deferred stack events per table window in document-order
	 * mode. See §4.3.
	 */
	const MAX_BUFFERED_TABLE_EVENTS = 1000;

	/**
	 * Opts into source-order presentation (§3.2). Callable only before
	 * scanning begins; returns whether the mode was enabled.
	 */
	public function enable_source_order_foster_parenting(): bool;

	/**
	 * Indicates whether the currently-matched node was inserted via
	 * foster parenting (§5.1).
	 */
	public function is_foster_parented(): bool;
}
```

`enable_foster_parenting()` does not exist; it was the name of the source-order
opt-in before document-order support existed, and its meaning — "support
foster parenting at all" — no longer describes either mode. This branch is
unreleased, so the rename carries no compatibility burden.

## 7. Bound value and rationale

`MAX_BUFFERED_TABLE_EVENTS` is 1000. Each element contributes two events and
each text or comment node two more, so the bound covers tables of roughly two
to three hundred cells — beyond typical authored content, and irrelevant to
larger *well-formed* tables, which flush and stream (§4.3). Deferral holds at
most ~500 tokens with one internal bookmark each, well within the processor's
bookmark budget, and transient memory on the order of hundreds of kilobytes.
The value is a class constant: discoverable, and adjustable in a subclass or
a future release without altering these semantics.

## 8. Canonical examples

Input: `a<table>b<td>c</table>d`

Document-order presentation:

    #text "a" · #text "b" (fostered) · TABLE · TBODY · TR · TD · #text "c"
    · /TD · /TR · /TBODY · /TABLE · #text "d"

Source-order presentation (after opt-in):

    #text "a" · TABLE · #text "b" (fostered, breadcrumbs HTML>BODY>#text)
    · TBODY · TR · TD · #text "c" · /TD · /TR · /TBODY · /TABLE · #text "d"

Input: `<table><td><table>x` — fostering anchored to the *nested* table lands
inside the cell, before the inner table, in both modes' documents; in
document-order mode it is presented there as well:

    TABLE · TBODY · TR · TD · #text "x" (fostered) · TABLE · /TABLE · /TD …

Input: `<table><template><tbody>x<tr>` — fostering anchored inside template
contents presents after the tbody subtree, before the template closes:

    TABLE · TEMPLATE · TBODY · TR · /TR · /TBODY · #text "x" (fostered)
    · /TEMPLATE · /TABLE
