# Round protocol

Operational runbook for one evaluation round. Keep in sync with PLAN.md.

## 0. Choose round mode and model tier

Start every run with the read-only state audit:

```sh
python3 doc-experiment/tools/audit-state.py
```

If it reports local drift, corpus/result mismatch, source-doc changes since the
last trusted score, or missing current-corpus baseline, resolve that state
before trusting any new score.

Use `priority` service tier for every Codex agent when available.

Judges always use `gpt-5.5` / `xhigh` / `priority` when available. If this
is unavailable, pause or explicitly record the downgrade.

Test subjects use one primary tier per scored round:

1. `gpt-5.4` / `medium` / `priority`
2. `gpt-5.4` / `low` / `priority`
3. `gpt-5.4-mini` / `high` / `priority`
4. `gpt-5.4-mini` / `low` / `priority`

Do not mix subject tiers into the main round score. Before a new tier drives
source edits, run a no-edit baseline for that tier.

Pick exactly one round mode:

- `scored-train`: primary tier on train tasks only; this is the normal edit
  feedback loop.
- `checkpoint`: primary tier on train plus held-out; held-out is a regression
  sentinel and never drives edits.
- `weak-tier-calibration`: current docs, no edits, one candidate tier at a
  time; selects the next measuring instrument.
- `discoverability-probe`: citation-only questions against rendered docs; no
  hidden tests and no source edits.
- `shadow-doc-a/b`: compare normal rendered docs against a scratch-only
  rendered variant, such as contract cards or pruning. Source docblocks are not
  edited until a variant wins and is promoted as its own hypothesis.

If the active corpus has changed since the last trusted score, do not compare
against that older score and do not promote source docblock edits. First run a
no-edit baseline/calibration on the current corpus with the current model
policy, then use that result as the current comparison point.

## 1. Stage

```sh
python3 doc-experiment/tools/prepare-round.py <N> \
  --mode weak-tier-calibration
```

This regenerates the rendered docs, copies only the selected tasks'
`task.md` files into `/tmp/html-api-docs-eval/round-NN/tasks/`, and writes
`doc-experiment/results/round-NN/round-metadata.json` with the mode, selected
tasks, trial count, model policy, git head, and scratch path. It must not copy
corpus directories, `reference.php`, or `tests.json` into scratch. Use
`--dry-run` first when reconciling task selection. The preparation script runs
`verify-scratch-isolation.py` before writing metadata and records SHA-256
hashes for every staged doc and task prompt.

`stage-round.sh <N>` remains the low-level docs-only staging command for
manual scratch variants and shadow-doc A/B setup.

For a manually edited scratch variant, run:

```sh
python3 doc-experiment/tools/verify-scratch-isolation.py <scratch> \
  --task-id T01-add-image-class
```

If docs were edited since the last round, first run the docs-only guard:

```sh
php doc-experiment/tools/docs-only-guard.php
```

For `shadow-doc-a/b`, stage normal docs first, then copy the staged directory
to a variant scratch directory and apply rendered-markdown-only changes there.
Do not edit source docblocks for the variant. Record the variant name in the
result directory and judge prompts.

## 2. Test-subagent prompt template

One agent per task-trial; agent type `docs-test-subject` (Read+Grep only,
defined in `.claude/agents/`); use the selected primary subject tier from
section 0; 3 trials per task unless a weaker tier needs 5 trials to reduce
variance. Note: agent definitions register at session start — in a session
older than the definition, fall back to a general agent with the
prompt-level restrictions below and spot-check transcripts for isolation
violations. Substitute `{SCRATCH}` and `{TASK_MD}`:

````text
You are implementing a PHP function for WordPress using the HTML API.

Your ONLY sources of information about the API are these two
documentation files:

- {SCRATCH}/html-tag-processor.md
- {SCRATCH}/html-processor.md

Strict rules: do not read any other file; do not run code; do not rely on
memory of WordPress source code — if the documentation contradicts your
memory, trust the documentation. Methods not documented in those files do
not exist.

THE TASK:

{TASK_MD}

Respond with your final answer in exactly this structure (the code block
must contain a complete PHP file defining exactly the requested function):

```php
<?php
// implementation
```

EXPLANATION: one short paragraph describing your approach and which
documented APIs you used.

CONFIDENCE: an integer 0-100 — your confidence the implementation passes
a strict behavioral test suite.
````

When orchestrating via the Workflow tool, prefer `schema` structured
output with fields `code` (string), `explanation` (string), `confidence`
(integer 0-100) instead of free-text parsing.

For the bundled workflow script, generate the task list and model policy from
the round metadata:

