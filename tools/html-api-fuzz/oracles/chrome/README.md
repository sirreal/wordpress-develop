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
under `.chrome-for-testing/<version>/<platform>`, and verifies the binary's
reported version. Set `HTML_API_FUZZ_CHROME_INSTALL_ROOT` to use another
install root. `--chrome-executable` may select another path, but the script
rejects binaries whose version differs from `VERSION`.

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

Run the end-to-end smoke test after installation:

```sh
node tools/html-api-fuzz/oracles/chrome/smoke-test.js
php tools/html-api-fuzz/tests/chrome-oracle-smoke.php
```

It checks canonical formatting, full-document data-URL parsing, author-script
suppression, network blocking, SVG/table contextual fragments, concurrent
socket clients, Chrome-process reuse and recovery, graceful shutdown/socket
cleanup, and fresh-service replay/minimization without persisted transport
state.
