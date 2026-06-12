# HTML API autonomous documentation improvement

## Goal

Improve the usability of `WP_HTML_Tag_Processor` and `WP_HTML_Processor` (only `src/wp-includes/html-api/class-wp-html-{tag-,}processor.php`), measured by how well weaker models complete real tasks using **only** rendered documentation. Full design contract: `doc-experiment/PLAN.md`. Runbook with exact prompts and judge rubric: `doc-experiment/PROTOCOL.md`. Narrative so far: `doc-experiment/LOG.md`. Post-round-17 hypotheses and diagnostic sequence: `doc-experiment/NEXT-HYPOTHESES.md`.

Use Codex model settings directly, not legacy opus/sonnet/haiku labels. Judges are always `gpt-5.5` / `xhigh` / `priority` when available. Test subjects use one primary tier per scored round, stepping down only after no-edit calibration: `gpt-5.4`/`medium`/`priority`, `gpt-5.4`/`low`/`priority`, `gpt-5.4-mini`/`high`/`priority`, then `gpt-5.4-mini`/`low`/`priority`.

## Pipeline (one round)

1. `php doc-experiment/tools/docs-only-guard.php` — must pass after any doc edit (comment-stripped token stream identical to HEAD, `php -l`, `@since` untouched).
2. `sh doc-experiment/tools/stage-round.sh <N>` — regenerates `artifacts/*.json` via `/Users/jonsurrell/a8c/phpdoc-parser/generate-json-manually.php` (absolute path required), renders markdown, stages `/tmp/html-api-docs-eval/round-NN/` containing only the two `.md` docs. Then copy each active task's `task.md` to `<scratch>/tasks/<task-id>.md`.
3. Trials: use one selected primary subject tier for the scored round; do **not** mix model tiers into the main score. Example shape: `Workflow({scriptPath: "doc-experiment/tools/trials-workflow.js", args: {scratch, taskIds: [...], trialsPerTask: 3, model: "gpt-5.4", reasoningEffort: "medium", serviceTier: "priority"}})` if the workflow supports those fields. Use agent type `docs-test-subject` (`.claude/agents/`, Read+Grep only) — it registers in fresh sessions. Test subjects may read only scratch files; never expose `reference.php`/`tests.json`; spot-check transcripts for external reads.
4. `python3 doc-experiment/tools/persist-trials.py doc-experiment/results/round-NN < trials.json` — writes candidates and executes each against hidden tests in the standalone harness (`doc-experiment/harness/`, subprocess isolation, 10s timeout).
5. Judges: `Workflow({scriptPath: "doc-experiment/tools/judge-workflow.js", args: {repoRoot, round: "round-NN", scratch, taskIds, model: "gpt-5.5", reasoningEffort: "xhigh", serviceTier: "priority"}})` if supported — one strongest judge per task; persist `judge.json` per task.
6. `python3 doc-experiment/tools/aggregate-round.py doc-experiment/results/round-NN` → `round-summary.json`. Review **per-concept**, not just aggregate.
7. Update `LOG.md`, commit results, then choose the next action. Post-round-17 default is diagnostic first: no-edit weak-tier calibration, citation-only discoverability probes, scratch-rendered A/B variants, then source docblock edits only after evidence. Source edits still require one commit per hypothesis (verify every doc example by execution first; probe via `php -r 'require "doc-experiment/harness/bootstrap.php"; …'`).

## Rules

- Score: trial = 0.7·(pass fraction·100) + 0.3·adherence; task = mean of 3 trials; round = mean over tasks.
- Revert a hypothesis commit on >2-point round drop or a clean task regression.
- Held-out tasks (N01, N02, N05, H04) run only on checkpoint rounds (every 3rd + final) and **never drive doc edits**.
- Do not run every agent tier against held-out every round. Holdout is for primary-tier checkpoint/final rounds or diagnostic sentinels only; if seen in cross-tier panels, it still must not drive edits.
- Train = T01–T12 (T01/T02 are smoke) + N03, N04, N06. Retired: `corpus-retired/H01–H03`.
- Stop/pause after 2 consecutive flat rounds on the selected weak tier, when diagnostic A/B tests stop producing concept-level signal, or on Jon's interrupt. Report after every round.

## Known operational hazards

- Workflow `args` may arrive as a string — scripts already parse defensively.
- Strong-judge session limits can kill a judge fan-out (returns `[]` with failures listed); just relaunch after reset — trial executions are already persisted and nothing is lost.
- Expected outputs are frozen; regenerate (`--generate`) only when a reference intentionally changes, and review the diff.

## Vocabulary

- Legacy logs may say "opus", "sonnet", or "haiku"; treat those as historical role labels, not current model choices.
- Current judges: `gpt-5.5` / `xhigh` / `priority`.
- Current test-subject ladder: `gpt-5.4` / `medium`, `gpt-5.4` / `low`, `gpt-5.4-mini` / `high`, `gpt-5.4-mini` / `low`, all on `priority` when available.
