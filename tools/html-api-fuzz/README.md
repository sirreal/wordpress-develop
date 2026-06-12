# HTML API Fuzzer

Pure PHP fuzzer for the WordPress HTML API under `src/wp-includes/html-api`.
It compares `WP_HTML_Processor` against a selectable tree-construction oracle
using an html5lib-style textual tree, and separately checks a set of API
invariants described under “Invariants” below. The default oracle is PHP's
`Dom\HTMLDocument`, preserving the historical behavior.

No browser, Playwright, Node, or `wp-env` is involved.

## Requirements

- PHP 8.4+ with ext-dom, for `Dom\HTMLDocument`.
- Run from the repository root.
- Optional source-built Lexbor oracle: `git`, `cmake`, and a C compiler.

## Common Commands

Run one generated seed:

```sh
php tools/html-api-fuzz/worker.php --seed 1 --output-dir artifacts/html-api-fuzz/seed-1
```

Run one generated seed with a specific terminal payload policy:

```sh
php tools/html-api-fuzz/worker.php --seed 1 --payload-policy valid-utf8 --output-dir artifacts/html-api-fuzz/seed-1
```

Run a batch in worker subprocesses (seeds are batched into shared worker
processes, 25 per process by default; see `--batch-size`):

```sh
php tools/html-api-fuzz/runner.php --max-seeds 100 --duration-seconds 60
```

Run a structural UTF-8-biased batch with a post-generation byte cap:

```sh
php tools/html-api-fuzz/runner.php --max-seeds 100 --payload-policy valid-utf8 --max-input-bytes 4096
```

Build and run against the source-built Lexbor oracle:

```sh
tools/html-api-fuzz/oracles/lexbor/build.sh
php tools/html-api-fuzz/worker.php --seed 1 --dom-oracle lexbor-source --output-dir artifacts/html-api-fuzz/seed-1-lexbor
php tools/html-api-fuzz/runner.php --max-seeds 100 --dom-oracle lexbor-source --duration-seconds 60
```

Use `--lexbor-oracle-bin PATH` or `HTML_API_FUZZ_LEXBOR_ORACLE` when the
oracle binary is not at
`tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle`.

Run indefinitely:

```sh
php tools/html-api-fuzz/runner.php --duration-seconds 0 --max-seeds 0
```

Run parallel lanes and triage failures after completion:

```sh
php tools/html-api-fuzz/launcher.php --lanes 4 --max-seeds 1000 --watcher
```

For continuous fuzzing, run the launcher with `--duration-seconds 0 --max-seeds 0`
and run `watcher.php` in a second shell against the same output directory.

Stop an indefinite run gracefully (each lane finishes its current batch, the
watcher performs a final scan, and the codex orchestrator drains its running
jobs):

```sh
php tools/html-api-fuzz/stop.php --run-dir artifacts/html-api-fuzz/run-...
```

Without `--run-dir` the most recently active *unfinished* run under
`artifacts/html-api-fuzz` is targeted. Finished and stale runs are not
preferred; if nothing live is found, the most recent stopped-looking run is
targeted with a warning. The script creates the stop file advertised by the
run state and also `RUN_DIR/STOP` when a run directory is known, so watchers
and orchestrators see the stop request. For a standalone runner with custom
`--stop-file PATH`, both files are written. Relative custom stop files are
resolved with the runner cwd recorded in new runner state; for older state,
pass only `--stop-file PATH` to write a known stop file directly if needed.
With `--run-dir --stop-file PATH`, the explicit path is added to the run-state
and `RUN_DIR/STOP` targets. `touch` works just as well. The launcher and runner refuse to start while a stop file already
exists — remove `STOP` (or the custom stop file) before reusing a run
directory. (A stop requested in the sub-second window between the launcher's
startup check and a lane's own makes that lane refuse rather than stop
gracefully; the run still ends.)
If state cannot be read or old state lacks enough context to locate a relative
custom stop file, the tool still writes `RUN_DIR/STOP` but exits `2` with
`ok: false` and warnings because a standalone custom stop file may be unknown.

The watcher exits after a final scan once every runner under the run
directory reports a stop reason. A runner whose state has gone silent is
presumed dead after `--stop-stale-seconds` (default 120); per lane that
threshold is floored at twice the lane's advertised batch budget
(`timeout-ms × batch-size`), so long batches are not mistaken for crashes.
The stop tool uses the same default stale threshold when auto-selecting the
latest unfinished run.

