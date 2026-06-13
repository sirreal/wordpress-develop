# Next hypotheses and test strategy

This document captures the next phase after round 17. The current train
score is high enough that another ordinary "add the latest judge gap" loop
has weak signal. The next tests should deliberately lower model capability,
increase the signal density of the rendered docs, and separate content gaps
from discoverability gaps.

## Current read

Round 17 was a no-edit hold round on the previous active corpus and scored
98.93 on train. After that hold round, several active tasks were intentionally
replaced or tightened: N03, N04, N06, T07, T11, H04, plus smaller prompt or
reference updates. Those committed corpus changes reset comparability: round
17 remains a trusted historical score for the previous corpus, but it is not a
current-corpus baseline.

Round 18 is the first trusted current-corpus no-edit baseline:
`gpt-5.4` / `medium` / `priority` subjects, `gpt-5.5` / `xhigh` /
`priority` judges, train score 98.73 / core 98.54. The current tier is close
to saturated, but it produced one concrete train failure with three-trial
agreement: N03-first-list-count scored 85.07 because all trials trusted
HTML Processor virtual closers after truncated syntax inside the scanned
region. This is usable source-edit evidence because it is a current-corpus
train failure, not held-out-only signal.

The next valid action is either a focused source hypothesis for the N03
incomplete-token subtree-guard gap, or another no-edit weak-tier calibration
one step down the subject ladder if the experiment owner wants a less
saturated measuring instrument before promotion. Do not compare round 18
against round 17 except as historical context.

A focused citation-only probe after round 18 asked the current subject tier
whether an HTML Processor virtual closer proves a truncated source region was
complete, and which methods to check. All three probes answered correctly and
cited the relevant rendered-doc headings. Round 19 promoted the resulting
placement/transfer edit as a generic class-level recipe plus compact
method-local guard notes. N03 moved from 85.07 to 100.00 with all three
trials at 11/11 and 100 adherence, so this hypothesis is confirmed.

Historical round-17 judge gaps had mostly reduced to these shapes:

- The fact exists, but is too far from the method heading readers enter
  through.
- The docs describe a positive capability, but not the contrasting wrong
  move.
- The docs are accurate, but a long surrounding section dilutes the line that
  matters.
- The subject passed by delegating to the API, but could not explain the API's
  boundary conditions.

Treat future edits as precision edits. A one-line contrast in the right
method docblock is probably worth more than another long example.

## Strong candidates

These are the best next candidates after a local review plus three read-only
subagent passes. Treat them as hypotheses to test through no-edit baselines,
discoverability probes, or scratch-rendered A/B variants before promoting any
source docblock changes.

### 0. Incomplete-token guard for HTML Processor region scans — confirmed in round 19

Core idea: connect the documented subtree-walk/depth-boundary pattern to the
existing incomplete-token API. A depth drop or virtual closer proves that the
HTML parser unwound the element stack; it does not prove the source region was
complete. After a forward scan that will drive a mutation or other trusted
result, callers should check both parser abort state and incomplete-token
state:

- `get_last_error()` / `get_unsupported_exception()` for unsupported parser
  states.
- `paused_at_incomplete_token()` for lexical truncation at the input tail.
- A bounded scan can visit virtual closers after truncation while
  `paused_at_incomplete_token()` is true and `get_last_error()` is still null.

Why this is strong: round 18's only functional train failure was exactly this
gap. All three N03 trials used the documented depth-bounded HTML Processor
walk, passed ordinary omitted-end-tag and malformed-list cases, and failed
only incomplete token/comment tails inside the scanned list.

Round-19 result: source docs now include a generic "scan a region before
editing its opener" recipe in the HTML Processor class docs plus compact notes
near `next_token()` and `get_current_depth()`. N03 passed 11/11 in all three
trials with 100 adherence. Do not keep spending source-edit budget here unless
a weaker tier or future task exposes a new variant.

