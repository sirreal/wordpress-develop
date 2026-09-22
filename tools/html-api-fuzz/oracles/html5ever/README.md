# Source-built html5ever tree oracle

This standalone Rust binary parses HTML with pinned `html5ever` 0.39.0 and
`markup5ever_rcdom` 0.39.0+unofficial, then renders the fuzzer's canonical
html5lib-style tree. `Cargo.lock` pins every transitive crate and its registry
checksum. The CLI metadata exposes the two direct crate checksums, and
`rust-toolchain.toml` pins Rust 1.88.0.

Install the pinned local Rust toolchain and build:

```sh
tools/html-api-fuzz/oracles/html5ever/install-rust.sh
tools/html-api-fuzz/oracles/html5ever/build.sh
tools/html-api-fuzz/oracles/html5ever/smoke.sh
```

The installer supports arm64/x86-64 macOS and glibc Linux, installs below
`.cache/html5ever/rust`, and does not edit shell startup files. A preinstalled
`cargo` also works. The build script automatically finds the local install and
verifies that the checked-in Rust 1.88.0 toolchain is active. The rustup 1.28.2
bootstrap executable is verified against `RUSTUP_SHA256SUMS` before it is made
executable. Those checked-in digests come from the publisher's HTTPS SHA-256
sidecars, so a fresh checksum is not trusted alongside each download.

CLI:

```text
html5ever-tree-oracle --mode full-document|fragment-* --input PATH [--context TAG] [--max-nodes N]
html5ever-tree-oracle --version
```

Fragment modes use html5ever's context-aware fragment parser. Supported
contexts match the fuzzer: `body`, `div`, `p`, `td`, `tr`, `table`, `caption`,
`colgroup`, `select`, `option`, `template`, `title`, `textarea`, `script`,
`style`, `svg`, and `math`. The result is one JSON object containing `status`,
`oracle`, `tree`, `treeBase64`, and `nodeCount` on success.