Replay a failure from a retained seed directory, or from the lane's SQLite
store when the seed directory was pruned (see "Artifact Retention"):

```sh
php tools/html-api-fuzz/replay.php --replay artifacts/html-api-fuzz/run-.../seed-.../primary/replay.json
php tools/html-api-fuzz/replay.php --store artifacts/html-api-fuzz/run-.../lane-00/results.sqlite --seed 12345
```

Minimize a failure while preserving the same signature:

```sh
php tools/html-api-fuzz/minimize.php --replay artifacts/html-api-fuzz/run-.../seed-.../primary/replay.json
```

By default (`--probe-mode auto`) the minimizer evaluates candidates in worker
subprocesses so `--timeout-ms` can kill pathological candidates and each probe
starts with fresh PHP state. Use `--probe-mode in-process` for faster
exact-signature minimization when that isolation is not needed; in-process
probes write only the final minimized artifacts unless
`--keep-candidate-artifacts` is also passed. Use `--probe-mode process` to
force subprocess probes explicitly.

Watch an existing run directory and minimize new distinct signatures:

```sh
php tools/html-api-fuzz/watcher.php --run-dir artifacts/html-api-fuzz/run-... --once
```

Configured ceilings are reported as `failureClass: "resource-limit"` and remain
in the watcher/minimizer triage path. This bucket includes tag/tree token
ceilings (`tag-token-limit-exceeded`, `mutation-token-limit-exceeded`,
`wordpress-token-limit-exceeded`) and oracle node ceilings
(`node-limit-exceeded`, recorded as `dom-node-limit-exceeded` in historical
signature facts). Process timeouts, PHP fatal errors, and memory failures are
separate failures and are also in scope for triage.

## Execution Model

The runner batches consecutive seeds into one worker process
(`worker.php --batch-count N`, default `--batch-size 25` on the runner) so the
WordPress bootstrap and process spawn are paid once per batch rather than once
per seed. Each seed still writes its own `seed-N/primary` artifacts. If a batch
process dies or times out mid-way, seeds left without a `result.json` are
re-run individually in isolation, so a crash on one input cannot take
neighboring seeds' results with it.

## Input Stages

Seeds are deterministically split between two input stages:

- **Generated** (default ~80%): the structural grammar described under
  “Generator Profiles”.
- **Corpus-mutated** (default ~20%, `--corpus-mutate-percent N` on
  `worker.php`/`runner.php`): a `#data` section from the html5lib-tests
  tree-construction corpus (`tests/phpunit/data/html5lib-tests`), passed
  through 1–4 deterministic mutations (byte insert/replace, chunk
  delete/duplicate, tag-name swap, case toggle, corpus splice). The stage,
  corpus file, entry index, and operations are recorded in result metadata,
  and the mutated input itself is in the replay manifest, so replays are
  standalone. Inputs report `inputSource: "corpus-mutated"` and
  `profile: "corpus-mutated"`.

Both stages derive entirely from the seed, so seed N always produces the same
input for the same fuzzer version and corpus.

## Artifact Layout

The runner writes:

- `results.sqlite`: one row per attempted seed (table `attempts`, WAL mode).
  Passing attempts store summary columns only — every attempt is regenerable
  from its seed. Failure rows additionally store the summary, result, and
  replay JSON documents; the replay embeds the input as base64, so a pruned
  failure can be reproduced with `replay.php --store results.sqlite --seed N`.
  `signature_hash` and `family_key` are indexed columns for grouping
  failures without `json_extract`. `oracle_kind`, `oracle_version`,
  `oracle_commit`, and `oracle_binary` record which oracle generated the
  summary, including for passing rows whose JSON payloads are pruned. The
  watcher tails these stores
  incrementally by row id. (`summary.ndjson` files from older runs are still
  scanned.) Durability is `synchronous=NORMAL`: an OS crash (not a process
  crash) can lose the last moments of a run.
- `events.ndjson`: runner lifecycle events, including batch boundaries.
- `logs/batch-N.log`: output of a batch worker process, kept only when the
  batch contained a retained failure or a seed that needed an isolated
  re-run — over-cap repeats of a known signature do not accumulate batch
  logs.
- `state.json`: aggregate counters, stop reason, and compact Git metadata.
  Oracle losses are counted per class: `oracleParseErrors` (inputs the
  selected oracle rejects receive no differential coverage),
  `oracleUnsupported` (tree shapes the oracle cannot represent), and
  `oracleTolerated`
  (comparisons that passed only under the documented scalar tolerance).