Risk: low-medium. Keep it framed as a general scan-completion contract, not as
a list-counting recipe. Best placement is near
`WP_HTML_Processor::next_token()`, `get_current_depth()`, and the inherited
`paused_at_incomplete_token()` docs/cross-reference.

### 1. Depth-boundary equivalence card

Core idea: make the subtree-walk boundary mechanically hard to copy wrong.
Show both safe forms side by side near `WP_HTML_Processor::next_token()` and
`get_current_depth()`:

- Continue form: walk while `get_current_depth() >= $anchor_depth`.
- Break form: break only when `get_current_depth() < $anchor_depth`.
- Wrong forms: `>` drops equal-depth content; `<=` exits too early in break
  form.

Why this is strong: round 17's only functional miss was still T08, and the
same off-by-one family has appeared across T03, T06, T08, N02, and H04-style
walks. This is the clearest remaining train signal.

Risk: medium. Avoid a table-specific solution. The invariant should be
explained with generic "container and descendants" language, optionally backed
by a compact trace that stresses sibling/implicit structures.

### 2. Factory lifecycle contract

Core idea: clarify construction failure versus parse/serialization failure at
`WP_HTML_Processor::create_fragment()` and `create_full_parser()`.

Contract to test:

- These factories belong only to `WP_HTML_Processor`.
- `null` from construction means unsupported context/encoding, not malformed
  body content.
- A non-null processor does not prove the document is fully supported.
- Unsupported markup surfaces later while walking, or through `serialize()`,
  `normalize()`, `get_last_error()`, or `get_unsupported_exception()`.
- Callers promising normalized output should not return raw input as a fallback
  when processing fails.
- Reference implementations should get extra credit for explicit incomplete
  token and last-error handling where relevant: Tag Processor and HTML Processor
  loops can stop at an incomplete tail, while HTML Processor walks can also
  encounter unsupported parser states after construction.

Why this is strong: repeated judge notes across N04, T09, T11, T12, and N05
show invented null branches, wrong fallback choices, and cross-class factory
hallucinations. This is a broad API boundary, not a task-specific patch.

Risk: low.

### 3. Where-text-lives matrix

Core idea: add a compact token-model matrix near `get_token_type()` and
`get_modifiable_text()`.

Rows to cover:

- `#text` tokens: decoded text-node character data.
- Attribute values: retrieved through `get_attribute()`, never as `#text`.
- Comments: `#comment`, not `#text`.
- Raw-text/RCDATA elements such as `SCRIPT`, `STYLE`, `TITLE`, and `TEXTAREA`:
  text rides on the element token, not on child `#text` tokens.
- Inline markup: one logical element's text may be split across multiple
  `#text` tokens; accumulate.
- Tag Processor text walk versus HTML Processor tree-aware text walk.

Why this is strong: many passing trials still show shallow explanations about
why comments, attributes, raw-text elements, and split text are excluded or
included. Weaker models are likely to expose this more sharply.

Risk: medium-low if phrased as a token model instead of a task recipe.

### 4. Contract-card rendered-doc A/B

Core idea: before source edits, generate scratch-rendered docs that insert
short "Use this / do not use this / common wrong move" cards under high-entry
method headings.

High-value headings:

- `create_fragment()` and `create_full_parser()`
- `next_tag()`
- `next_token()`
- `get_updated_html()`
- `serialize()`
- `serialize_token()`
- `get_breadcrumbs()`
- `get_namespace()` / `get_tag()`

Why this is strong: recent gaps repeatedly say the fact exists but is too far
from where subjects enter the docs. A shadow variant tests discoverability
without committing source bloat.

Risk: medium. Cards must teach boundaries, not current corpus answers.

### 5. Signal-density pruning A/B

Core idea: test whether fewer visible words produce better weaker-model
behavior. Do this only in scratch-rendered docs first.

Candidate ablations:

- Hide future-direction prose in the Tag Processor header.
- Hide HTML Processor roadmap bullets that imply current inner-text operations
  are unsupported.
- Collapse duplicate special-element/modifiable-text lists into method-local
  contracts.
- Collapse the class-level bookmark overview while keeping method-local
  bookmark docs.
