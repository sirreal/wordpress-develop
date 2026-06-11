# Round protocol

Operational runbook for one evaluation round. Keep in sync with PLAN.md.

## 1. Stage

```sh
sh doc-experiment/tools/stage-round.sh <N>   # prints /tmp/html-api-docs-eval/round-NN
```

If docs were edited since the last round, first run the docs-only guard:

```sh
php doc-experiment/tools/docs-only-guard.php
```

## 2. Test-subagent prompt template

One agent per task-trial; agent type `docs-test-subject` (Read+Grep only,
defined in `.claude/agents/`); model `sonnet` (later `haiku`); 3 trials per
task. Note: agent definitions register at session start — in a session
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

## 4. Judge prompt template

One Opus judge per task. The judge receives: the task directory contents
(task.md, reference.php, tests.json), all three trials (candidate.php,
explanation, confidence, execution.json), and the two rendered markdown
docs the subagents saw. The judge may read the html-api source and run
ad-hoc probes with the harness bootstrap.

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

## 5. Aggregate and record

```sh
python3 doc-experiment/tools/aggregate-round.py doc-experiment/results/round-NN
```

Record in LOG.md: round score, per-task scores, judge doc_gaps summary.
Commit results, then make doc edits (one commit per hypothesis), re-run
the guard, and stage the next round.

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