- `seed-N/primary/input.bin`: raw generated bytes.
- `seed-N/primary/replay.json`: base64 replay manifest, including the commit
  hash, tracked-file dirty state, selected oracle, and fragment context needed
  to interpret a standalone replay.
- `seed-N/primary/result.json`: full worker result.
- `seed-N/primary/wordpress-tree.txt` and `dom-tree.txt`: rendered trees when available.

### Artifact Retention

Seed directories are working space, not the archive. After each seed is
recorded in `results.sqlite`, its `seed-N` directory is deleted unless the
attempt failed *and* the failure's signature has fewer than
`--max-keep-per-signature` (default 5, minimum 1) exemplar directories still
on disk in this lane. The cap counts directories, not rows, so restarting a
runner against the same output directory neither double-counts re-recorded
seeds nor deletes a previously retained exemplar. The first exemplar of every
new signature is always retained, so the watcher's minimization path keeps a
replay file to work from; subsequent repeats of a known signature add a
database row and nothing else. A failure whose replay document is missing
(worker killed before writing it) always keeps its directory — the files are
the only reproduction. Disk growth is therefore proportional to *new
distinct failures*, not to seeds executed.

The cap applies per lane: the launcher passes the same value to every lane,
so a signature that appears in all lanes keeps up to `N × lanes` directories
across the run. Use `--keep-all-artifacts` (runner or launcher) to keep every
seed directory for debugging.

The run-level Git metadata intentionally stays compact: full and short commit
hash, current branch when available, commit date, and a dirty flag for
tracked-file changes. The dirty flag is tri-state: `true`, `false`, or `null`
when Git is unavailable or dirty detection fails. Full `git status` or diff
output is not recorded because it is noisy, can expose local edits, and grows
indefinitely in long runs. Launcher and runner processes collect this metadata
once and pass it to workers so long runs do not invoke Git for every seed.
Replayed and minimized manifests keep current checkout metadata at the top level
and preserve discovery provenance in `sourceReplay`.

The watcher writes triage state under `.triage-watcher` by default, or under
`--state-dir` when provided. Each signature gets a stable directory containing
`failure.json`, minimizer logs, and minimized replay/result artifacts. Failed
minimizations are retried on later scans, up to `--max-minimize-retries`
(default 3) attempts per signature.

## Modes and Fragment Contexts

- `fragment-body`: parse as a fragment. The selected oracle uses real fragment
  parsing (the `innerHTML` setter on a context element of an empty document),
  not a document-wrapping approximation.
- `full-document`: parse as a full HTML document.
- `auto`: weighted choice.

In fragment mode a context element is selected per seed
(`--fragment-context TAG` on `worker.php` for replays). `<body>` dominates;
the other contexts (`div`, `p`, `td`, `tr`, `table`, `caption`, `colgroup`,
`select`, `option`, `template`, `title`, `textarea`, `script`, `style`,
`svg`, `math`) receive a small probe weight. `WP_HTML_Processor::create_fragment()`
currently supports only `<body>`, so non-body contexts are recorded as
`status: "unsupported"` today; when create_fragment() gains context support
the fuzzer picks up the new coverage with no changes. The selected oracle already
parses every context correctly.

Unsupported `WP_HTML_Processor` cases are expected by default and are recorded
as successful attempts with `status: "unsupported"`. Use `--fail-unsupported`
when you want unsupported cases to become failures.

## Invariants

Each seed checks, in order, stopping at the first failing class:

1. **Tag Processor invariants** (`tag-invariant-failed`): token loop
   termination under the token ceiling; non-null token type/name/tag;
   attribute getters and `class_list()` iteration do not throw;
   `get_updated_html()` with no queued edits returns the input unchanged; a
   simple `set_attribute()` mutation is visible to a re-scan; and
   **seek consistency** — a bookmark set at a seed-chosen token, after
   scanning to the end and seeking back, must reproduce the identical token
   stream (`seek-token-stream-mismatch`).
2. **Differential tree comparison** (`tree-mismatch` / `encoding-mismatch`):
   the WordPress tree must equal the selected oracle tree (see “Tree Comparison”).
3. **Breadcrumb consistency** (`breadcrumb-mismatch`): at every tag token,
   `get_breadcrumbs()` must agree with the element stack derived from token
   order and `expects_closer()`.