- Deduplicate normalization prose across `normalize()`, `serialize()`, and
  `serialize_token()`, leaving decision contracts at each method heading.
- Move the template-building overview into method-local contracts for
  `set_attribute()`, `set_modifiable_text()`, and `get_updated_html()`.

Why this is strong: if the next weaker tier fails by retrieval dilution rather
than missing facts, pruning may outperform additive documentation.

Risk: low as shadow-doc A/B; high if source pruning is promoted without broad
concept stability.

### 6. Parsed identity and namespace contract

Core idea: show that parsed element identity is not source spelling. Clarify
that `next_tag( 'IMG' )` uses the parser's element identity, while
`get_namespace()` distinguishes HTML/SVG/MathML when names overlap.

Why this is strong: the pre-refresh N06 namespace task passed, but subjects
often added redundant or misunderstood namespace guards. The current corpus no
longer has an active namespace task, so treat this as historical/future-task
evidence until a current train task, probe, or A/B test revives it.

Risk: medium. Use generic parsed-identity language and varied examples rather
than a task-shaped `img`-only recipe.

### 7. Method-local small contracts

These are lower-risk but probably smaller-signal than the candidates above:

- `next_tag()`: opener-only by default; no `is_tag_closer()` guard unless
  `tag_closers => 'visit'`.
- `get_breadcrumbs()`: final entry is the current node; slice it off for
  strict ancestor checks.
- `get_attribute()`: use `is_string( $value ) && '' !== $value` when a real
  string value is required; `null`, `true`, and `''` are distinct.
- `normalize()` / `serialize()`: attribute order is preserved, not sorted.
- `get_tag()`: returns `null` on non-tag tokens during `next_token()` walks.
- `paused_at_incomplete_token()`: lexical incomplete-token state, not unclosed
  tree structure.

Risk: low, but expected incremental score gain may be small unless weaker-tier
probes show these are findability failures.

## Codex model policy

Purpose: as the docs approach perfect scores, move test subjects to less
capable configurations so failures reveal documentation strength instead of
model strength. Keep judges strongest and stable; only weaken test subjects.
Use `priority` service tier for every Codex agent when it is available, because
latency variance is not part of the documentation experiment.

As of 2026-06-12, official OpenAI docs list GPT-5.5 as the flagship model,
GPT-5.4 as the more affordable strong coding/professional model, and
GPT-5.4 mini/nano as smaller lower-latency lower-cost variants. The same
docs list reasoning efforts `none`, `low`, `medium`, `high`, and `xhigh`.
This session's visible subagent overrides expose `gpt-5.5`, `gpt-5.4`, and
`gpt-5.4-mini`. I did not find `gpt-5.3` in the current public model docs; use
it only if the workflow runner exposes it, and treat its position as empirical.

Judges:

- Always use `gpt-5.5` / `xhigh` / `priority` for judge agents when available.
- If unavailable, pause or explicitly record the downgrade. Do not silently
  compare judge scores across different judge tiers.

Recommended subject ladder, strongest to weakest:

1. `gpt-5.4` / `medium` / `priority`
2. `gpt-5.4` / `low` / `priority`
3. `gpt-5.4-mini` / `high` / `priority`
4. `gpt-5.4-mini` / `low` / `priority`

Do not assume base-model size and reasoning effort compose linearly. For
example, `gpt-5.4-mini/high` may beat or lose to `gpt-5.4/low` depending on
the task. Whenever stepping down across a model-family boundary, run a no-edit
rebaseline first.

Default round policy:

- If a subject tier scores 97+ train for two consecutive train rounds and a
  checkpoint held-out split is stable, step down one rung.
- Use one primary subject tier per scored round so deltas remain comparable.
- Do not mix subject tiers into the main round score.
- On checkpoints or hold rounds, run a small cross-tier panel to watch for
  regressions and calibrate the next rung. Treat this panel as diagnostic until
  that tier has its own no-edit baseline.
