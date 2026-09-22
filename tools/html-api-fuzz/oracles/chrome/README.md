# Pinned Chrome CDP tree oracle

This oracle parses HTML with Chrome for Testing and serializes the resulting
DOM in the fuzzer's canonical tree format. It uses Node's built-in modules and
the Chrome DevTools Protocol directly; it has no npm, Playwright, or Puppeteer
dependency.

## Install and verify

`VERSION` pins Chrome for Testing 150.0.7871.114. `SHA256SUMS` independently
anchors each official archive and `EXECUTABLE_SHA256SUMS` anchors the Chrome
executable extracted from each archive for macOS arm64/x64 and Linux x64.
Foreign-platform executable hashes are derived without executing those
binaries.

```sh
tools/html-api-fuzz/oracles/chrome/install.sh
tools/html-api-fuzz/oracles/chrome/install.sh --print-path
```

The installer requires HTTPS, authenticates the archive before extraction,
and authenticates the executable before its version probe. Publication uses a
serialized lock, unique staging, rollback backup, and marker-last commit. The
marker records the authenticated snapshot for diagnostics but never acts as
its own trust anchor: cache hits always re-read the checked-in hashes and
rehash around the version probe. Downloads have a 15-second connect timeout
and a 300-second overall timeout. A dependency-free Node watchdog caps the
version probe at 10 seconds and 16 KiB of combined output, terminates its whole
process group with bounded TERM/KILL escalation, and removes the exact staging
directory and authenticated lock if the installer owner dies. An elected
in-lock reaper may also recover a lock whose exact published owner PID is dead.
Missing or malformed owner metadata fails closed and requires manual removal
because it cannot be reaped without a race against an owner that has not
finished publishing its identity.

Set `HTML_API_FUZZ_CHROME_INSTALL_ROOT` to relocate the cache. An alternate
`--chrome-executable` path is accepted only when its bytes match the current
platform's checked-in executable hash and its live CDP version matches
`VERSION`. Oracle startup does not take the installer's publication lock: it
compares read-only executable and marker identity snapshots around supervised
startup, so concurrent replacement fails closed without leaving an
owner-created lock. `--print-path` computes a path only; it downloads and
executes nothing.

## Run

One-shot mode accepts an exact input file:

```sh
node tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js \
  --mode full-document --input /path/to/input.html
```

Persistent stdio uses one newline-delimited JSON request at a time:

```sh
node tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js --serve
```

Socket mode keeps the same Chrome process warm for multiple clients. The
socket parent must already be owned by the current uid with mode exactly 0700;
the socket is created with mode 0600 only after Chrome has been authenticated
and CDP is ready. Each socket connection carries exactly one request and
response.

```sh
runtime_dir="$(mktemp -d)"
chmod 0700 "$runtime_dir"
node tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js \
  --serve --socket "$runtime_dir/oracle.sock"
```

Commands are `render`, `version`, and `shutdown`. Render payloads contain
canonical `htmlBase64`, `mode`, and positive `maxNodes`, `maxDepth`, and
`maxTreeBytes` limits. Fragment mode accepts only the 17 contexts in
`../fragment-contexts.json`, which the smoke test checks against
`Generator::fragment_contexts()`. Request frames must be valid UTF-8 JSON.
Optional request IDs are strings or JSON numbers that decode to safe integers;
string IDs are capped at 4,096 UTF-8 bytes, and invalid or rounded numeric IDs
are rejected and never echoed. Socket admission is capped at eight peers. An
incomplete request and a blocked response each have a 10-second deadline, and
disconnected requests are cancelled before dispatch when possible.

Successful renders return only `treeBase64` for tree bytes—never a duplicate
JSON string—plus `treeBytes`, `treeSha256`, and `nodeCount`. Raw input is capped
at 2 MiB, nodes at 100,000, rendered indentation depth at 1,024, canonical tree
bytes at 16 MiB, request frames at 4 MiB, response frames at 24 MiB, and
internal CDP messages at 32 MiB. Invalid UTF-8 is a structured unsupported
result; malformed or noncanonical base64 is a protocol error. Renderer
envelopes use exact schemas and fixed failure-class allowlists, and successful
tree bytes must be canonical UTF-8 text.

Documents are parsed with a detached `DOMParser` document. Fragments use a
detached inert contextual document. Author nodes are never inserted into the
active trusted renderer page, and outbound protocols are blocked. Chrome,
Node, script, and fragment-context hashes/versions, the ordered context list,
and the live CDP protocol form durable `oracle.identity`. Paths, endpoints,
PIDs, profiles, sockets, and instance counters appear only in
`oracle.transport`, marked `replayExcluded`.

The owner creates no runtime resources and executes no Chrome binary before
starting an internal direct-parent supervisor. That supervisor creates the
runtime/profile and starts one token/profile-bearing Chrome tree as its
non-detached child; the live `Browser.getVersion` CDP response proves the
runtime version before the oracle becomes healthy, and a second live identity
round trip gates publication after setup. The entire startup has one
30-second absolute deadline. Supervisor events are fatal-UTF-8, capped at 1
MiB, and accepted only through exact per-event schemas; a protocol failure is
fatal both before and after readiness. The separate bounded
`--version` probe belongs to installation, where installer signal/rollback
handling owns it. The owner and supervisor monitor each other: owner death
closes an unshared pipe and triggers supervisor cleanup; supervisor death
triggers authenticated owner-side process-tree cleanup and a fatal
infrastructure result. Process birth identity and the unique profile/token
prevent signaling PID-reuse victims. The entire tree continues to inherit the
outer worker process group. Supervisor
process-snapshot heartbeats are dropped while its owner pipe is backpressured.
Cleanup uses one process-table snapshot per signaling pass, gives each
synchronous inspection only the remaining absolute cleanup budget, and
preserves the runtime/profile whenever the authenticated tree is not proven
gone. A cleanup failure is an oracle-infrastructure failure.
A render is attempted again at most once, and only after an authenticated live
CDP session is observed closed. Each attempt uses one exact captured session;
timeouts, malformed trusted output, and a failed final retry quarantine that
session without replaying author input again.

## Verify

```sh
tools/html-api-fuzz/tests/chrome-install-integrity-smoke.sh
node tools/html-api-fuzz/oracles/chrome/smoke-test.js
```

The first suite is network-free fault injection for manifests, archive and
executable authentication, cache races, concurrent locks, transactional
rollback, signals, and stale-lock election. The second runs the real pinned
browser through canonical/security/limit/framing tests, exact 16 MiB output,
exact 2 MiB raw-input acceptance and overflow rejection, warm reuse and
restart, signals, bounded partial clients and writes, and separate
owner/supervisor hard-kill tests during startup both before any executable
child/socket exists and after the authenticated browser tree has spawned.
Additional supervisor/browser hard kills after the CDP handshake prove that
death remains latched until healthy publication; steady-state owner/supervisor
hard-kill cleanup is covered separately. A real paused Unix-socket client
forces a 20 MiB response into backpressure, disconnects, and proves bounded
slot release and dispatcher recovery.
