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
When a matching current-corpus calibration round is already prepared,
`audit-state.py` reports its lifecycle and the next artifact action: launch
trials, complete trials, run judges, aggregate, or repair/restage.

When corpus fixtures changed since the latest trusted score, verify active
reference implementations before staging or comparing a new round:

```sh
python3 doc-experiment/tools/validate-corpus.py
```

This runs every active `reference.php` against its hidden `tests.json`.
Harness signal records such as unsupported-markup `wp_trigger_error()` events
are reported as warnings by default; use `--strict-signals` when those should
fail a focused audit.

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
no-edit baseline/calibration on the current corpus with the current subject and
judge model policy, then use that result as the current comparison point. The
start-of-run audit treats a baseline as current only when the scored artifacts
validate cleanly and the metadata matches the current task set, subject tier,
and judge tier.

## 1. Stage

```sh
python3 doc-experiment/tools/prepare-round.py <N> \
  --mode weak-tier-calibration
```

This regenerates the rendered docs, copies only the selected tasks'
`task.md` files into `/tmp/html-api-docs-eval/round-NN/tasks/`, and writes
`doc-experiment/results/round-NN/round-metadata.json` with the mode, selected
tasks, trial count, model policy, git head, scratch path, and HTML API source
file digests. It must not copy corpus directories, `reference.php`, or
`tests.json` into scratch. Use `--dry-run` first when reconciling task
selection. The preparation script runs `verify-scratch-isolation.py` before
writing metadata and records SHA-256 hashes for every staged doc and task
prompt. Source digests include both raw source bytes and a comment/whitespace
stripped PHP token-stream fingerprint matching the docs-only guard invariant.
Metadata also records SHA-256 digests for each selected task's `task.md`,
`reference.php`, and `tests.json`; these hidden corpus inputs must not drift
between preparation, execution, judging, and aggregation.
When the worktree is clean, the digest ref is the recorded `git_head`; when
local drift exists, it is `working-tree` and `git_status_short` records the
drift.

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

To inspect the source fingerprints recorded by prepared rounds:

```sh
php doc-experiment/tools/source-digests.php
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

For trusted scored rounds, the preferred runner must enforce the
`docs-test-subject` tool boundary or an equivalent Read+Grep-only boundary. If
that Workflow runner is unavailable, use the local Codex CLI fallback:

```sh
python3 doc-experiment/tools/run-codex-trials.py round-NN \
  --output doc-experiment/results/round-NN/codex-trials-output.json
python3 doc-experiment/tools/validate-workflow-output.py trials \
  doc-experiment/results/round-NN/codex-trials-output.json round-NN
python3 doc-experiment/tools/ingest-trials.py \
  doc-experiment/results/round-NN/codex-trials-output.json round-NN