- If a subject tier falls below roughly 70 with failures unrelated to doc
  lookup, keep it as a stress test but do not drive source edits from it.
- At weaker tiers, consider five trials per task or paired A/B trials because
  sampling variance will rise.

Official references:

- https://developers.openai.com/api/docs/models
- https://developers.openai.com/api/docs/guides/latest-model

## New experiment types

### 1. Shadow-doc ablation

Question: does removing visible but low-value documentation improve results by
increasing signal density?

Method:

- Render current docs normally.
- Produce scratch-only ablation variants that delete or collapse selected
  sections from the rendered markdown. Do not edit source docblocks for the
  first pass.
- Run the same task/model/trial matrix against control docs and ablated docs.
- Promote an ablation to source-doc pruning only if it improves or preserves
  scores and judge notes show less confusion.

Candidate ablations:

- Collapse long narrative sections that do not appear in successful subject
  citations.
- Remove duplicate examples that teach the same path as a stronger nearby
  example.
- Hide internal history, future-direction, and low-frequency caveat prose from
  the rendered docs unless it affects a task contract.
- Replace long paragraphs with compact "Contract" bullets at method headings.

Success metric:

- Equal or better task score.
- Fewer hallucinated methods and fallback branches.
- Explanations cite closer, more local passages.
- No new held-out regression in concepts not targeted by the prune.

### 2. Contrast cards

Question: do "do this instead of that" patterns outperform neutral prose?

Method:

- Add small contrast blocks near the relevant method docs.
- Avoid task-shaped examples. Teach the decision boundary, not the current
  corpus answer.

High-value patterns:

- Use `new WP_HTML_Tag_Processor( $html )` for flat lexical tag/class/attribute
  edits. Do not call `create_fragment()` or `create_full_parser()` on the Tag
  Processor.
- Use `WP_HTML_Processor::create_fragment()` for fragment tree traversal,
  breadcrumbs, depth, normalized serialization, and implied nodes. Do not use
  the Tag Processor when ancestry or namespace identity matters.
- Use `WP_HTML_Processor::create_full_parser()` for whole-document questions
  such as the document `TITLE`. Do not treat the first source-order `<title>`
  as the document title.
- After queued edits such as `add_class()`, `remove_class()`,
  `set_attribute()`, or `set_modifiable_text()`, use `get_updated_html()`. Do
  not use `serialize()` or `normalize()` to read queued lexical edits.
- For selective normalized rewrites while walking every token, use
  `serialize_token()`. Do not mix this with queued edits unless the docs
  explicitly say that pattern is supported.
- During a plain `next_tag()` walk, do not add an `is_tag_closer()` guard unless
  `tag_closers => 'visit'` was requested.
- For ancestor-only tests, slice `get_breadcrumbs()` before checking ancestors
  because the last breadcrumb is the current node.
- For usable attribute values, prefer `is_string( $value ) && '' !== $value`.
  Do not treat `null`, `true`, and `''` as interchangeable.
- For `#text` rewriting, act on `get_token_type() === '#text'`. Do not expect
  attributes, comments, or raw-text element contents to appear as `#text`.
- For foreign content, trust parsed element identity. Do not assume source
  spelling alone determines `get_tag()` or `get_namespace()`.

### 3. Discoverability probes

Question: are failures caused by missing facts or hard-to-find facts?

Method:

- Before a full round, run small read-only subject probes that ask for an answer
  and a cited doc location, not code.
- Use weaker models and short time budgets.
- Score only whether the subject finds the right contract and cites a local
  passage.

Probe questions:

- Which class owns `create_full_parser()`?
- Does `create_fragment()` return null for malformed body HTML, or only for
  unsupported context/encoding?
- Does `next_tag()` visit closers by default?
- Does `get_breadcrumbs()` include the current node?
- Does `next_tag( 'IMG' )` match an SVG `<image>` element?
- Does `normalize()` sort attributes?
- What distinguishes `get_updated_html()`, `serialize()`, and
  `serialize_token()`?

