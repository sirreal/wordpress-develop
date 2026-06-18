# Lexbor Source Oracle

This directory contains a standalone oracle binary for comparing the WordPress
HTML API against a source-built Lexbor checkout instead of PHP's bundled
`Dom\HTMLDocument` runtime.

Build upstream `master`:

```sh
tools/html-api-fuzz/oracles/lexbor/build.sh
```

The script clones Lexbor under `.cache/lexbor/<ref>/source`, builds and
installs a static Lexbor library under the same cache entry, then writes:

```text
tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle
```

Use it from the fuzzer by selecting the non-default oracle:

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

The oracle returns JSON with `status`, `oracle` metadata, `tree`,
`treeBase64`, and `nodeCount`. The `treeBase64` field is the exact
html5lib-style tree bytes consumed by the PHP adapter; `tree` is the same tree
as a JSON-safe display string. Neither field is serialized HTML.