python3 doc-experiment/tools/validate-round.py round-NN --require-trials-complete
```

The local fallback runs each subject from a private non-repo directory
containing only the two rendered docs, one task prompt, and the output schema,
then embeds the task and rendered docs directly in the subject prompt because
local `codex exec` does not expose the experiment's Read/Grep-only agent tools.
It ignores project rules and user config, uses a read-only sandbox, sets
approval policy `never`, and persists `subject_isolation.isolation_mode` as
`isolated-workdir` with `input_delivery: prompt-embedded-docs`. Scores from
this runner are comparable only with rounds using the same isolation mode and
runner policy. A prompt-only fallback without one of these persisted isolation
attestations remains diagnostic unless transcripts are inspected and the
isolation risk is explicitly recorded.

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

Trusted trials must also persist runner isolation evidence. The trials workflow
returns an object with a `result` array and a `subject_isolation` attestation:

```json
{
  "subject_isolation": {
    "enforced": true,
    "agent_type": "docs-test-subject",
    "allowed_tools": ["Read", "Grep"],
    "notes": "Runner enforced the docs-test-subject tool boundary."
  },
  "result": []
}
```

If a Workflow runner uses an equivalent agent type, `agent_type` may differ,
but `allowed_tools` must still be exactly `Read` and `Grep`, and
`equivalent_boundary_notes` must explain the equivalent enforced boundary. If
the local Codex CLI fallback is used, `allowed_tools` is replaced by the
`isolated-workdir` fields validated by `validate-workflow-output.py` and
`validate-round.py`.
`ingest-trials.py` persists this as `subject-isolation.json`; `validate-round.py`
rejects trial artifacts that lack it. If the workflow runner saves returned
values under a top-level `result` key, `validate-workflow-output.py` and
`ingest-trials.py` also accept `{ "result": { "subject_isolation": ..., "result": [...] } }`.

For the bundled workflow script, generate the task list and model policy from
the round metadata:

```sh
python3 doc-experiment/tools/workflow-args.py trials round-NN
```

The bundled trials workflow passes `agent_type: docs-test-subject` to each
subject `agent()` call. This command verifies the staged scratch directory,
recorded file hashes, selected corpus references, and round preflight before
emitting agent-launch arguments. If `/tmp` was cleaned, a staged file changed,
selected corpus inputs drifted, or a selected reference no longer passes its
hidden tests, restage or reconcile the round rather than launching subjects
against mismatched docs or fixtures.
The escape hatch is named `--skip-round-check` because it bypasses all staged
round artifact and selected-corpus checks, not only scratch isolation; use it
only for diagnostics.
To emit both trial and judge workflow inputs plus the ingest/validation command
sequence as a single handoff object, run:

```sh
python3 doc-experiment/tools/workflow-args.py manifest round-NN
```

The manifest includes launch provenance: current git head/status, the prepared
round metadata git head/status, and SHA-256 hashes for the bundled trial and
judge workflow scripts. Persist the manifest or equivalent values with the
external runner handoff so tooling-only commits can be distinguished from the
staged rendered-doc/corpus state. Its preflight commands validate exactly the
selected tasks recorded in round metadata; they are intentionally not split
shortcuts such as `--split train`.
Use `--output <path>` to write the emitted trials, judges, or manifest JSON to
a handoff file while still printing it to stdout.

For `discoverability-probe`, replace the implementation prompt with a
question-answer prompt requiring: answer, cited markdown file/heading, and
one-sentence rationale. Do not execute code or expose hidden tests.

If the Workflow runner is unavailable, use the local Codex CLI probe fallback:

```sh
python3 doc-experiment/tools/run-codex-probes.py round-NN \
  --question-id <stable-id> \
  --question '<citation-only question>' \
  --output doc-experiment/results/probes/round-NN-<stable-id>.json
```

The local fallback runs each probe subject from a private non-repo directory,
embeds only the staged rendered docs and probe question in the prompt, ignores
project rules and user config, uses a read-only sandbox, and sets approval
policy `never`. Persist the probe output with the result artifacts and log
whether the subject found the relevant local contract.

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
`persist-trials.py` refuses to persist a trial if the harness output is not
valid execution JSON with `passed`, `total`, and `cases`; artifacts created for
that failed ingest attempt are removed before the ingest exits non-zero, so a
mid-batch harness failure does not leave partial trial artifacts behind.
After `persist-trials.py` succeeds, `ingest-trials.py` writes
`subject-isolation.json` atomically; if that write fails, it removes the trial
directories from the current ingest attempt before exiting non-zero.

For metadata-backed rounds, `ingest-trials.py` rejects workflow outputs whose
task IDs, trial numbers, or structured-output fields do not match
`round-metadata.json`. Trial entries must include non-empty `code` and
`explanation` strings plus integer `confidence` 0-100, and `code` must be a
complete PHP file starting with `<?php`. Incomplete or malformed agent
responses are rejected before result files are written; ingestion does not
repair subject code. Malformed workflow envelopes, missing or invalid
`subject_isolation` attestations, non-array `result` payloads, and non-object
trial entries are rejected before ingestion reads or persists the payload. You
can run the same
preflight without writing files:

```sh
python3 doc-experiment/tools/validate-workflow-output.py trials \
  <trials-output.json> round-NN
