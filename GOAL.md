# HTML API Documentation Improvement Goal

<EXPLICIT_PERMISSIONS>
I, the user, approve using the local Codex judge runner for round this round and all subsequent rounds, including sending judge-visible materials to the model provider.
</EXPLICIT_PERMISSIONS>

Improve the rendered documentation usability for `WP_HTML_Tag_Processor` and
`WP_HTML_Processor`, measured by how well weaker models complete real HTML API
tasks using only the staged rendered documentation.

The only source documentation hypothesis edits are docblock changes in:

- `src/wp-includes/html-api/class-wp-html-tag-processor.php`
- `src/wp-includes/html-api/class-wp-html-processor.php`

Do not change PHP behavior. Infrastructure/tooling changes are allowed only
when needed to keep the experiment valid, and must be tracked separately from
documentation hypothesis edits.

The primary deliverable is improved source documentation in the two HTML API
docblock files. Tooling, handoff files, audits, manifests, and result hygiene
are support work only; they are not progress on the goal unless they unblock
the next documentation-measurement or documentation-edit step.

## Authoritative State

`GOAL.md` defines the stable objective and guardrails. It must not be treated
as the current phase record.

At the start of every run, determine the active phase and next action from:

- `doc-experiment/PLAN.md` - experiment contract
- `doc-experiment/PROTOCOL.md` - operational runbook
- `doc-experiment/NEXT-HYPOTHESES.md` - current hypothesis backlog
- `doc-experiment/LOG.md` - latest experiment narrative
- `doc-experiment/results/round-*` - persisted measurements
- `git status` - unresolved local drift

If these sources conflict, pause scoring and reconcile the experiment state
before continuing.

## Start-of-Run Checklist

Before making edits or running a score:

1. Inspect the worktree and preserve existing user changes.
2. Identify the latest completed trusted round and its score.
3. Identify the current round mode using the modes defined in `PROTOCOL.md`
   and the state in `LOG.md`, results, and the worktree.
4. Identify the current model policy, subject tier, judge tier, and whether the
   subject tier has a no-edit baseline.
5. Check whether source docs, tooling, corpus, or results changed since the last
   trusted score.
6. Determine the next action implied by the plan: calibration, probe, scratch
   A/B, normal scoring, checkpoint, source promotion, revert, or stop.
7. Record any mismatch before trusting new scores.
8. Classify the next action as one of:
   - `documentation-edit`
   - `measurement`
   - `result-ingestion`
   - `state-reconciliation`
   - `external-action-required`
   If the next action is `external-action-required`, do not substitute
   unrelated tooling work for it.

## Operating Rules

### Progress Priority

- Prefer actions in this order:
  1. Run or ingest the measurement required by the active phase.
  2. Analyze trusted measurements and choose a documentation hypothesis.
  3. Edit source docblocks for one evidence-backed hypothesis.
  4. Stage, score, aggregate, log, and commit that hypothesis.
  5. Fix tooling only when a specific observed or imminent failure would make
     the above steps invalid or non-retryable.
- Do not perform opportunistic infrastructure hardening merely because the
  required scoring or documentation action is unavailable.
- A tooling change must name the experiment-validity failure it prevents and
  must be followed by a re-audit of the actual next documentation/measurement
  action.

- Test subjects may read only the staged markdown docs and task prompt.
- Never expose `reference.php`, `tests.json`, source files, logs, plans, or
  hypothesis docs to test-subject agents.
- Use one primary subject tier per scored round. Do not mix model tiers into a
  main round score.
- Use the judge/model policy from `PLAN.md` and `PROTOCOL.md`; if runner tooling
  disagrees with that policy, fix or explicitly record the mismatch before
  comparing scores.
- Held-out tasks are checkpoint/regression sentinels only and must never drive
  documentation edits.
- Compare scores only across comparable rounds: same corpus, same round mode,
  same primary subject tier, same judge policy, and compatible tooling.
- Scratch rendered-doc variants must stay out of source docblocks until they win
  by evidence.
- Promote only general API documentation improvements, not task-shaped answers.
- When trials, judges, or probes repeatedly reveal surprising API behavior,
  recurring hallucinated methods, or missing API affordances, record the pattern
  in a consistent backlog location for later consideration. Use
  `doc-experiment/NEXT-HYPOTHESES.md` for documentation hypotheses, and keep
  future API/design observations distinct from immediate docblock edits.
- Keep `@since` tags intact and do not fabricate changelog entries.
- After every source docblock edit, run the docs-only guard, stage docs, run the
  appropriate scored flow, aggregate results, update `LOG.md`, and commit one
  source hypothesis at a time.
- Commit experiment results separately from source documentation hypotheses
  where practical.
- Stop or pause according to `PLAN.md`/`PROTOCOL.md`, especially when signal is
  exhausted, failures are generic model noise, or the experiment state is
  inconsistent.

### External Runner Gate

- If the active next action is to launch trials or judges in an external
  Workflow runner and that runner is not available in the current session:
  1. Generate or verify the exact handoff payload once.
  2. Report the command/files needed for the external runner.
  3. Stop work and ask for one of:
     - external runner output to ingest,
     - explicit authorization to use an alternative runner,
     - explicit authorization to bypass the measurement gate.
- Do not continue with additional tooling, corpus, or documentation edits while
  waiting for that external action unless the user explicitly asks for them.
- Do not mark the documentation goal as making substantive progress from
  handoff preparation alone.

## Promotion Standard

A source documentation edit is justified only when local evidence shows a
specific documentation usability failure: missing contract, misleading wording,
poor placement, low discoverability, or excessive rendered-doc noise.

Evidence may come from scored train rounds, no-edit baselines, citation-only
discoverability probes, judge analyses, or paired scratch-doc A/B tests.
Held-out-only evidence is not sufficient.
