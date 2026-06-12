I found the plan under `doc-experiment/`. The work is an experiment to improve only the docblocks for `WP_HTML_Tag_Processor` and `WP_HTML_Processor`, measured by how well weaker models solve hidden HTML API tasks using only rendered docs.

Current state: round 17 hit `98.93` train with no edits, so the next phase should not blindly add doc prose. It should run diagnostics: weaker-tier calibration, discoverability probes, scratch A/B rendered-doc variants, then promote only winning changes to source docblocks.

Use this goal prompt:

```text
Continue the HTML API autonomous documentation improvement experiment in this repo.

Start by reading and internalizing:
- doc-experiment/PLAN.md
- doc-experiment/PROTOCOL.md
- doc-experiment/NEXT-HYPOTHESES.md
- doc-experiment/LOG.md
- HANDOFF.md if present

Objective: improve the rendered documentation usability for WP_HTML_Tag_Processor and WP_HTML_Processor, but only by evidence-driven docblock changes to:
- src/wp-includes/html-api/class-wp-html-tag-processor.php
- src/wp-includes/html-api/class-wp-html-processor.php

Current phase: post-round-17 diagnostics. Do not start by making source edits. Round 17 train score was 98.93; the main remaining signal is discoverability/placement/signal-density, especially T08 traversal and the depth-boundary equivalence issue.

Before scoring anything, check the current worktree and preserve existing user changes. Also reconcile the runner tooling with the current model policy: the plan says judges should be gpt-5.5/xhigh/priority and subjects should use one tier at a time from the ladder, but the workflow scripts may still contain legacy opus/haiku labels or hardcoded model values. Fix or explicitly record any tooling mismatch before trusting new scores.

Run the next phase in this order:

1. No-edit weak-tier calibration on train tasks only, one subject tier at a time:
   - gpt-5.4 / medium / priority
   - gpt-5.4 / low / priority
   - gpt-5.4-mini / high / priority
   - gpt-5.4-mini / low / priority
   Use one primary tier per scored run. Do not mix tiers into one round score. Pick the weakest tier that is not saturated but still mostly fails on HTML API documentation reasoning, not generic coding mistakes.

2. Run citation-only discoverability probes for the contracts in NEXT-HYPOTHESES.md:
   depth-boundary equivalence, factory lifecycle, where text lives, get_updated_html vs serialize vs serialize_token, breadcrumbs includes current node, next_tag opener default, namespace/parsed identity, normalize attribute order, paused_at_incomplete_token semantics.
   These probes should require answer + cited markdown file/heading. No code execution and no hidden tests.

3. Build scratch-only rendered-doc variants. Do not edit source docblocks for these variants. Test at least:
   - depth-boundary equivalence card near next_token()/get_current_depth()
   - factory lifecycle contract near create_fragment()/create_full_parser()
   - where-text-lives matrix near get_token_type()/get_modifiable_text()
   - method-heading contract cards
   - signal-density pruning/relocation
   Run paired shadow-doc A/B tests against the selected primary tier.

4. Promote only winning variants to source docblocks, one hypothesis per commit. Each promoted edit must be general API documentation, not a task-shaped answer. Verify examples by executing probes through doc-experiment/harness/bootstrap.php where applicable.

5. After each source docblock edit:
   - run php doc-experiment/tools/docs-only-guard.php
   - stage the next round with doc-experiment/tools/stage-round.sh
   - run train scoring through the documented trial/judge/aggregate flow
   - update doc-experiment/LOG.md with score, deltas, concept-level read, doc gaps, and the hypothesis outcome
   - commit results and source edits separately where sensible, keeping one source hypothesis per commit

Hard rules:
- Never expose reference.php or tests.json to test-subject agents.
- Test subjects may read only the staged markdown docs and task prompt.
- Held-out tasks N01, N02, N05, H04 are checkpoint/regression sentinels only and must never drive edits.
- Do not run every model tier against held-out every round.
- Revert a hypothesis if the next comparable round drops more than 2 points or a previously passing task regresses across all trials.
- Do not change PHP behavior; docblock-only source edits must preserve the comment-stripped token stream.
- Keep @since tags intact and do not fabricate changelog entries.
- Stop or pause after two flat rounds on the selected weak tier, when A/B tests stop producing concept-level signal, or if the remaining failures are generic model reasoning noise rather than documentation issues.

Prioritize depth-boundary equivalence first unless calibration/probes show a stronger signal. The known T08 failure is that readers invert `continue while depth >= anchor_depth` into the wrong break condition; teach the equivalent break form explicitly: break only when depth drops below the anchor depth, not when it is equal.
```