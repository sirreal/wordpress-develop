# Lexbor Source Oracle

This directory contains a standalone oracle binary used by the
`set_inner_html` fuzzer to self-check accepted and rejected updates against a
source-built Lexbor checkout.

Build upstream `master`:

```sh
tools/html-api-fuzz/oracles/lexbor/build.sh
```

The script clones Lexbor under `.cache/lexbor/<ref>/source`, builds and
installs a static Lexbor library under the same cache entry, then writes:

```text
tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle
```

Use it from the `set_inner_html` fuzzer by passing the binary path, or by
building it at the default location shown above:

```sh
php tools/html-api-fuzz/set-inner-html.php \
  --iterations 100 \
  --lexbor-oracle-bin tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle \
  --output-dir artifacts/html-api-fuzz/set-inner-html-lexbor
```

Pass `--lexbor-oracle-bin PATH` or set `HTML_API_FUZZ_LEXBOR_ORACLE` when the
binary is not at the default build path above.

When present, the oracle parses the original and candidate-updated HTML and
compares the rendered tree outside the target element. For accepted
`set_inner_html()` calls, a changed outside tree is a fuzzer failure: it
indicates a replacement that should have been rejected by the HTML API. For
rejected calls, a preserved outside tree is a fuzzer failure: it indicates a
replacement that should have been accepted by the HTML API.

The oracle also self-checks each Lexbor parse by serializing the parsed tree,
parsing that serialization again in the same mode, and comparing the rendered
tree bytes. The fuzzer records this as `originalSelfCheck` and
`updatedSelfCheck` in Lexbor failure details. The self-check is diagnostic
metadata; the original-vs-updated outside-tree comparison remains the oracle
signal.

The binary records the resolved Lexbor commit in its JSON metadata, even when
building from a moving ref such as `master`.

Use a different checkout or commit when bisecting upstream behavior:

```sh
LEXBOR_SOURCE_DIR=/path/to/lexbor \
LEXBOR_COMMIT=481c444261a132190a3fb746d6d2f60824af3717 \
tools/html-api-fuzz/oracles/lexbor/build.sh
```

Direct CLI examples:

```sh
tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle \
  --mode full-document \
  --max-nodes 3000 \
  --input /path/to/input.bin

tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle \
  --mode fragment-body \
  --context body \
  --max-nodes 3000 \
  --input /path/to/input.bin
```

The oracle returns JSON with `status`, `oracle` metadata, `selfCheck`, `tree`,
`treeBase64`, and `nodeCount`. The `treeBase64` field is the exact
html5lib-style tree bytes consumed by the PHP adapter; `tree` is the same tree
as a JSON-safe display string. Neither field is serialized HTML.