4. **Mutation differential** (`mutation-tree-mismatch` /
   `mutation-delta-mismatch`), only on a clean baseline: after setting
   `data-fuzz="1"` on the first tag, the mutated document must parse
   identically in WordPress and the selected oracle, and the WordPress tree must
   change by exactly the one attribute line (unless formatting-element
   reconstruction clones the attribute, or tree construction legitimately
   drops the mutated element, in which case the differential comparison alone
   applies).
5. **Normalize tree preservation** (`normalize-tree-changed`), only on a
   clean baseline: parsing `normalize()` output must produce the same tree as
   the original input, modulo the documented scalar substitutions. This is
   stricter than idempotence, which a consistently wrong serializer can pass.
6. **Normalize idempotence** (`normalize-invariant-failed`):
   `normalize()` / `serialize()` run twice must be a fixed point, with no
   PHP native errors or throwables. Full documents use
   `create_full_parser()->serialize()`; non-body fragment contexts use
   `create_fragment(<context>)->serialize()`.

### Known invariant oracle follow-ups

- The simple `set_attribute()` mutation oracle needs to handle inputs that
  begin with `</br>` using the same tag-selection semantics as the mutator.
  `next_tag()` skips the raw closing token and mutates the following tag,
  while a verifier that scans with `next_token()` can see the spec-special
  `BR` element synthesized from `</br>` first and incorrectly report
  `mutation-attribute-missing`. Fix the verifier by selecting the first
  mutable tag through `next_tag()` too.

## Generator Profiles

The generator uses a structural HTML grammar with weighted profiles:

- `balanced`
- `full-document` (includes occasional frameset documents, quirks-mode
  doctypes, and content after `</html>`)
- `body-fragment`
- `tables`
- `template`
- `select` (option/optgroup nesting, select-ending elements such as `input`,
  `textarea` and `button`, nested selects, select-in-table)
- `foreign-content` (MathML/SVG integration points, HTML breakout tags,
  `<font>` with and without breakout attributes, `annotation-xml` encoding
  variants, CDATA sections in foreign content, case-mangled `foreignObject`)
- `rawtext-rcdata` (script/style/iframe/noembed/noframes/xmp/noscript,
  title/textarea, occasional `plaintext`)
- `text-fragment` (standalone terminal payloads, biased toward exact
  0-10 byte inputs plus medium syntax-heavy text unless `stress-long` is
  selected explicitly)
- `formatting-adoption` (random formatting elements plus explicit
  adoption-agency shapes: misnested closers, block-boundary formatting,
  reconstruction across siblings, nested anchors, Noah's Ark overflow,
  repeated closers)
- `attributes-entities`
- `comments-doctype-bogus`
- `deep-nesting`
- `resource-stress`
- `incomplete-malformed` (includes spec-special closers such as `</br>` and
  `</p>`, stray closers, and `<image>`)

All profiles can emit duplicate attribute names (first-wins coverage),
auto-closing chains (`li`, `dd`/`dt`, headings, `p`), and named character
references with longest-prefix-match ambiguity (`&notit;`, `&copyright;`,
`&ngE`, ...).

Terminal payloads are selected by a separate policy:

- `valid-utf8`: structural cases with ASCII, Unicode, controls, and
  entity references, including NUL byte coverage, but no raw invalid bytes.
- `mostly-valid`: default-biased structural cases with valid UTF-8 Unicode,
  controls, NUL bytes, and entity references.
- `ascii-structural`: ASCII-only terminal text and attributes for tokenizer and
  tree-construction coverage, including NUL byte coverage.
- `stress-long`: long valid UTF-8 terminal payloads for deliberate
  resource-stress runs.
- `auto`: weighted choice. Normal structural profiles favor valid UTF-8 and
  mostly-valid payloads; `resource-stress` favors `stress-long`.

Use `--payload-policy POLICY` on `worker.php`, `runner.php`, or `launcher.php`.
Use `--max-input-bytes N` to apply a post-generation byte cap before the worker
records replay metadata. The cap preserves UTF-8 byte boundaries, but it is not
grammar-aware and may cut through HTML tokens. Replay manifests and minimization
summaries preserve the original payload policy when it was recorded; old or
hand-supplied inputs leave `payloadPolicy` null unless an explicit policy label
is provided. Historical `invalid-byte-heavy` labels are accepted only as replay
metadata for direct inputs and are not selectable for generated runs.
Replayed and minimized manifests keep immediate `inputSource` metadata separate
from `originalGenerator` metadata.

## Tree Comparison

