# HTML API Autonomous Documentation Improvement

Improve the documentation of `WP_HTML_Tag_Processor` and `WP_HTML_Processor`
(docblocks in the two class files) by iteratively measuring how well weaker
models can complete real HTML API tasks using *only* the rendered
documentation, then editing the docs to fix observed failure modes.

## Pipeline (per round)

1. Regenerate parsed-doc JSON (script lives in the phpdoc-parser checkout;
   must be invoked by absolute path):

   ```sh
   php /Users/jonsurrell/a8c/phpdoc-parser/generate-json-manually.php \
     -d src/wp-includes/html-api/class-wp-html-tag-processor.php \
     -o artifacts/html-tag-processor.json
   php /Users/jonsurrell/a8c/phpdoc-parser/generate-json-manually.php \
     -d src/wp-includes/html-api/class-wp-html-processor.php \
     -o artifacts/html-processor.json
   ```

   (Harmless P2P_Autoload deprecation warnings are expected on stderr.)

2. Render deterministic markdown from the JSON:

   ```sh
   python3 doc-experiment/render-docs-markdown.py -i artifacts/html-tag-processor.json -o <scratch>/html-tag-processor.md
   python3 doc-experiment/render-docs-markdown.py -i artifacts/html-processor.json     -o <scratch>/html-processor.md
   ```

   The renderer fails loudly on unknown HTML tags (schema drift guard) and is
   byte-deterministic. It excludes line numbers and `uses` arrays
   (implementation leakage).

3. Copy ONLY the two markdown files into a fresh scratch directory outside the
   repo (e.g. `/tmp/html-api-docs-eval/round-NN/`). Test subagents are given
   those two absolute paths and never learn the repo location.

4. Run the train set: 12 tasks × 3 independent test-subagent trials
   (Sonnet initially; Haiku after the Sonnet plateau). One fresh subagent per
   task-trial, run in parallel. Test subagents get Read + Grep only, the task
   prompt, and the two markdown paths. They MUST NOT access any other
   information source or execute code. Their deliverable: PHP code +
   explanation + self-reported confidence. Spot-check transcripts for
   isolation violations each round.

5. Execute every trial's code in the standalone harness against the task's
   hidden test cases (deterministic pass/fail per case, recorded before
   judging).

6. Judge: one Opus judge per task sees the task spec, reference
   implementation, hidden-test execution results for all 3 trials, the
   markdown docs the subagents saw, and full source access. It scores each
   trial and writes a failure analysis: which doc gap or misleading passage
   caused each failure.

7. Analyze failures, form doc-edit hypotheses, edit docblocks, commit
   (one commit per hypothesis), regenerate, next round.

## Scoring

- Per-trial: 70% functional correctness (fraction of hidden test cases
  passed) + 30% API adherence rubric (no hallucinated methods, correct
  processor choice, idiomatic handling of malformed HTML, no
  `_doing_it_wrong` triggers).
- Task score = mean of 3 trials; round score = mean over 12 train tasks.
  Scale 0–100.
- Revert rule: revert a hypothesis commit if the next round's score drops
  more than 2 points, or a previously passing task regresses across all
  trials. Neutral edits that are qualitatively sound are kept.

## Corpus

Revised after Jon's round-1 review (task-first, not API-surface-first):
19 active tasks — 15 train + 4 held-out. Held-out tasks are scored only at
checkpoints (every 3rd round and at the end) and never drive doc edits —
they detect doc edits that game the train set.

- Train core: T03–T12 (text extraction, traversal, serialization,
  bookmarks) plus N03 (incomplete-input detection via
  paused_at_incomplete_token), N04 (normalize() failure handling),
  N06 (HTML img vs SVG image namespace distinction).
- Train smoke: T01, T02 — basic sanity checks, kept in the round score
  but reviewed separately; they must not dominate coverage.
- Held-out: N01 (class removal), N02 (contextual selection with
  breadcrumbs), N05 (full-document title via create_full_parser),
  H04 (advanced subtree text extraction).
- Retired to corpus-retired/ (too close to train patterns to give
  held-out anti-overfitting value): H01, H02, H03.

Every active task carries labels in tests.json — role (core/smoke), commonness
(high/medium/low), concept (attributes, classes, text, traversal,
serialization, full-document, failure-handling, namespace), and intended
processor (tag/html/either). Rounds are reviewed per concept, not only by
aggregate score, so a high aggregate cannot hide an untaught concept.

Sources of task patterns: dmsnell's gists (HTML serialization builder,
streaming html-grep, semantic truncation) adapted to the *current* API on
this branch — the gists use experimental methods that don't exist here —
plus common content workflows: class manipulation, contextual selection,
truncated-input detection, normalization failure, full-document parsing,
namespace distinction. Most tasks do not name which processor class to
use; choosing correctly is part of what the docs must teach. Every task
ships: prompt, function signature, reference implementation, hidden test
cases. All references must pass their hidden tests in the harness, and
extraction tasks are cross-checked against PHP's Dom\HTMLDocument oracle,
before they enter a round.

## Execution harness

Standalone PHP CLI harness (no WordPress boot, no DB): requires the html-api
source files directly plus small shims — real `utf8.php`, copied
`wp_kses_uri_attributes()`, identity `__()`, recording `_doing_it_wrong()`
(its triggering is an adherence signal), minimal `esc_url()` that performs
HTML escaping but no protocol filtering or URL normalization. Candidate and
reference both run under the same harness so shim divergence cancels out.
Tasks are authored to avoid protocol-filtering-sensitive expectations.

## Round flow & stopping

- Round 0 scores the unmodified docs (baseline/control) after corpus
  approval.
- Docs-only guard each round: PHP token stream with comments stripped must
  be identical before/after edits; `php -l` passes; `@since` tags untouched;
  no fabricated changelog entries. Free restructuring of docblock content is
  otherwise allowed (file-, class-, property-, method-level, both files).
- Docs are free-form: optimized purely for scores, not for WP documentation
  standards (upstreaming is a later, separate concern).
- Switch Sonnet → Haiku when the Sonnet train score is ≥90 for 2 consecutive
  rounds (re-baseline with Haiku before further edits).
- Stop when 2 consecutive Haiku rounds show no significant gain, or on
  Jon's interrupt.

## Repo layout

- `doc-experiment/PLAN.md` — this contract; update it when the design
  changes.
- `doc-experiment/render-docs-markdown.py` — JSON→markdown renderer.
- `doc-experiment/corpus/` — task specs, reference implementations, hidden
  test cases (never exposed to test subagents).
- `doc-experiment/harness/` — standalone PHP execution harness.
- `doc-experiment/results/round-NN/` — scores, per-task judge analyses.
- `doc-experiment/LOG.md` — running hypothesis → outcome narrative.
- `artifacts/` — generated JSON (gitignored; regenerated every round).

## Autonomy

After corpus approval the loop runs autonomously round-to-round. After each
round a summary is posted (scores, deltas, hypotheses, commits) for
asynchronous review; held-out checkpoints every 3rd round gate continuation.
