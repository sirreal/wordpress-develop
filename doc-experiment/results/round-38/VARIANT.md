# Round 38 Scratch Variant

Variant name: `html-processor-method-local-text-policy-clarification`

Control round: `round-37`

Edited rendered file: `/tmp/html-api-docs-eval/round-38/html-processor.md`

Source docblocks were not edited. This is a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
3f695d2cb2d43f14de27b3824edcbe600bb4d4f14c8650424840a0b4d9fe0b5b
```

Changed the method-local `WP_HTML_Processor::next_token()` special-elements
paragraph from an "important exception" framing to an explicit caller-policy
framing: special elements do not produce ordinary `#text` child tokens, and
their opener-carried text should be included only when the caller explicitly
asks for special-element contents.

Added a method-local warning to `WP_HTML_Processor::get_modifiable_text()`:
the method is not a predicate for ordinary text content; ordinary DOM-style
element text should first require `get_token_type() === '#text'`, while
comments, processing instructions, and special-element openers should be
included only by explicit caller policy.

Purpose: test whether moving the ordinary-text versus special-element
opt-in boundary to the method sections reduces special-element over-inclusion
in text extraction and text-node-only serialization tasks without editing
source docblocks.