```sh
python3 doc-experiment/tools/workflow-args.py trials round-NN
```

For `discoverability-probe`, replace the implementation prompt with a
question-answer prompt requiring: answer, cited markdown file/heading, and
one-sentence rationale. Do not execute code or expose hidden tests.

## 3. Execute

For each trial, write the returned code to
`results/round-NN/<task>/trial-<n>/candidate.php`, then:

```sh
php doc-experiment/harness/run-tests.php \
  results/round-NN/<task>/trial-<n>/candidate.php \
  doc-experiment/corpus/<task>/tests.json \
  > results/round-NN/<task>/trial-<n>/execution.json || true
```

(`run-tests.php` exits non-zero on failures; the JSON is still complete.)

For metadata-backed rounds, `ingest-trials.py` rejects workflow outputs whose
task IDs or trial numbers do not exactly match `round-metadata.json`.

Skip this section for `discoverability-probe` rounds. For `shadow-doc-a/b`,
execute control and variant candidates separately and keep result directories
clearly labeled.

## 4. Judge prompt template

One `gpt-5.5` / `xhigh` / `priority` judge per task. The judge receives: the
task directory contents (task.md, reference.php, tests.json), every `trial-N`
directory for that task (candidate.php, explanation, confidence,
execution.json), and the two rendered markdown docs the subagents saw. The
judge may read the html-api source and run ad-hoc probes with the harness
bootstrap.

For the bundled judge workflow script, generate args from the same metadata:

```sh
python3 doc-experiment/tools/workflow-args.py judges round-NN
```

The judge returns JSON:

```json
{
  "trials": [
    {
      "trial_id": "trial-1",
      "adherence": 0,
      "hallucinated_methods": [],
      "notes": "…"
    }
  ],
  "failure_analysis": "Which misunderstandings caused failures, citing the docs passages (or absences) responsible.",
  "doc_gaps": [
    { "location": "method or section", "problem": "…", "suggestion": "…" }
  ]
}
```

Adherence rubric (0-100): correct processor choice for the job (30),
no hallucinated/undocumented API usage (30), idiomatic use of documented
patterns — bookmarks, breadcrumbs, token walking (25), graceful handling
of edge cases the docs describe (15). Execution results measure
correctness separately; adherence is about HOW the API was used.

For held-out tasks, judges may report regressions but their `doc_gaps` must be
tagged `held-out-only` and must not drive source edits unless the same issue
has train or probe evidence.

For `shadow-doc-a/b`, ask judges to compare whether the variant changed
failure modes, hallucinated methods, local citations, or unnecessary fallback
branches. A variant "wins" only if it improves concept-level behavior or
discoverability without a clean regression.

## 5. Aggregate and record

Before aggregation, validate result completeness:

```sh
python3 doc-experiment/tools/validate-round.py round-NN
```

It should report `judged` before aggregation. After aggregation, rerun it with
`--require-scored`; it should report `scored` before the score is trusted.
`ingest-judges.py` validates trial completeness before writing judges and
judged-state completeness before writing a summary. `aggregate-round.py`
refuses metadata-backed rounds with missing judges, missing trial executions,
or mismatched task sets.

```sh
python3 doc-experiment/tools/aggregate-round.py doc-experiment/results/round-NN
```

Record in LOG.md: round score, per-task scores, judge doc_gaps summary.
Commit results. For normal scored rounds, make source doc edits only when the
evidence supports a general hypothesis; commit one hypothesis at a time,
re-run the docs-only guard, and stage the next round. For calibration,
discoverability, or shadow-doc rounds, record the outcome and whether any
variant should be promoted; do not commit source docblock changes as part of
the same hypothesis.

Before committing a source documentation hypothesis that includes examples,
verify the examples through `doc-experiment/harness/bootstrap.php` where
applicable.

## Operational hazards

- Workflow `args` may arrive as a JSON string; orchestration scripts should
  parse defensively.
- Strong-judge session limits can kill a judge fan-out, sometimes returning an
  empty result set with failures listed. Trial executions are already
  persisted, so relaunch judges after reset rather than rerunning trials.
- Expected outputs are frozen. Regenerate them only when a reference
  implementation intentionally changes, and review the diff before trusting the
  new fixtures.
- Historical logs may use legacy labels such as "opus", "sonnet", or "haiku".
  Treat those as historical role labels, not current model choices.

## Storage layout

```
doc-experiment/results/round-NN/
  <task-id>/
    trial-1/candidate.php
    trial-1/response.json    # explanation + confidence as returned
    trial-1/execution.json
    judge.json
  round-summary.json         # aggregate-round.py output
```
