# Source-built html5ever tree oracle

This standalone Rust binary parses HTML with pinned `html5ever` 0.39.0 and
`markup5ever_rcdom` 0.39.0+unofficial, then renders the fuzzer's canonical
html5lib-style tree. `Cargo.lock` pins every transitive crate and its registry
checksum. `rust-toolchain.toml` pins Rust 1.88.0. The CLI metadata exposes the
two direct crate checksums, complete lockfile hash, and build identity.

Install the pinned local Rust toolchain and build:

```sh
tools/html-api-fuzz/oracles/html5ever/install-rust.sh
tools/html-api-fuzz/oracles/html5ever/build.sh
tools/html-api-fuzz/oracles/html5ever/smoke.sh
```

The installer supports arm64/x86-64 macOS and glibc Linux, installs below
`.cache/html5ever/rust`, and does not edit shell startup files. A preinstalled
`cargo` also works. The build script automatically finds the local install and
requires both rustc and Cargo 1.88.0. The rustup 1.28.2
bootstrap executable is verified against `RUSTUP_SHA256SUMS` before it is made
executable. Those checked-in digests come from the publisher's HTTPS SHA-256
sidecars, so a fresh checksum is not trusted alongside each download.

The build uses Cargo's locked mode and derives a build identity from the exact
checked-in `Cargo.toml`, `Cargo.lock`, toolchain file, and Rust source bytes. It
publishes `build/html5ever-tree-oracle`, then atomically renames
`build/build-manifest.json` last as the pair's commit marker. The manifest
records those input hashes, the direct crate pins/checksums, Rust and Cargo
identities, build time, and the executable SHA-256. Consumers must read the
manifest before and after recomputing the executable hash and probing
`--version`, reject a changing manifest, and require all three identities to
agree. An interrupted publication therefore fails closed instead of exposing
an accepted mixed pair. The build and lock identities are required compile-time
inputs; missing inputs cannot compile, and malformed identities report the
oracle unavailable instead of producing a successful version response.

CLI:

```text
html5ever-tree-oracle --mode full-document|fragment-* --input PATH [--context TAG] [--max-nodes N] [--max-depth N] [--max-tree-bytes N]
html5ever-tree-oracle --version
```

Fragment modes use html5ever's context-aware fragment parser. Supported
contexts match the fuzzer: `body`, `div`, `p`, `td`, `tr`, `table`, `caption`,
`colgroup`, `select`, `option`, `template`, `title`, `textarea`, `script`,
`style`, `svg`, and `math`. The result is one JSON object containing `status`,
`oracle`, `tree`, `treeBase64`, and `nodeCount` on success. Parsing always has
scripting disabled. Input is read as exact bytes; invalid UTF-8 is reported as
an explicit `unsupported` result because html5ever's UTF-8 sink would otherwise
replace invalid byte sequences. It is never silently transcoded. Node, depth,
and tree-output byte limits fail with structured resource-limit classes.

`smoke.sh` exercises every fragment context, canonical output, scripting mode,
invalid-byte handling, resource limits, and manifest/binary identity. The
network-free `tests/html5ever-install-integrity-smoke.sh` proves corrupt cached
bootstrap bytes are rejected before chmod or execution.
