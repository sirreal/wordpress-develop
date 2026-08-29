# Lexbor Source Oracle

This directory contains a standalone oracle binary for comparing the WordPress
HTML API against a source-built Lexbor checkout instead of PHP's bundled
`Dom\HTMLDocument` runtime.

Build the exact commit recorded in `COMMIT`:

```sh
tools/html-api-fuzz/oracles/lexbor/build.sh
```

The script clones Lexbor under `.cache/lexbor/<commit>/source`, builds and
installs a static Lexbor library under the same cache entry, then writes:

```text
tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle
```

Lexbor is the default oracle; it can also be selected explicitly:

```sh
php tools/html-api-fuzz/worker.php \
  --seed 1 \
  --dom-oracle lexbor-source \
  --output-dir artifacts/html-api-fuzz/seed-1-lexbor

php tools/html-api-fuzz/runner.php \
  --max-seeds 100 \
  --dom-oracle lexbor-source
```

Pass `--lexbor-oracle-bin PATH` or set `HTML_API_FUZZ_LEXBOR_ORACLE` when the
binary is not at the default build path above. Replays preserve the selected
oracle and binary path.

The binary records the resolved Lexbor commit in its JSON metadata. The build
fails if the resolved commit differs from the requested pin or if the source
checkout contains tracked, untracked, or ignored changes. Use a separate clean
checkout when testing local Lexbor modifications; the pinned oracle never
builds bytes that Git cannot attribute to `COMMIT`.

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

Successful results always contain `treeBase64`, the authoritative exact
html5lib-style tree bytes consumed by the PHP adapter. The optional `tree`
field contains the same bytes as a JSON display string when the canonical tree
is valid UTF-8; it is omitted otherwise. Neither field is serialized HTML.