If probes fail while the fact exists, prefer relocation or contrast. If probes
pass but task code fails, prefer examples or task/corpus changes.

### 4. T08 traversal isolation

Question: is the remaining table-extraction variance a documentation gap or a
state-machine reasoning limit?

Method:

- Create microtasks around adjacent regions, self-nesting regions, implied
  nodes, and one-cursor walks.
- Keep them out of train until references and hidden tests are approved.
- Use them first as diagnostic probes, not score-driving tasks.

Potential microtasks:

- Collect text from adjacent `LI` elements with nested inline markup.
- Collect text from adjacent table cells where implied `TBODY` appears.
- Collect nested `BLOCKQUOTE` regions without losing sibling regions.
- Compare nested-loop and single-dispatch implementations and ask which is
  safe.

Expected useful edit if this confirms a doc gap:

- A compact single-dispatch "region collector" recipe that names the invariant:
  one cursor, one loop, explicit active region state, flush on matching closer.

### 5. Method-heading contract pass

Question: can we improve scores by moving existing facts to the exact method
headings where models enter?

Candidate local contracts:

- `WP_HTML_Processor::create_fragment()` and `::create_full_parser()`:
  construction failure is context/encoding failure; parser support failures
  surface later through walking, `serialize()`, `normalize()`, or
  `get_last_error()`.
- `WP_HTML_Tag_Processor::next_tag()` and `WP_HTML_Processor::next_tag()`:
  opener-only by default; tag-name matching is parsed-token matching, not raw
  text matching.
- `WP_HTML_Processor::get_breadcrumbs()`: includes current node as final entry.
- `WP_HTML_Processor::get_tag()`: returns null on non-tag tokens during a
  `next_token()` walk.
- `WP_HTML_Processor::normalize()` and `::serialize()`: attribute order is
  preserved; attributes are not sorted.
- `WP_HTML_Tag_Processor::paused_at_incomplete_token()`: reports lexical
  incomplete-token state, not unclosed tree structure.

## Noise-removal policy

Do not delete prose because it is long. Delete or collapse it only when it is
visible to test subjects and at least one of these is true:

- It repeats a stronger nearby contract.
- It explains implementation history rather than caller behavior.
- It introduces a low-frequency caveat before the common path.
- It causes subjects to add defensive fallback code that the API contract does
  not require.
- It has not been cited by successful trials or judges across multiple rounds.

Run pruning as a shadow-doc ablation first. Source deletion should be a
confirmed hypothesis, not a style cleanup.

## Proposed next sequence

1. Run a no-edit current-corpus baseline/calibration with the first current
   subject tier, `gpt-5.4` / `medium` / `priority`. Record any runner
   mismatch, because this score replaces round 17 as the current-corpus
   comparison point.
2. Continue weak-tier calibration down the subject ladder, one tier at a time,
   until a tier lands in a useful signal band: not saturated, but still mostly
   failing on doc/API reasoning rather than generic coding errors.
3. Run citation-only discoverability probes for the strong-candidate contracts.
   If a fact exists but weak subjects cannot cite it locally, prefer relocation
   or a contract card over more narrative prose.
4. Add a scratch-only rendered-doc variant tool or manual script that can
   insert contract cards and remove named sections without editing source.
5. Run paired shadow-doc A/B tests for the depth-boundary card, factory
   lifecycle card, where-text-lives matrix, and signal-density pruning.
6. Run a small cross-tier diagnostic panel on checkpoint or hold rounds to
   confirm the improvement generalizes across subject capability.
7. Only then promote winning changes to docblocks, one hypothesis per commit,
   with held-out still protected from driving edits.

The main risk now is overfitting the train set or adding enough prose that the
right line becomes harder to find. The next phase should measure signal
density, not only factual completeness.

## Future API/design observations

Use this section for repeated patterns that look like surprising API behavior,
recurring hallucinated methods, or missing API affordances. These notes are not
documentation hypotheses by themselves. Keep them distinct from source
docblock edits until the project decides whether they represent API design
work, task-design drift, or documentation usability gaps.
