# Chrome CDP tree oracle

This oracle launches the pinned Chrome for Testing build directly and speaks
the Chrome DevTools Protocol over its remote-debugging WebSocket. It has no
Playwright or npm package dependency.

Install the exact version recorded in `VERSION` and print its path with:

```sh
tools/html-api-fuzz/oracles/chrome/install.sh
tools/html-api-fuzz/oracles/chrome/install.sh --print-path
```

The installer supports macOS arm64/x64 and Linux x64. It downloads the
platform archive from the versioned Chrome for Testing public URL, installs it
under `.chrome-for-testing/<version>/<platform>`, and verifies both its
checked-in SHA-256 and the binary's reported version. Chrome does not publish
SHA-256 sidecars; the values in `SHA256SUMS` were calculated from the exact
immutable official HTTPS objects and cross-checked against their GCS MD5
metadata. A verified-install marker records the version, platform, and archive
digest. Installations made before the marker existed are replaced from a
verified archive without executing the old binary. `--print-path` only prints
the expected path and does not assert that the installation is verified. Set
`HTML_API_FUZZ_CHROME_INSTALL_ROOT` to use another install root.
`--chrome-executable` may select another path, but the oracle rejects binaries
whose version differs from `VERSION`.

For persistent operation, use newline-delimited JSON over standard input:

```sh
node tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js --serve
```

Or keep one Chrome process alive across multiple clients and PHP workers with
a Unix-domain socket:

```sh
node tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js \
  --serve --socket /tmp/html-api-fuzz-chrome.sock
```

The accepted commands are `render`, `version`, and `shutdown`. Full documents
are navigated as `data:text/html;charset=utf-8;base64,...` while author script
execution is disabled. HTTP(S), FTP, file, and WebSocket loads are blocked.
Fragments are parsed with `Range.createContextualFragment()` using an HTML,
SVG, or MathML context element as appropriate. Successful renders return
`status`, `oracle`, `tree`, `treeBase64`, and `nodeCount`.

Custom `--chrome-oracle-script` implementations must use the same protocol.
Every successful version or render response identifies the tracked Chrome
pin, the canonical script and executable paths, Node, and the CDP transport.
Socket version responses and successful or unsupported renders must also
include a non-empty CDP protocol version and browser instance ID plus a
positive integer browser PID. Error responses always include integer
`nodeCount: 0`; errors produced before Chrome starts may omit all three live
fields, while errors that include any live field must include the complete
set. PHP accepts only `oracle-unavailable`, `oracle-renderer-error`, and
`node-limit-exceeded` as semantic error classes, decodes `treeBase64`
strictly, and discards response metadata outside the validated contract.

Run the end-to-end smoke test after installation:

```sh
node tools/html-api-fuzz/oracles/chrome/smoke-test.js
php tools/html-api-fuzz/tests/chrome-oracle-protocol-smoke.php
php tools/html-api-fuzz/tests/chrome-oracle-smoke.php
```

It checks canonical formatting, full-document data-URL parsing, author-script
suppression, network blocking, SVG/table contextual fragments, concurrent
socket clients, Chrome-process reuse and recovery, graceful shutdown/socket
cleanup, and fresh-service replay/minimization without persisted transport
state.
