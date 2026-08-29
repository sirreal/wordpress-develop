# Round 26 Scratch Variant

Variant name: `readonly-text-policy-matrix`

Control round: `round-25`

Edited rendered file: `/tmp/html-api-docs-eval/round-26/html-processor.md`

Source docblocks were not edited. This was a scratch-only rendered-doc A/B
variant. The staged `html-processor.md` SHA-256 recorded in
`round-metadata.json` is:

```text
c0011d8b1a6431e0fa82fe953f9be5b2b38752a83115255c18403d8716179ab1
```

Inserted under `##### Recipe: collect DOM-style text from a subtree`:

```markdown
Text extraction policy matrix:

| Caller intent | Tokens to read | Notes |
|---|---|---|
| Ordinary subtree text | Only tokens where `get_token_type() === '#text'` | This is the default DOM-style text walk. Do not call `get_modifiable_text()` on every opening tag. |
| Include TITLE or TEXTAREA text | Add an explicit check for those opening element tokens and read their `get_modifiable_text()` | These elements carry decoded text on their own token and expose no child `#text` tokens. |
| Include SCRIPT or STYLE text | Add an explicit check for those opening element tokens and read their `get_modifiable_text()` | This is raw/non-ordinary text. Include it only when the caller asks for script or stylesheet contents. |
| Comments, processing instructions, and other syntax | Do not include for ordinary subtree text | They can carry modifiable text, but they are not DOM text descendants. |
| `get_last_error()` or `paused_at_incomplete_token()` after a read-only walk | Caller policy | The walk may have collected useful text before it stopped. Return best-effort text, return an empty result, or reject according to the function's contract. Mutations and strict complete-input scans should fail closed. |
```

Outcome: mixed/no source promotion. The variant improved the aggregate subset
score and T05 adherence, but it encouraged all three T03 subjects to include
SCRIPT/STYLE/TITLE/TEXTAREA opener text when the task wanted ordinary heading
text. N06 still over-included special-element text inside headings.
