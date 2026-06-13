# Doc-improvement experiment

## Process documents

- `PLAN.md` — experiment contract, corpus rules, model policy, and
  source-edit promotion criteria.
- `PROTOCOL.md` — operational runbook for scored rounds, weak-tier
  calibration, discoverability probes, and shadow-doc A/B tests.
- `NEXT-HYPOTHESES.md` — post-round-17 hypothesis backlog: strong candidates,
  signal-density tests, contrast cards, model ladder, and next sequence.
- `LOG.md` — round-by-round hypothesis and outcome narrative.

## `render-docs-markdown.py`

Deterministic JSON-to-Markdown renderer for phpdoc-parser output. Converts a
parsed PHP class (description, properties, methods, docblock tags) into a single
Markdown file optimized for an LLM agent reading the docs to write code against
the API.

### Usage

```sh
python3 render-docs-markdown.py -i input.json -o output.md
```

- `-i/--input` — phpdoc-parser JSON (array of file objects, each with `classes`).
- `-o/--output` — Markdown file to write (UTF-8, LF line endings).

Standard library only; no dependencies. Python 3.

### Output structure

1. `# H1` class name + file-level description / long description.
2. `## Overview` — class doc, plus extends / implements / final / abstract.
3. `## Method Index` — navigation table (method, visibility, one-line description), source order.
4. `## Properties` — every property (all visibilities) with type from `@var` and description.
5. `## Methods` — one `### method()` per method in source order: PHP-style signature
   (types from `@param` / `@return`), description, long description (HTML converted to
   Markdown), then `@since` / `@param` / `@return` / `@throws` / `@see` / other tags.

Line numbers, `uses` arrays, and `root` / `path` fields are excluded.

### Guarantees and behavior

- **Deterministic:** identical input bytes produce identical output bytes (JSON
  order preserved; no timestamps, no randomness).
- **HTML to Markdown:** an `html.parser`-based converter handles the docblock tag
  inventory (`p`, `br`, `pre`/`code` to fenced PHP, `code`, `em`, `strong`,
  `ul`/`ol`/`li`, `h2`-`h4`, `blockquote`, tables, `a`). Entities are decoded.
- **Schema-drift guard:** an unknown HTML tag aborts loudly via `sys.exit` rather
  than being silently dropped. (`<div>` in example prose is the one tolerated
  non-structural tag and is re-emitted as literal text.)

### Regenerate the sample outputs

```sh
python3 render-docs-markdown.py \
  -i ../artifacts/html-tag-processor.json \
  -o /tmp/html-api-docs-eval-test/html-tag-processor.md

python3 render-docs-markdown.py \
  -i ../artifacts/html-processor.json \
  -o /tmp/html-api-docs-eval-test/html-processor.md
```

<!-- The experiment harness documentation is appended below by a later step. -->

## Round tools

- `tools/audit-state.py` — read-only start-of-run audit for worktree drift,
  latest trusted score, corpus comparability, prepared-round lifecycle, model
  policy, valid current-policy baseline status, and next action.
- `tools/prepare-round.py` — preferred current entry point for a round. It
  stages rendered docs, copies only selected `task.md` prompts into scratch,
  and writes `results/round-NN/round-metadata.json`.
- `tools/verify-scratch-isolation.py` — checks a scratch directory exposes only
  rendered docs and selected task prompts, never references, tests, plans, or
  source files; it can also emit/verify SHA-256 hashes for staged files.
- `tools/source-digests.php` — emits raw-source and comment/whitespace-stripped
  PHP token-stream SHA-256 fingerprints for the two HTML API source files.
- `tools/validate-corpus.py` — runs active corpus `reference.php` files against
  their hidden `tests.json` fixtures and reports harness signal warnings.
- `tools/validate-round.py` — reports whether a round is prepared, partially
  trialed, trial-complete, judged, or scored, verifies recorded scratch hashes,
  verifies recorded source and corpus digests against the current worktree,
  validates trial and judge artifact contents, recomputes metadata-backed
  scored summaries, and lists missing artifacts.
- `tools/workflow-args.py` — emits trials or judges workflow JSON from
  `round-metadata.json` so model policy and task IDs are not transcribed by
  hand; it runs full round validation before emitting launch args, and can emit
  a full launch manifest or atomically write the emitted JSON with `--output`.
- `tools/validate-workflow-output.py` — preflights trials or judges workflow
  JSON envelopes, subject-isolation attestation, round metadata coverage, and
  required payload shape before ingestion writes files.
- `tools/stage-round.sh` — low-level docs-only staging command used by
  `prepare-round.py` and manual scratch variants.
- `tools/persist-trials.py` / `tools/ingest-trials.py` — persist subject
  outputs, persist the runner isolation attestation, and execute candidates
  against hidden tests.
- `tools/ingest-judges.py` / `tools/aggregate-round.py` — persist judge
  verdicts and compute scored summaries.