```

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

This performs the same scratch/hash preflight because judges must see the exact
rendered docs that subjects saw, and it revalidates the selected corpus
references before judge launch.

If the Workflow runner is unavailable, use the local Codex CLI judge fallback:

```sh
python3 doc-experiment/tools/run-codex-judges.py round-NN \
  --output doc-experiment/results/round-NN/codex-judges-output.json
python3 doc-experiment/tools/validate-workflow-output.py judges \
  doc-experiment/results/round-NN/codex-judges-output.json round-NN
python3 doc-experiment/tools/ingest-judges.py \
  doc-experiment/results/round-NN/codex-judges-output.json round-NN
python3 doc-experiment/tools/validate-round.py round-NN --require-scored
```

The local judge runner uses the same judge model policy, runs from the
repository root under a read-only sandbox, ignores project rules and user
config, and writes the same judge workflow-output shape consumed by
`ingest-judges.py`.

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

For metadata-backed rounds, judge workflow preflight rejects missing task
coverage, missing trial verdicts, non-integer adherence, non-string
hallucinated method entries, empty trial notes, empty failure analysis, and
empty doc-gap fields before any `judge.json` or `round-summary.json` is
written. Malformed workflow envelopes, non-array `result` payloads, and
non-object judge entries are rejected before ingestion reads or persists the
payload.

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
For metadata-backed rounds, validation also checks that staged scratch files
still match the SHA-256 hashes recorded at preparation time and that recorded
HTML API source digests match both their recorded git ref and the current
worktree. It also checks the current selected task prompts, references, and
hidden tests against the corpus file digests recorded at preparation time.
Trial artifacts are content-validated before a round can be considered
trial-complete:
`candidate.php` must be non-empty PHP, `response.json` must contain the
subject explanation/confidence shape, and `execution.json` must contain the
harness pass/total/cases shape. Persisted `judge.json` artifacts are
content-validated before a round can be considered judged or scored: every
expected trial must have an adherence score, hallucinated-method list, and
non-empty notes, and the task verdict must include non-empty failure analysis
plus structured doc-gap entries.
Lifecycle counts in `validate-round.py` include only valid artifacts; a
present but malformed `candidate.php`, `response.json`, `execution.json`, or
`judge.json` keeps the round incomplete and must be reconciled before
advancing.
`ingest-judges.py` validates trial completeness before writing judges and
judged-state completeness before writing a summary. It also preflights judge
workflow output shape:

```sh
python3 doc-experiment/tools/validate-workflow-output.py judges \
  <judges-output.json> round-NN
```

`aggregate-round.py` refuses metadata-backed rounds with missing judges,
missing trial executions, or mismatched task sets. For metadata-backed scored
rounds, `validate-round.py --require-scored` recomputes the aggregate and
rejects a `round-summary.json` that no longer matches the persisted trial
executions, judge verdicts, metadata, and current corpus labels.
Trial and judge ingestion refuse to overwrite existing trial directories,
`subject-isolation.json`, `judge.json`, or `round-summary.json`. If an ingest
must be retried after a failed or invalid runner output, first record the
reconciliation in `LOG.md`, remove or quarantine the invalid artifacts
deliberately, and then rerun ingestion.
Judge ingestion removes artifacts it created in the current attempt if judge
writing, post-write validation, aggregation, or summary writing fails.

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
  subject-isolation.json     # runner-enforced docs-test-subject boundary attestation
  <task-id>/
    trial-1/candidate.php
    trial-1/response.json    # explanation + confidence as returned
    trial-1/execution.json
    judge.json
  round-summary.json         # aggregate-round.py output
```