The tree renderer follows the html5lib test style used by
`tests/phpunit/tests/html-api/wpHtmlProcessorHtml5lib.php`:

- attributes sorted by their spec-scrubbed names (so a raw-NUL name on the
  WordPress side and its U+FFFD substitution on the DOM side sort identically),
  rendered raw
- boolean attributes rendered as `=""`
- namespace-qualified element and attribute names
- template `content` marker
- only the narrow auto-generated `html/head/body` wrapper tolerance

When the default PHP DOM oracle is selected, template content is rendered
through a self-contained serialization round-trip: PHP hides template child
nodes, so the oracle re-parses the template's `innerHTML` serialization in a
body context and accepts the result only when re-serializing reproduces the
source byte-for-byte. Content that cannot round-trip (table parts, foreign
fragments) is quarantined as `oracle-unsupported`. This check never consults
the WordPress HTML API, which is the system under test.

Raw bytes are rendered without normalization. The WordPress HTML API
deliberately preserves NUL and CR bytes where spec-following parsers
substitute U+FFFD and normalize newlines during input preprocessing, so the
comparison tolerates a differing line only when that exact substitution
explains the entire difference. The tolerance is additionally gated by line
type: WordPress preserves raw bytes only in attribute values and
tag/attribute names (verified empirically across text, RCDATA, rawtext,
foreign text, CDATA, comment, and doctype contexts, where WordPress applies
the spec substitutions itself), so only tag lines and attribute lines are
eligible. A scalar difference on any other line type is a real divergence
and fails. Tolerated lines are reported per seed
(`comparison.scalarToleratedLines`) and per run (`oracleTolerated`), and the
result is classified `oracle-tolerated` rather than silently passed. Any
difference beyond the substitution fails as usual, and the first-difference
record points at the first *unexplained* line.

One known PHP DOM oracle bug is tolerated with a runtime probe: PHP's Lexbor parser
fails to treat U+000C FORM FEED as ignorable whitespace in the pre-body
insertion modes. When a full-document comparison fails, the input contains a
form feed, and re-parsing with form feeds substituted by spaces makes the DOM
oracle reproduce the WordPress tree exactly, the case is classified
`oracle-tolerated` with `comparison.formFeedQuirk: true`. The probe disables
the tolerance automatically when PHP fixes the bug.

Invalid bytes are never normalized away. If WordPress and the selected oracle
surface different byte sequences, the first-difference record includes bounded
line previews, byte lengths, line hashes, the first differing byte offset, and
hex previews, including a diff-window hex preview around the differing byte, so
the mismatch remains inspectable even when JSON display substitutes replacement
characters. Full comparison lines are kept out of `result.json` to avoid large
artifacts from stress inputs.

### Known classification gaps

Two known issues affect labeling, not pass/fail correctness:

- **Dual-axis lines classify as `tree-mismatch`, not `encoding-mismatch`.**
  A differing line explained only by *both* an invalid-UTF-8 substitution and
  a NUL/CR scalar substitution (e.g. an attribute value containing a raw NUL
  *and* a raw `0x82`) matches neither single-axis check:
  `linesMatchAfterWordPressUtf8Scrub` fails on the unscrubbed NUL, and the
  scalar matcher fails on the invalid byte. Such results report
  `tree-mismatch` although encoding is involved.
- **CDATA at SVG/MathML integration points is a real WordPress divergence
  the fuzzer will keep reporting.** For
  `<svg><foreignObject><![CDATA[a\0b]]>` (likewise `<svg><desc>`,
  `<math><mtext>`, HTML-encoded `annotation-xml`), WordPress substitutes
  NUL with U+FFFD in CDATA text while the spec routes those characters
  through the HTML insertion mode, which drops NUL — WordPress handles
  plain text at integration points correctly; only the CDATA path diverges
  (a known `@todo` in `WP_HTML_Tag_Processor`'s CDATA handling). The shape
  is U+FFFD-versus-removed, which no tolerance covers in either direction,
  so these report as genuine `tree-mismatch` findings. This is distinct
  from the upstream-fixed PHP-DOM integration-point reparenting family.

## Minimization

`minimize.php` reduces in three phases under a shared attempt budget
(`--max-attempts`, default 600): markup-aligned segment deletion, binary
byte-chunk deletion, then per-byte deletion and canonicalization (replacements
never grow the input). Every accepted candidate re-runs the worker and must
reproduce the original signature hash (or any failure with `--any-failure`).
