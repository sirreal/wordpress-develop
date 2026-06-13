# Experiment log

Hypothesis → outcome narrative, one entry per round. Newest first.

## Rounds 54/55 — serialization rewrite fallback scratch A/B wins

`round-54` was the control rendered-doc round and `round-55` was a
scratch-only HTML Processor rendered-doc variant for
`T09-mark-keyword`, `T12-unwrap-spans`, and the normalization control
`N04-normalize-or-placeholder`. Both used `shadow-doc-a/b`, subjects
`gpt-5.4-mini` / `low` / `priority`, and judge `gpt-5.5` / `xhigh` /
`priority`. Source docblocks were unchanged.

Variant: add a compact string-returning rewrite checklist near the
class-level `serialize_token()` recipe and a method-local wrapper example.
The key distinctions are: use `get_modifiable_text()` for decoded inspection,
not for hand-escaped output; use `serialize_token()` to emit the current token;
the accumulated `$output` is the rewrite; and `normalize( $html )` or raw input
discard wrappers, skipped tokens, replacements, and other emitted changes.

Numeric result: variant won, **99.53 vs 98.87**. Serialization rose 98.30 ->
99.55. T09 improved 98.50 -> 99.60, and T12 improved 98.10 -> 99.50. N04
moved 100.00 -> 99.50 because one variant trial used the lower-level
`create_fragment()` + `serialize()` path rather than the direct `normalize()`
helper, but all N04 hidden cases still passed.

Transfer result: the variant eliminated the control's worst T09 pattern:
decoded `get_modifiable_text()` plus `htmlspecialchars()` as a substitute for
token serialization. It also reduced T12 fallback-policy penalties. The
remaining near-miss is narrower: subjects may still use `normalize( $html )`
or raw input as an explicit abandonment fallback after a parser error.

Interpretation: promotable as an adapted source hypothesis. Keep it generic
and compact. Promote the class-level checklist and method-local wrapper /
anti-pattern examples, but avoid suggesting one universal fallback policy for
all string-returning rewrites.

Next action: commit rounds 54/55 results, then edit
`src/wp-includes/html-api/class-wp-html-processor.php` to promote one adapted
serialization rewrite fallback recipe. Run the docs-only guard, stage docs, and
score the source hypothesis with `gpt-5.4-mini` / `low` / `priority`.

## Round 53 — mini/low calibration exhausts weak-tier ladder

**Train 99.51 / core 99.43** under `weak-tier-calibration`, with subjects
`gpt-5.4-mini` / `low` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This was the final no-edit calibration rung defined in
`PROTOCOL.md`.

Outcome: the weakest configured subject tier is still functionally saturated.
All 45 subject trials passed all hidden cases. The round score was essentially
flat with round 52, 99.53 -> 99.51. Concept means: classes 100.00, traversal
99.62, normalization 99.60, attributes 99.57, text 99.50, and serialization
98.85.

The most repeated weaker-tier signal is not a hidden-test failure but an
adherence pattern around normalized rewrite fallback. T12-unwrap-spans scored
98.60 and T09-mark-keyword scored 99.10; candidates again used raw input or
`normalize( $html )` as generic recovery after a `serialize_token()` rewrite
loop, which discards accumulated insertions/removals/replacements. T05/T06/N06
read-only extraction remained strong but still showed smaller caller-policy
near-misses.

Decision: treat `gpt-5.4-mini` / `low` as the selected weak diagnostic tier
because the ladder is exhausted, even though it remains saturated. Do not
promote source docs directly from the calibration. The next evidence-building
step should be a scratch rendered-doc A/B, not a source edit.

Next action: commit round-53 results separately, then run a focused
`shadow-doc-a/b` diagnostic at `gpt-5.4-mini` / `low` on the serialization
rewrite tasks, testing a compact generic recipe/card in the HTML Processor
class docs for string-returning `serialize_token()` rewrites and explicit
fallback policy.

## Round 52 — mini/high weak-tier calibration still saturated

**Train 99.53 / core 99.46** under `weak-tier-calibration`, with subjects
`gpt-5.4-mini` / `high` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This was a no-edit calibration on the current source docs,
staged after the audit tool was taught to follow the weak-tier subject
ladder. The tooling change affected preflight next-action selection only; the
rendered docs, source docblocks, corpus, runners, and judge policy were
unchanged.

Outcome: still saturated. All 45 subject trials passed all hidden cases. The
round score fell only slightly from round 51, 99.65 -> 99.53. Concept means:
classes 100.00, text 99.73, attributes 99.73, normalization 99.50,
traversal 99.52, and serialization 98.75.

The clearest adherence signal moved from read-only text extraction toward
string-returning normalized rewrites. T09-mark-keyword scored 98.60 and
T12-unwrap-spans scored 98.90 because candidates still used raw input or
`normalize( $html )` as generic fallbacks after a `serialize_token()` rewrite
loop, which discards the accumulated rewrite. Text extraction stayed strong:
T05 was 99.60, T06 was 99.60, and N06 was 99.20.

Decision: record round 52 as the no-edit baseline for `gpt-5.4-mini` /
`high`, but do not promote source docs from another saturated calibration.
Per the subject ladder in `PROTOCOL.md`, step down one final rung before
choosing a primary weak tier for scratch A/B or source-hypothesis work.

Next action: commit round-52 results separately, then prepare and run a
`weak-tier-calibration` round on current docs using `gpt-5.4-mini` / `low` /
`priority`.

## Round 51 — weak-tier calibration still saturated

**Train 99.65 / core 99.59** under `weak-tier-calibration`, with subjects
`gpt-5.4` / `low` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This was a no-edit calibration on the current source docs after
round 50, run because the experiment owner asked to move to a weaker testing
tier before promoting another documentation hypothesis.

Outcome: still too saturated to be the main source-edit driver. All 45
subject trials passed all hidden cases. The weakest task scores were
T06-collect-links at 98.50, T05-text-excerpt at 99.00,
T07-nested-lists at 99.20, T08-table-extract at 99.30,
T09-mark-keyword at 99.40, and N06-extract-toc at 99.50. Concept means were
attributes/classes/normalization 100.00, serialization 99.70, traversal 99.56,
and text 99.17.

The useful signal remains adherence-only: T05/N06 still show occasional
fail-closed handling of already visited read-only text after
`paused_at_incomplete_token()` or `get_last_error()`, T06 still varies on
read-only completion policy, and T09 still shows occasional uncertainty about
normalized rewrite fallback. None of this justifies a new source docblock edit
before a less saturated tier is calibrated.

Decision: record round 51 as a no-edit calibration baseline for
`gpt-5.4` / `low`, but do not use it to promote source documentation. Per the
subject ladder in `PROTOCOL.md`, step down one more rung.

Next action: commit round-51 results separately, then prepare and run a
`weak-tier-calibration` round on current docs using `gpt-5.4-mini` / `high` /
`priority`.

## Round 50 — checkpoint before weaker-tier calibration

**All 99.08 / train 99.65 / held-out 96.93 / core 98.97** under
`checkpoint`, with subjects `gpt-5.4` / `medium` / `priority` and judge
`gpt-5.5` / `xhigh` / `priority`. This scored the current source docs after
the round-47 text-policy source edit and after the rounds-48/49 read-only
completion-policy scratch A/B. Source docblocks were unchanged since
`29a148a4f7`.

Outcome: stable enough not to revert. Compared with the previous checkpoint,
round 46, train rose 99.63 -> 99.65 while held-out fell 98.33 -> 96.93. The
held-out movement is below the 2-point revert threshold and is not an
all-trial task regression. The drop is concentrated in N02 trial 3, which
passed 6/9 after interpreting `array( 'FIGURE', 'IMG' )` breadcrumbs as
arbitrary-depth containment rather than a contiguous breadcrumb path. This is
held-out-only sentinel evidence and must not drive a source edit.

The train tasks tied to the read-only completion-policy candidate stayed
strong: T05 was 99.90, T06 was 98.40, T08 was 99.30, and N06 was 100.00.
This keeps the round-49 scratch variant viable, but the current primary tier
is saturated enough that another immediate source promotion would have weak
signal.

Decision: do not revert. Do not promote another source docblock edit yet.
Per experiment-owner direction, move to a weaker subject tier and run a
no-edit calibration before using that tier to drive source edits.

Next action: commit round-50 results separately, then prepare and run a
`weak-tier-calibration` round on current docs using the next subject tier in
`PROTOCOL.md`, `gpt-5.4` / `low` / `priority`.

## Rounds 48/49 — read-only completion-policy scratch A/B wins

`round-48` was the control rendered-doc round and `round-49` was a
scratch-only HTML Processor rendered-doc variant for four train tasks:
`T05-text-excerpt`, `T06-collect-links`, `T08-table-extract`, and
`N06-extract-toc`. Both used `shadow-doc-a/b`, subjects `gpt-5.4` /
`medium` / `priority`, and judge `gpt-5.5` / `xhigh` / `priority`. Source
docblocks were unchanged.

Variant: add one compact read-only completion-policy rule of thumb under the
class-level DOM-style text recipe. It separates best-effort extraction from
complete-source validation and from mutation, normalization, or token-rewrite
output. The key contract is that `paused_at_incomplete_token()` and
`get_last_error()` report scan status; they do not retroactively invalidate
tokens already visited.

Numeric result: variant won, **99.65 vs 99.03** on the paired subset. All 24
subject trials passed all hidden cases. T05 improved 98.30 -> 100.00, T08
improved 99.00 -> 99.80, and N06 improved 99.40 -> 100.00. T06 dipped 99.40
-> 98.80 because one variant trial still cleared read-only results on
`get_last_error()`.

Transfer result: the variant removed several over-strict completion-policy
near-misses. Control N06 trial 2 rejected accumulated headings after
`paused_at_incomplete_token()`, while variant N06 was 100/100/100 adherence.
Control T05 trials 1 and 2 used a risky Tag Processor fallback after an HTML
Processor abort; variant T05 used the HTML Processor pattern directly in all
trials. T06 shows the remaining weakness: a compact policy note helps but
does not fully prevent all fail-closed read-only collectors.

Interpretation: promotable after a checkpoint gate, but adapt carefully. The
source edit should keep the small rule-of-thumb shape and avoid implying that
all read-only extractors must keep partial results. It should state the
choice as caller contract: best-effort extraction may return accumulated
visited-token data, while complete-source validation and mutations/rewrites
should fail closed when required.

Next action: commit rounds 48/49 results separately, then run the required
checkpoint/regression sentinel before promoting another source docblock edit.
If held-out remains stable, promote an adapted read-only completion-policy
note as one source hypothesis.

## Round 47 — text-policy decision table source edit confirmed

**Train 99.55 / core 99.48** under `scored-train`, with subjects
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored commit `29a148a4f7`, which promoted the winning
rounds-44/45 text-policy decision table into the `WP_HTML_Processor` source
docs.

Outcome: keep. All 45 subject trials passed all hidden cases. Compared with
the previous comparable scored-train round, round 43, train rose 98.18 ->
99.55. That comparison includes round 43's known generic T05 PHP bug, so the
more useful read is that round 47 is back in the high-signal band and below
round 36 only by judge noise, 99.65 -> 99.55. There is no revert signal and
no all-trial task regression.

Target tasks stayed strong: T03 was 100.00, T05 was 98.00, T06 was 99.40,
T08 was 99.40, and N06 was 99.20. Judges credited the promoted table and
method-local reminders for the key transfer: candidates consistently used
ordinary `#text` tokens for DOM-style heading, table-cell, link, and article
text, and treated SCRIPT/STYLE/TITLE/TEXTAREA opener-carried text as opt-in
data rather than ordinary subtree text.

Residual signal: read-only completion policy is still not crisp enough. In
T05, T06, T08, and N06, judges repeatedly saw candidates erase already
collected read-only results when `paused_at_incomplete_token()` was true, even
though the new source docs say this is caller policy. This is a real train
near-miss, but the source docs already contain the basic fact, so do not
promote another source wording change directly. Test a scratch variant that
makes the read-only best-effort vs complete-source-validation decision more
concrete.

Next action: commit round-47 results separately, then run a focused scratch
A/B for read-only completion policy on the affected train tasks before any
additional source promotion.

## Round 46 — checkpoint clears text-policy promotion gate

**All 99.36 / train 99.63 / held-out 98.33 / core 99.28** under
`checkpoint`, with subjects `gpt-5.4` / `medium` / `priority` and judge
`gpt-5.5` / `xhigh` / `priority`. This scored the current source docs after
the round-43 serialization fallback source edit and before promoting the
rounds-44/45 text-policy decision-table scratch variant.

Outcome: stable enough to continue. All 57 subject trials passed all hidden
cases. Compared with the previous checkpoint, round 42, train rose 99.54 ->
99.63 while held-out was effectively flat, 98.38 -> 98.33. The held-out
movement is below the revert threshold and is not an all-trial functional
regression. Held-out judge gaps remain regression-sentinel data only and must
not drive the next edit.

The train tasks tied to the text-policy candidate stayed strong: T03 was
100.00, T05 was 98.80, T06 was 99.50, T08 was 98.60, and N06 was 98.60. The
checkpoint also repeated the same useful T05 near-miss from train evidence:
visited parser artifacts are not necessarily emitted normalized content, so
conditional subtree emission should test the serialized token string when the
contract depends on emitted output.

Decision: checkpoint gate is clear. Promote one adapted source docblock
hypothesis for the text-policy decision table: ordinary DOM-style text reads
visited `#text` tokens by default; special-element opener text is an explicit
opt-in with different decoding/raw-text semantics; and read-only partial-scan
fallback remains caller policy rather than a blanket reject-or-keep rule.

Next action: commit round-46 results separately, then edit the
`WP_HTML_Processor` source docs for the text-policy hypothesis, run the
docs-only guard, stage docs, and score the source edit as the next normal
source round.

## Rounds 44/45 — text-policy decision table scratch A/B wins

`round-44` was the control rendered-doc round and `round-45` was a
scratch-only HTML Processor rendered-doc variant for five train tasks:
`T03-first-h1-text`, `T05-text-excerpt`, `T06-collect-links`,
`T08-table-extract`, and `N06-extract-toc`. Both used `shadow-doc-a/b`,
subjects `gpt-5.4` / `medium` / `priority`, and judge `gpt-5.5` /
`xhigh` / `priority`. Source docblocks were unchanged.

Variant: add a compact "where text lives / extraction policy" table near the
class-level DOM-style text recipe, plus short method-local reminders in
`next_token()` and `get_modifiable_text()`: ordinary DOM-style text reads only
visited `#text` tokens; special-element opener text is explicit opt-in for
that element's own contents; TITLE/TEXTAREA are decoded while SCRIPT/STYLE are
raw; and read-only extraction policy for partial scans is separate from
mutation, normalization, and token-rewrite fail-closed policy.

Numeric result: variant won, **99.56 vs 98.94** on the paired subset. All 30
subject trials passed all hidden cases. T03 improved 99.10 -> 100.00, T05
98.90 -> 99.90, T08 98.60 -> 99.50, and N06 98.70 -> 99.50. T06 dipped only
99.40 -> 98.90, still with all trials passing all hidden cases.

Transfer result: the variant eliminated the main special-element over-inclusion
pattern in the paired tasks. Control T03 trial 3, T08 trials 1 and 3, and N06
trial 2 still treated special-element opener text as ordinary subtree text.
Variant T03, T08, and N06 trials all used ordinary `#text`-only extraction for
those tasks. The remaining weak spot is read-only partial-scan policy: T06
variant trial 2 still returned an empty result on `paused_at_incomplete_token()`
even though all hidden cases passed.

Interpretation: promotable after the checkpoint gate, but adapt carefully. The
source edit should keep the compact decision-table shape and the method-local
opt-in reminder. It should not over-expand the prose or imply that all
read-only extractors should keep partial results; the contract remains caller
policy.

Next action: commit rounds 44/45 results separately, then run the required
checkpoint/regression sentinel before promoting another source docblock edit.
If held-out is stable, promote an adapted text-policy decision table as one
source hypothesis.

## Round 43 — serialization fallback source edit scored neutral

**Train 98.18 / core 97.89** under `scored-train`, with subjects
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored commit `27c764f6f0`, which promoted the round-41
fallback-policy card into source docs around the HTML Processor class recipe,
`create_fragment()`, `normalize()`, and `serialize_token()`.

Outcome: keep under the revert rule, but treat as neutral rather than a clean
win. Compared with the primary scored-train comparator, round 36, train fell
99.65 -> 98.18. The drop is below the 2-point revert threshold and is not an
all-trial task regression. It is concentrated in one unrelated T05-text-excerpt
trial that passed 2/10 because the candidate treated `preg_match_all()` as a
boolean/single-match API and skipped multi-codepoint text chunks. The judge
explicitly called this a PHP bug, not an HTML API documentation failure.

Target serialization tasks remained stable but did not show a decisive win:
N04-normalize-or-placeholder stayed 100.00, T12-unwrap-spans rose 99.70 ->
99.80, and T09-mark-keyword fell 99.30 -> 99.10. All target hidden cases
passed. The remaining near-miss is still raw-input fallback after parser
abort: T09 candidates returned the original HTML even though the source docs
now state that raw input is not normalized rewritten output. The edit improved
local correctness of the docs, but the transfer problem is not fully solved.

Decision: keep `27c764f6f0`; do not revert. Do not spend another immediate
source edit on fallback-policy wording without fresh diagnostic evidence.

Next action: commit round-43 results separately, then analyze trusted judge
notes for the next diagnostic. The strongest current signals are still text
policy/read-only extraction and UTF-8 decoded-text measurement, but the T05
functional failure alone is generic model noise and should not drive a source
edit by itself.

## Round 42 — checkpoint clears fallback-policy promotion gate

**All 99.29 / train 99.54 / held-out 98.38 / core 99.21** under
`checkpoint`, with subjects `gpt-5.4` / `medium` / `priority` and judge
`gpt-5.5` / `xhigh` / `priority`. This scored the current source docs after
the round-36 depth/direct-child source edit and before promoting the winning
round-41 serialization fallback-policy scratch card.

Outcome: stable enough to continue. All 57 subject trials passed all hidden
cases. Compared with the previous checkpoint, round 35, train rose 99.50 ->
99.54 while held-out fell 99.38 -> 98.38. The held-out decline is below the
2-point revert threshold and is not an all-trial functional regression:
N01-remove-external-class stayed 100.00, N02-collect-figure-images was 98.90,
H04-remove-empty-paragraphs was 98.20, and N05-document-title fell to 96.40
from one adherence-only trial. Held-out judge gaps remain regression-sentinel
data only and must not drive the next edit.

The train tasks tied to the fallback-policy candidate stayed strong:
N04-normalize-or-placeholder was 100.00, T12-unwrap-spans was 98.80, and
T09-mark-keyword was 99.80. Round-42 judges still noted the same generic gap:
after a token-by-token `serialize_token()` rewrite, `normalize( $html )` on
the original input or returning raw input discards the accumulated rewrite and
is only a caller-chosen fallback, not normalized rewritten output.

Decision: checkpoint gate is clear. Promote one adapted source docblock
hypothesis for serialization fallback policy, making the anti-pattern more
explicit than the round-41 scratch wording.

Next action: commit round-42 results separately, then edit the
`WP_HTML_Processor` source docs for the fallback-policy hypothesis, run the
docs-only guard, stage docs, and score the source edit as the next normal
source round.

## Rounds 40/41 — serialization fallback scratch A/B wins

`round-40` was the control rendered-doc round and `round-41` was a
scratch-only HTML Processor rendered-doc variant for three train tasks:
`T09-mark-keyword`, `T12-unwrap-spans`, and
`N04-normalize-or-placeholder`. Both used `shadow-doc-a/b`, subjects
`gpt-5.4` / `medium` / `priority`, and judge `gpt-5.5` / `xhigh` /
`priority`. Source docblocks were unchanged.

Variant: add method-local fallback-policy guidance around
`WP_HTML_Processor::create_fragment()`, `normalize()`, and
`serialize_token()`: factory `null` means no processor was created; later
`get_last_error()` is an unsupported-parser abort; the accumulated
`serialize_token()` output is the rewrite; `normalize( $html )` on the
original input discards emitted rewrite changes; raw original input is not
normalized output; and `paused_at_incomplete_token()` is a separate
complete-input policy check.

Numeric result: variant won, **99.83 vs 99.57** on the paired subset. All
18 subject trials passed all hidden cases. N04 stayed perfect at 100.00.
T12 improved 98.90 -> 100.00, with all variant trials using an explicit
empty-string fallback instead of raw input or `normalize( $html )` after the
rewrite loop. T09 fell slightly, 99.80 -> 99.50, because one variant trial
still used `normalize( $html )` as an error fallback.

Interpretation: promotable after the checkpoint gate, but adapt carefully.
The source edit should keep the winning method-local fallback-policy shape,
but should make the anti-pattern more explicit than the scratch wording:
after a `serialize_token()` rewrite loop, `normalize( $html )` and raw input
both abandon the accumulated rewrite; choose a caller-defined failure signal
instead.

Next action: run a checkpoint/regression sentinel on the current source docs
before promoting another source docblock edit. If held-out remains stable,
promote an adapted fallback-policy card as one source hypothesis and score it
normally.

## Round 39 — serialization fallback citation probe passes

`round-39` was a `discoverability-probe` against the current rendered docs,
with subjects `gpt-5.4` / `medium` / `priority`. The question asked how a
token-by-token `serialize_token()` rewriter should distinguish
`create_fragment()` returning `null`, later `get_last_error()`, trailing
incomplete input via `paused_at_incomplete_token()`, post-rewrite
`normalize( $html )` / `serialize()` calls, and raw-input fallback when the
caller promises normalized output.

Outcome: 3/3 subjects answered correctly with local citations. They found
that factory `null` is construction-time failure while non-null
`get_last_error()` is a later parser abort; `paused_at_incomplete_token()` is
a separate complete-input policy check after scanning; the accumulated
`serialize_token()` string is the rewrite; calling `normalize( $html )` on
the original input discards emitted changes; `serialize()` returns `null`
after scanning has started; and raw original input is not documented as a
normalized-output fallback.

Interpretation: the facts are discoverable when directly requested. The
remaining problem is transfer into implementation tasks, where round-36 and
round-37/38 candidates still improvised raw-input or `normalize( $html )`
fallbacks after a rewrite loop.

Next action: test a scratch-only method-local fallback-policy card around
`serialize_token()` / `create_fragment()` / `normalize()` on
`T09-mark-keyword`, `T12-unwrap-spans`, and `N04-normalize-or-placeholder`.
Do not source-edit from this probe alone.

## Rounds 37/38 — method-local text policy scratch A/B loses

`round-37` was the control rendered-doc round and `round-38` was a
scratch-only HTML Processor rendered-doc variant for five train tasks:
`T03-first-h1-text`, `T05-text-excerpt`, `N06-extract-toc`,
`T08-table-extract`, and `T09-mark-keyword`. Both used `shadow-doc-a/b`,
subjects `gpt-5.4` / `medium` / `priority`, and judge `gpt-5.5` /
`xhigh` / `priority`. Source docblocks were unchanged.

Variant: change the method-local `WP_HTML_Processor::next_token()` special
elements paragraph from "important exception" framing to explicit
caller-policy framing, and add a method-local `get_modifiable_text()` warning
that the method is not a predicate for ordinary text. The intended target was
the recurring over-inclusion of SCRIPT/STYLE/TEXTAREA/TITLE opener-carried
text in ordinary subtree extraction.

Numeric result: variant lost, **98.72 vs 99.18** on the paired subset. All
30 subject trials passed all hidden cases, so the loss is adherence-only.
T03 was flat at 98.80, but T05 fell 99.60 -> 98.60, N06 fell 98.90 ->
98.80, T08 fell 98.70 -> 98.30, and T09 fell 99.90 -> 99.10. The variant did
not eliminate the target pattern: variant T03 still had one trial including
special-element opener text, and variant T08 still had two such trials.

Interpretation: do not promote this wording. The method-local text-policy
direction is not dead, but this particular phrasing adds noise and can pull
models into broader fallback or special-element reasoning without fixing the
transfer problem. Keep the existing source docs unchanged.

Next action: run the separate normalized-output / `serialize_token()`
fallback diagnostic as a citation-only probe before any source edit. Round-36
and round-37/38 judges repeatedly show candidates improvising raw-input or
`normalize( $html )` fallbacks after token-by-token rewrites, but that
hypothesis has not had a fresh focused probe after the round-36 source state.

## Round 36 — depth-bounded traversal source edit confirmed

**Train 99.65 / core 99.59** under `scored-train`, with subjects
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored the source promotion of the round-34 class-level
HTML Processor recipe for subtree membership and direct-child opener checks.
The prepared round was at `4a39f7802c`, with the documentation hypothesis in
`6548356f1f`.

Outcome: confirmed. All 45 subject trials passed all hidden cases. Compared
with the primary same-mode scored-train baseline, round 32, the round is
essentially tied: 99.67 -> 99.65, well clear of the revert threshold. The
targeted traversal tasks held or improved: N03-first-list-count stayed
perfect at 100.00, T07-nested-lists rose 99.30 -> 100.00, and
T08-table-extract rose 97.60 -> 98.50. N06-extract-toc was 99.00, down only
0.4 from round 32 and still all hidden cases passed.

Secondary context: compared with the immediate pre-promotion checkpoint's
train split, round 35 train 99.50 -> round 36 train 99.65. This is useful
local context but not the primary comparator because round 35 was
`checkpoint` mode and included held-out tasks.

Decision: keep the traversal recipe source edit. It is general API
documentation and the scored source round does not show a regression. The
remaining judge signal is separate: special-element opener text can still be
over-included in ordinary subtree text, `serialize_token()` rewriters still
vary in fallback policy, and examples that call the inherited
`paused_at_incomplete_token()` from HTML Processor workflows could be made
more explicit.

Next action: commit round-36 results separately from the source hypothesis,
then analyze trusted round-36 judge notes against the backlog. Do not add more
traversal/depth source prose unless a new measurement exposes a distinct
failure.

## Round 35 — checkpoint clears depth-card promotion gate

**All 99.47 / train 99.50 / held-out 99.38 / core 99.41** under
`checkpoint`, with subjects `gpt-5.4` / `medium` / `priority` and judge
`gpt-5.5` / `xhigh` / `priority`. This scored the current source docs after
the round-32 `next_tag()` source edit and before promoting the round-34
scratch traversal card.

Outcome: stable. All 57 subject trials passed all hidden cases. Compared with
the previous checkpoint, round 24, all-score rose 99.35 -> 99.47, train rose
99.41 -> 99.50, and held-out rose 99.12 -> 99.38. Held-out scores were
N01-remove-external-class 100.00, N02-collect-figure-images 99.80,
N05-document-title 98.80, and H04-remove-empty-paragraphs 98.90. There is no
held-out functional regression and no reason to revert the current source
docs.

The checkpoint also confirms the round-32 cursor edit held in the broader
sentinel: N03-first-list-count was 100.00 and T07-nested-lists was 99.30. The
lowest train task remains T08-table-extract at 98.10, with the same residual
text-policy issue: subjects sometimes over-include SCRIPT/STYLE/TEXTAREA/TITLE
opener-carried modifiable text when ordinary `#text` extraction was intended.
That is separate from the depth/direct-child traversal card.

Decision: the held-out gate is clear.

Next action: promote an adapted, concise version of the round-34
depth-bounded traversal/direct-child card into the `WP_HTML_Processor` class
documentation as one source hypothesis, then run the docs-only guard, stage
docs, and score it as the next normal source round.

## Rounds 33/34 — depth-bounded traversal scratch A/B wins

`round-33` was the control rendered-doc round and `round-34` was a
scratch-only HTML Processor rendered-doc variant for four train tasks:
`N03-first-list-count`, `N06-extract-toc`, `T06-collect-links`, and
`T08-table-extract`. Both used `shadow-doc-a/b`, subjects `gpt-5.4` /
`medium` / `priority`, and judge `gpt-5.5` / `xhigh` / `priority`. Source
docblocks were unchanged.

Variant: add a compact class-level card after the existing "scan a region
before editing its opener" recipe explaining depth-bounded subtree membership
and direct-child opener tests: record the container opener depth; later tokens
remain inside while depth is `>=` that value; direct child element openers
require `get_token_type() === '#tag'`, `! is_tag_closer()`, and
`get_current_depth() === $container_depth + 1`; child closers report parent
depth and must not be counted; repeated regions should generally use one
`next_token()` loop with explicit state rather than nested token loops.

Numeric result: variant won, **99.08 vs 97.34** on the paired subset.
Traversal improved from 96.62 to 99.00. N03 moved from 94.46 to 100.00: the
control had one 9/11 trial that treated a depth drop plus null
`get_last_error()` as a complete scan and missed
`paused_at_incomplete_token()`, while all variant N03 trials passed 11/11
with 100 adherence. T08 moved from 96.50 to 98.00. N06 was flat/slightly up
at 99.00, and T06 dipped only 0.2 to 99.30. All variant hidden tests passed.

Interpretation: promotable as a source hypothesis after the held-out cadence
is satisfied. The edit is generic API documentation rather than a task-shaped
answer, and it directly addresses repeated judge gaps around subtree
membership, direct-child detection, and one-cursor traversal. Caveat: it does
not solve the separate text-policy issue. Variant judges still saw
special-element opener text over-inclusion in N06 and T08, so that remains a
separate method-local/text-policy hypothesis.

Next action: run a checkpoint/regression sentinel on the current source docs
before promoting another source docblock edit. If held-out remains stable,
promote an adapted, concise version of the depth-bounded traversal card into
the `WP_HTML_Processor` class documentation and score it as one source
hypothesis.

## Round 32 — HTML Processor next_tag() cursor source edit confirmed

**Train 99.67 / core 99.62** under `scored-train`, with subjects
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored commit `19a49c1479`, which promoted the winning
round-31 scratch method-local card into `WP_HTML_Processor::next_tag()`:
searches are cursor-relative, a failed search does not rewind, `tag_name` is
one string or null rather than a list of alternatives, and first-of-several
tag searches should use one forward scan plus `get_tag()` branching unless
the caller intentionally bookmarks/seeks or creates a new processor.

Outcome: confirmed. The round improved from the comparable round-29
scored-train baseline 98.31 to 99.67, well clear of the revert threshold.
All 45 subject trials passed all hidden cases. The target failure recovered:
T07-nested-lists moved from 81.13 to 99.30, and all three T07 trials used a
single forward scan rather than sequential filtered searches. N03 stayed
perfect at 100.00.

Residual signal is adherence-only. The lowest task was T08-table-extract at
97.60, with judges again pointing at generic traversal/depth traces,
virtual-closer and incomplete-token policy, and ordinary-text versus
special-element opt-in wording. T03 and N06 passed all hidden cases but still
showed occasional special-element text over-inclusion in explanations or
implementations. T09 and T12 were strong, but judges still noted inconsistent
fallback policy for token-serialization helpers that promise normalized
output.

Decision: keep `19a49c1479`. The suggested generic recipe direction remains
plausible, but should be tested by a discoverability probe or scratch
rendered-doc A/B before source promotion; do not directly add broad
class-level recipe prose from round-32 judge suggestions alone. If such a
diagnostic wins, check the held-out checkpoint cadence before promoting the
next source docblock edit.

## Round 29 — ordinary subtree text policy source edit is mixed

**Train 98.31 / core 98.05** under `scored-train`, with subjects
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored commit `95173a4486`, which promoted the winning
round-28 scratch direction into the HTML Processor class docs: ordinary
subtree text is `#text` tokens by default, special-element opener text is
explicit opt-in, and unguarded `get_modifiable_text()` is too broad.

Outcome: mixed, keep under the revert rule but do not treat the hypothesis as
fully confirmed. The round dropped from the comparable round-23 scored-train
baseline 99.50 to 98.31, below the 2-point revert threshold. There was no
all-trials regression on a previously passing task, but T07-nested-lists had
one functional miss and fell to 81.13 because one subject ran separate
cursor-relative `next_tag()` scans for `UL` and then `OL`; the second scan
started at EOF and never revisited earlier `OL` elements. Judges attributed
that to missing HTML Processor `next_tag()` cursor/OR-query guidance, not to
the text-policy edit.

Target text results were split. T03-first-h1-text improved to 99.40 and
T05-text-excerpt improved to 99.80. N06-extract-toc fell to 97.60: all three
subjects still included SCRIPT/STYLE/TEXTAREA/TITLE opener text in ordinary
heading text. The N06 judge identified the competing method-local
`next_token()` special-element paragraph as the stronger remaining source of
over-inclusion; the overview recipe now says opt-in, but the method section
can still read like a general instruction to include special-element opener
text whenever collecting element text.

Decision: do not revert `95173a4486`; it stays below the protocol's revert
threshold and improved adjacent text tasks. Also do not add another broad
overview recipe for this same text policy. If continuing text-policy work, the
next diagnostic should be method-local and focused on the `next_token()`
special-element paragraph. The stronger immediate train failure is the
repeated `WP_HTML_Processor::next_tag()` cursor-relative / one-of-several-tags
gap exposed by T07 and previously seen in N03-style scans.

Follow-up citation-only probe: `round-29-next-tag-cursor-or-search` asked
three subjects whether a `next_tag( 'UL' )` scan followed by a
`next_tag( 'OL' )` scan on the same processor rescans earlier tags, and how to
find the first of several tag names. All three answered correctly: the second
scan does not restart; a failed `next_tag()` leaves the cursor at the end; use
one forward scan and branch on `get_tag()` for alternatives; `tag_name` is a
single string or null. They mostly cited the Tag Processor "Finding tags" and
"Custom queries" sections plus the HTML Processor one-cursor `next_token()`
note. Interpretation: the facts are discoverable when asked directly, but
placement is weak for HTML Processor `next_tag()` task work. The next
documentation diagnostic can be a scratch method-local HTML Processor
`next_tag()` contrast card rather than another broad overview recipe. A
sidecar doc-location check confirmed there is no local HTML Processor
`next_tag()` warning and no HTML Processor first-of-several-tags idiom; the
only OR-style idiom found is in the Tag Processor "Custom queries" section.

Follow-up scratch A/B: rounds 30/31 tested a method-local
`WP_HTML_Processor::next_tag()` card under `shadow-doc-a/b` on N03 and T07.
The card stated that searches are cursor-relative, false does not reset the
cursor, `tag_name` is one string or null, first-of-several tags should use one
forward `next_tag()` scan plus `get_tag()` branching, and intentional rescans
require a bookmark/seek or a new processor. Result: variant won cleanly,
99.80 versus 99.30. N03 stayed 100.00 in both rounds, while T07 improved from
98.60 to 99.60 and all variant T07 trials used a one-pass approach. This
supports promoting the method-local cursor/OR-search card as a source
hypothesis.

## Rounds 27/28 — ordinary-text negative example scratch A/B

`round-27` was a fresh control rendered-doc round and `round-28` was a
scratch-only HTML Processor rendered-doc variant for the same three train
tasks (`T03-first-h1-text`, `N06-extract-toc`, `T05-text-excerpt`). Both used
`shadow-doc-a/b`, subjects `gpt-5.4` / `medium` / `priority`, and judge
`gpt-5.5` / `xhigh` / `priority`. Source docblocks were unchanged.

Variant: instead of the broad policy matrix from round 26, the scratch docs
added a default-first policy under the HTML Processor DOM-style text recipe:
ordinary subtree text is only reached `#text` tokens; special-element opener
text is available through `get_modifiable_text()` only when the caller
explicitly opts into those node types. The variant also included a negative
example intended to discourage treating all modifiable text as ordinary text.

Numeric result: the variant improved the paired subset from **99.27** to
**99.50**. T03 moved from 99.60 to 100.00, N06 from 98.20 to 98.90, and T05
from 100.00 to 99.60. All trials in both rounds passed all hidden tests.

Interpretation: promotable after revising the scratch wording. The target
failure improved cleanly: in the control, T03 trials 2/3 and N06 trials 2/3
included SCRIPT/STYLE/TEXTAREA/TITLE opener text in ordinary heading text; in
the variant, all three T03 implementations and all three N06 implementations
used `#text` only for ordinary heading/subtree text. T05 still included
TITLE/TEXTAREA and excluded SCRIPT/STYLE, so the stronger default rule did not
erase the explicit opt-in path needed by callers that ask for those elements.

Caveat before source promotion: the scratch negative example used
`null !== $processor->get_modifiable_text()`, but `get_modifiable_text()`
returns a string and should not be taught as a presence test. Promote the
default-first/explicit-opt-in wording, plus a negative example based on
calling `get_modifiable_text()` from an unguarded token loop, but do not copy
the null-check code.

Next action: commit these result artifacts, then promote the adapted generic
recipe to the `WP_HTML_Processor` class documentation and score it as one
source hypothesis.

## Rounds 25/26 — read-only text policy matrix scratch A/B

`round-25` was the control rendered docs and `round-26` was a scratch-only
HTML Processor rendered-doc variant adding a compact read-only text extraction
policy matrix near the class-level DOM-style text recipe. Both rounds used
`shadow-doc-a/b`, the same three train tasks (`T03-first-h1-text`,
`N06-extract-toc`, `T05-text-excerpt`), subjects `gpt-5.4` / `medium` /
`priority`, and judge `gpt-5.5` / `xhigh` / `priority`. Source docblocks were
unchanged.

Numeric result: the variant improved the paired subset from **98.70** to
**99.17**. T05 moved from 99.40 to 100.00, T03 from 99.70 to 100.00, and N06
from 97.00 to 97.50. All trials in both rounds passed all hidden tests.

Interpretation: mixed, not promotable as written. The matrix helped the task
that explicitly wanted TITLE/TEXTAREA text while excluding SCRIPT/STYLE, but
it did not solve the target N06 over-inclusion pattern. More importantly, it
worsened the ordinary-heading-text signal in T03: control had two pure
`#text` implementations and one implementation that added special-element
opener text, while the variant had all three T03 subjects append SCRIPT,
STYLE, TEXTAREA, and TITLE opener text. Judges scored this as documented API
use because hidden cases did not cover special elements, but they still noted
that it was broader than the ordinary text-node extraction policy.

Decision: do not promote this policy matrix to source docs. The next text
diagnostic, if pursued, should be a revised scratch-only variant that stresses
the default exclusion rule and a negative example: ordinary heading/subtree
text appends only `#text`; special-element opener text is available but is not
included unless the caller explicitly asks for those node types. Keep the
serialization/decoded-text reparse signal separate.

## Round 24 — checkpoint after lexical-text boundary edit

**All 99.35 / train 99.41 / held-out 99.12 / core 99.28** under
`checkpoint`, with subjects `gpt-5.4` / `medium` / `priority` and judge
`gpt-5.5` / `xhigh` / `priority`. This was the held-out regression sentinel
after the round-23 Tag Processor lexical-text boundary source edit.

Outcome: stable. All 57 subject trials passed all hidden tests, including all
four held-out tasks. Held-out scores were H04 98.70, N01 100.00, N02 99.00,
and N05 98.80. There is no held-out functional regression and no reason to
revert the source edit.

The target train signal held: T05-text-excerpt scored 99.80 in the checkpoint
with all three trials passing 10/10 and adherence 100/99/99. The Tag Processor
lexical-token example is no longer pulling subjects away from
`WP_HTML_Processor::create_fragment()` for parsed BODY-fragment text
extraction.

Residual train signal: the lowest task was T09-mark-keyword at 98.10 because
one trial reparsed decoded `get_modifiable_text()` with
`WP_HTML_Processor::normalize()` instead of wrapping `serialize_token()`.
N06-extract-toc scored 98.30 because two trials over-included special-element
opener modifiable text in ordinary heading text. These are separate candidate
diagnostics: (1) decoded modifiable text is application text, not an HTML token
to reparse during serialization, and (2) ordinary subtree text is `#text` by
default, with special-element opener text as explicit caller opt-in.

Next action: run a citation-only discoverability probe before any source edit.
Prefer probing the HTML Processor read-only text policy first because it spans
round-23 T03/N06/T05 and round-24 N06/N02 notes. Keep the
`serialize_token()`/decoded-text reparse issue as a separate follow-up probe or
scratch A/B candidate; do not merge the two hypotheses into one source edit.

Follow-up citation-only probe:
`round-24-readonly-text-extraction-policy` asked three `gpt-5.4` / `medium`
subjects to explain ordinary read-only subtree text extraction, special
element opener text opt-in, and fallback policy after `get_last_error()` or
`paused_at_incomplete_token()`. All three answered the main boundary
correctly: ordinary subtree text uses only `#text`; callers should not call
`get_modifiable_text()` on every opening tag; SCRIPT/STYLE/TITLE/TEXTAREA
opener text is opt-in; and read-only fallback is caller policy rather than an
automatic discard of already collected text. Interpretation: the facts are
discoverable when directly requested. The remaining train near-misses are a
placement/transfer or signal-density problem, so the next diagnostic should be
a scratch rendered-doc A/B for a compact policy matrix before source
promotion.

## Round 23 — Tag Processor lexical-text boundary confirmed

**Train 99.50 / core 99.42** under `scored-train`, with subjects
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored commit `f7c83bfb6b`: a narrow Tag Processor class-doc
placement edit before the `next_token()` text example, labeling it as lexical
token processing and pointing parsed BODY-fragment text extraction to
`WP_HTML_Processor::create_fragment()` plus HTML Processor subtree text walks.

Outcome: confirmed, with no functional regressions. All 45 subject trials
passed all hidden tests. Round score moved from the comparable round-22
current-docs medium baseline 99.45 to 99.50 (+0.05), and core moved from
99.36 to 99.42 (+0.06). Concept means: attributes 99.87, classes 100.00,
normalization 100.00, serialization 99.15, text 99.07, traversal 99.48.

The target task moved strongly: T05-text-excerpt improved from 96.70 to 99.20.
All three T05 trials now chose `WP_HTML_Processor::create_fragment()`, filtered
ordinary `#text`, and handled TITLE/TEXTAREA opener text intentionally. This
resolves the repeated round-20/21/22 failure where subjects copied the Tag
Processor lexical token walk as if it were the parsed fragment text-content
recipe.

Residual signal is now different. T03 fell from 100.00 to 98.40 and N06 stayed
at 99.00 because some subjects over-included special-element opener modifiable
text in ordinary heading/subtree text. Judges also noted T05 trials 1 and 3
used an all-or-nothing `get_last_error()` fallback for a read-only text walk,
discarding text collected before an unsupported parser abort. These are not
functional regressions in this round, but they sharpen the next text hypothesis:
ordinary subtree text means `#text` tokens by default; special-element
modifiable text and read-only abort fallback are explicit caller policies.

Next action: commit the round-23 result artifacts, then run the required state
audit. Because a source edit just landed and the post-refresh train loop has
not run a held-out checkpoint recently, prefer a checkpoint/regression
sentinel before another source edit unless the audit/protocol state says
otherwise.

## Round 22 — current-docs medium calibration restored

**Train 99.45 / core 99.36** under `weak-tier-calibration`, with subjects
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This was a no-edit calibration on the current committed docs after
round 21, run because `audit-state.py` correctly reported that the current
source docs no longer had a current-docs no-edit baseline at the default
subject policy.

Outcome: all 45 subject trials passed all hidden tests. Concept means:
attributes 100.00, classes 100.00, normalization 100.00, serialization 99.45,
text 98.43, traversal 99.50. The tier remains functionally saturated.

The calibration confirms the main residual signal from round 21:
T05-text-excerpt again scored 96.70 with all three trials passing 10/10 but
adherence 90/88/89. Judges again identified the Tag Processor lexical token
text example as competing with the processor-selection guidance that parsed
BODY-fragment text content belongs on `WP_HTML_Processor::create_fragment()`.
This is now present at both `gpt-5.4` / `low` and `gpt-5.4` / `medium`.

Follow-up citation-only probe: `round-22-tag-vs-html-text-boundary` asked
three `gpt-5.4` / `medium` subjects to choose between the Tag Processor
`next_token()` text example and `WP_HTML_Processor::create_fragment()` for
parsed BODY-fragment text-content extraction. All three chose
`create_fragment()`, cited the Tag Processor "Which processor should I use?",
"Tokens and finer-grained processing", and `get_modifiable_text()` sections,
and cited the HTML Processor DOM-style text recipe, `create_fragment()`, and
`next_token()` sections. Interpretation: the boundary facts are discoverable
when asked directly. The remaining failure mode is transfer/placement: task
agents enter through the Tag Processor text example and do not carry the
processor-choice contrast into implementation.

Next action: a narrow Tag Processor source hypothesis is justified before
more broad recipe prose. Clarify that the Tag Processor `next_token()` text
example is lexical token processing, not parsed fragment text-content
extraction, and point callers needing BODY-fragment semantics, implied closing
behavior, tree order, or unsupported-markup policy to the HTML Processor.

## Round 21 — generic HTML Processor recipes are mixed

**Train 98.97 / core 98.81** under `scored-train`, with subjects
`gpt-5.4` / `low` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored the generic main-class recipe hypothesis from commit
`27077e06b1`: add HTML Processor class-level recipes for collecting
DOM-style text from a subtree and rewriting while serializing tokens, plus a
method-local `serialize_token()` completion-policy note.

Outcome: keep for now under the protocol's revert rule, but this is not a
clean win. Round score moved from the round-20 low-effort no-edit calibration
99.43 to 98.97 (-0.46), below the 2-point revert threshold. All but one
subject trial passed all hidden tests; N03 trial 2 failed 10/11 because it
treated sequential filtered `next_tag( 'UL' )` then `next_tag( 'OL' )` calls
as alternate searches from the same cursor. Judges attributed that to missing
`WP_HTML_Processor::next_tag()` cursor/lookahead guidance, not to the recipe
edit.

The target tasks were mixed:
- T09-mark-keyword improved slightly from 98.80 to 99.20. The new
  `serialize_token()` policy avoided the exact probe failure where subjects
  rejected all incomplete trailing syntax, but judges still saw inconsistent
  fallback choices for factory failure and unsupported parser aborts.
- T05-text-excerpt fell from 96.70 to 94.40, with all three trials still
  passing hidden tests but choosing `WP_HTML_Tag_Processor` for text
  extraction. The new HTML Processor text recipe did not overcome the existing
  Tag Processor lexical-token text example, which still looks like a ready
  whole-fragment text-content recipe.
- N06 improved from 98.50 to 98.90, but two trials over-opted into special
  element text while extracting heading text, reinforcing that
  "modifiable text" is broader than ordinary parsed text.

Interpretation: a broad HTML Processor recipe block is not enough. The next
evidence-backed source hypothesis should clarify the Tag Processor text-walk
example as lexical token processing and cross-reference the HTML Processor for
parsed BODY-fragment text, implied closing behavior, tree order, and
unsupported-markup policy. Separately, `WP_HTML_Processor::next_tag()` needs a
small cursor/lookahead warning and a first-of-several-tags idiom, but that is
a different hypothesis.

## Round 20 — low-effort weak-tier calibration still saturated

**Train 99.43 / core 99.34** under `weak-tier-calibration`, with subjects
`gpt-5.4` / `low` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This was a no-edit calibration round using the round-19 source
docs to test whether one step down the subject ladder gives a less saturated
measurement instrument.

Outcome: the tier is still functionally saturated on the current train corpus.
All 45 subject trials passed all hidden tests. Concept means: attributes
100.00, classes 100.00, normalization 100.00, serialization 99.40, text
98.47, traversal 99.44.

The round does produce useful adherence-only signal, especially for generic
main-class recipe candidates:
- T05-text-excerpt was the lowest task at 96.70, with all three trials passing
  10/10 but adherence 90/88/89. Judge notes point to scattered guidance for
  DOM-style text extraction: use `WP_HTML_Processor`, filter ordinary text
  with `get_token_type() === '#text'`, skip comments and attributes, and opt
  into element-carried text only when wanted.
- N06-extract-toc scored 98.50. Trial 3 passed hidden cases but overused
  `get_modifiable_text()` on non-closing named tokens; a judge probe showed it
  would include comment text in a heading. This reinforces the same
  "where text lives" / "DOM text versus modifiable text" gap.
- T09-mark-keyword scored 98.80. Trial 3 over-applied incomplete-input and
  normalization fallback guidance after a token-rewrite loop, risking loss of
  accumulated edits. This supports a clearer token-rewrite completion policy,
  not a task-shaped example.

Interpretation: `gpt-5.4` / `low` is not a meaningfully weaker measuring
instrument for functional failures, but it strengthens the case for a
scratch-tested generic recipe block in the class-level docs: text extraction
and token-rewrite recipes should teach broad API contracts rather than solve
specific corpus tasks. Per the subject ladder, the next measurement action is
a no-edit `gpt-5.4-mini` / `high` / `priority` calibration before using weaker
tier results to promote another source docblock hypothesis.

Follow-up citation-only probe: a generic text/rewrite recipe probe at
`gpt-5.4` / `low` asked for (1) DOM-style text collection from a subtree and
(2) token-by-token rewrite completion policy when input may end incomplete or
unsupported. All three subjects found the DOM-style `#text` recipe and cited
the rendered docs correctly, but all three gave an over-conservative rewrite
policy: reject or fall back whenever `paused_at_incomplete_token()` is true.
That repeats the round-20 T09 near-miss where a rewrite loop risks discarding
already-emitted changes by re-normalizing the original HTML. The evidence
supports a narrow generic recipe/source hypothesis: token-by-token rewrites
should distinguish unsupported parser aborts from acceptable best-effort
omission of an incomplete trailing token, and should make the accumulated
output the rewrite.

## Round 19 — generic region-scan recipe lands

**Train 99.59 / core 99.53** against the current train corpus with subject
`gpt-5.4` / `medium` / `priority` and judge `gpt-5.5` / `xhigh` /
`priority`. This scored the round-18 N03 hypothesis as a source docblock edit:
add a class-level HTML Processor recipe for "scan a region before editing its
opener," plus compact method-local guard notes in `next_token()` and
`get_current_depth()`.

Outcome: N03-first-list-count moved from 85.07 to 100.00. All three trials
passed 11/11 hidden cases and received 100 adherence. The candidates used the
documented pattern directly: bookmark the opener, walk the bounded region with
`next_token()` and `get_current_depth()`, reject incomplete or unsupported
scans with `paused_at_incomplete_token()` and `get_last_error()`, seek back,
mutate with `set_attribute()`, and read with `get_updated_html()`.

All 45 subject trials passed all hidden tests. Concept means: attributes
100.00, classes 100.00, normalization 100.00, serialization 99.80, text
98.77, traversal 99.60. Small adherence-only movement on T05/T06/T08 remains
well under the revert threshold, and no previously passing task regressed
functionally.

Round-19 judge residuals are now lower-signal polish: the stale
`next_token()` "do not use" since note, a direct-child predicate
(`get_current_depth() === $parent_depth + 1`), read-only extraction policy
for partial scans, and factory/serialization fallback clarity. The measured
N03 failure is resolved.

Follow-up citation-only probe: a text-content recipe probe asked how to collect
an element's text, where SCRIPT/STYLE/TITLE/TEXTAREA contents appear, and what
not to append. All three `gpt-5.4` / `medium` subjects answered correctly and
cited `next_token()`, `get_current_depth()`, and `get_modifiable_text()`.
Interpretation: the text-location facts are discoverable when named directly;
do not promote another text recipe at this tier without weaker-tier or A/B
evidence that task code still fails by transfer rather than model judgment.

## Round 18 — current-corpus weak-tier baseline scored

**Train 98.73 / core 98.54** under the current corpus and current weak-tier
policy: subject `gpt-5.4` / `medium` / `priority`, judge `gpt-5.5` /
`xhigh` / `priority`, 15 train tasks × 3 trials. This is the first trusted
current-corpus no-edit baseline after the post-round-17 corpus refresh; round
17 remains historical and is not a comparable baseline for source edits.

The baseline is nearly saturated but still has one strong train signal:
N03-first-list-count scored 85.07, with all three trials passing 9/11 and
failing only `incomplete-token-inside-list` and
`incomplete-comment-inside-list`. Judges agreed on the root cause: subjects
used the documented HTML Processor depth-bounded subtree pattern and trusted
virtual closers as proof that the bounded region was fully scanned. The docs
do not connect that pattern to `paused_at_incomplete_token()`: after truncated
syntax at the end of input, `WP_HTML_Processor` can still emit virtual closers
while `paused_at_incomplete_token()` remains true and `get_last_error()` stays
null. The next source hypothesis should be general, not task-shaped: document
that region scans which will drive mutations must treat a depth drop as a
structural boundary only, then separately check incomplete-token and parser
error state before trusting the scan.

A focused citation-only probe against the same staged rendered docs asked
whether an HTML Processor virtual closer proves the source region was complete
when input may be truncated, and which methods to check. All three
`gpt-5.4` / `medium` probe subjects answered correctly and cited
`next_token()`, `paused_at_incomplete_token()`, `get_last_error()`, and
`get_unsupported_exception()`. Interpretation: the facts are discoverable when
the question names the issue, so the source hypothesis should be a short
placement/transfer edit near the subtree-walk and mutation examples, not a
large new concept section.

Concept means: attributes 100.00, classes 100.00, normalization 100.00,
serialization 99.90, text 99.03, traversal 96.81. Secondary non-failing gaps
remain useful as low-risk polish candidates, especially factory null/failure
fallbacks, where text lives, special-element text lists, and clearer
get_updated_html vs serialize()/serialize_token() contracts, but they should
not displace the measured N03 failure unless diagnostic probes show higher
signal at a weaker tier.

Prepared the required current-corpus weak-tier calibration round with no source
docblock edits: `round-metadata.json` records 15 train tasks and the staged
scratch directory `/tmp/html-api-docs-eval/round-18`. Scratch isolation
passed: only the two rendered docs and selected task prompts are exposed.
Local Codex CLI subject trials and judge verdicts are complete and ingested:
45/45 subject responses, hidden-test executions, 15/15 judge verdicts, and
subject-isolation attestation are persisted.

Operational note: the first local judge-runner attempt failed before producing
verdicts because the local Codex structured-output validator now requires
`additionalProperties: false` on nested object schemas. The runner schema was
fixed in a separate tooling commit, then the full judge run was rerun and
validated before ingestion.

Added a local Codex CLI trial runner to avoid deadlocking on the external
Workflow UI when it is unavailable. The runner writes the same trial-output
shape as the Workflow script, but records `subject_isolation.isolation_mode`
as `isolated-workdir`: each subject gets a private non-repo directory
containing only the two rendered docs, one task prompt, and the output schema;
the task and rendered docs are embedded directly in the subject prompt because
local `codex exec` does not expose the experiment's Read/Grep-only tools;
project rules and user config are ignored, the sandbox is read-only, and the
approval policy is `never`. Scores from this runner must be compared only with
rounds using the same isolation mode and `input_delivery:
prompt-embedded-docs`.
`audit-state.py` now prints the local runner command sequence for prepared
rounds waiting on trials, so autonomous continuations do not reinterpret that
state as an external-only Workflow gate.
Added the matching local Codex CLI judge runner for the next round-18 phase.
It uses the same judge model policy as the Workflow script, runs from the repo
root under a read-only sandbox, and writes the existing judge-output envelope
for `ingest-judges.py`.
`audit-state.py` now prints the local judge command sequence when a prepared
round is trial-complete, so the next autonomous continuation can move straight
to judging once the judge data-export approval is present.

Added `validate-round.py` as an artifact lifecycle gate. It reports whether a
round is prepared, partially trialed, trial-complete, judged, or scored, and it
lists missing trial, judge, or summary files before a score can be trusted.

Added `workflow-args.py` to emit trial and judge workflow JSON directly from
`round-metadata.json`, avoiding hand transcription of task IDs, scratch paths,
and model policy when the runner becomes available.

Hardened trial and judge ingestion plus aggregation for metadata-backed rounds:
trial outputs must match the recorded task/trial matrix, judge outputs must
cover the recorded task set, and aggregation now refuses missing judges,
missing executions, or mismatched task directories instead of silently scoring
them.

Round preparation now records SHA-256 hashes for every staged rendered doc and
task prompt. Round 18 metadata was backfilled with hashes for the staged
current-corpus baseline scratch files so the exact docs/prompts can be audited
without trusting the transient `/tmp` path alone.

Round validation and workflow argument generation now verify the recorded
scratch hashes before a prepared round is trusted or handed to agents. This
closes the remaining transient-`/tmp` drift hole: if staged rendered docs or
task prompts change after preparation, validation fails before scoring.

Round preparation now also records source-file fingerprints for the two HTML
API class files: raw source SHA-256 plus a comment/whitespace-stripped PHP
token-stream SHA-256 matching the docs-only guard invariant. Round 18 metadata
was backfilled with those fingerprints. This is infrastructure/results metadata
only; no source docblock or PHP behavior changed.

Added `validate-workflow-output.py` and wired it into trial/judge ingestion.
Workflow output files are now checked against round metadata and structured
output shape before any candidate, execution, judge, or summary file is
written.

Added `validate-corpus.py` so the corpus precondition is reproducible: active
reference implementations are run against their hidden tests before a fresh
baseline is trusted. Current result: 19 active references pass 151/151 cases;
N04 records expected unsupported-markup `wp_trigger_error()` events as
warnings, not output failures.

Updated `audit-state.py` to detect a matching prepared current-corpus
calibration round and report its lifecycle. For round 18 it now distinguishes
"baseline missing" from "round prepared; launch trials next," while still
blocking scoring on local drift or invalid scratch artifacts.

Added a `manifest` mode to `workflow-args.py`. The manifest preflights scratch
hashes and emits trial/judge workflow script paths, exact model-policy args,
and the ingest/validation command sequence for the external workflow runner.

Tightened trial workflow preflight so metadata-backed ingestion rejects
incomplete subject responses before writing partial trial directories: every
trial output must include non-empty `code` and `explanation` strings plus
integer `confidence` 0-100.

Made the trial launch isolation contract explicit in both the workflow script
and manifest: trusted scored trials require the `docs-test-subject` agent type
or an equivalent Read+Grep-only tool boundary. Prompt-only fallback must be
treated as diagnostic unless transcript isolation is recorded.

The bundled trial workflow now passes `agent_type: docs-test-subject` on each
subject `agent()` call, instead of relying only on workflow metadata, prompt
text, and returned isolation attestation to describe the required boundary.

Round 18 was restaged before launch after the tooling-only isolation commits.
The refreshed metadata now records git head `5d02b91636`; rendered-doc, task
prompt, source, and corpus file hashes stayed unchanged.

The launch manifest now reports current checkout provenance separately from
round metadata provenance, plus SHA-256 hashes for the trial and judge workflow
scripts. This avoids treating metadata's staged content ref as the workflow
execution ref after tooling-only commits.

Trial and judge ingestion now refuse to overwrite persisted artifacts. Existing
trial files, `subject-isolation.json`, `judge.json`, or `round-summary.json`
must be reconciled explicitly before a runner output can be ingested again.

`workflow-args.py` now runs `validate-corpus.py` for the exact tasks selected
in the round metadata before emitting trial, judge, or manifest payloads, so
the launch handoff cannot skip reference-fixture validation accidentally.
The manifest's human-readable preflight command now mirrors that exact task
selection instead of using a train-split shortcut.
`workflow-args.py` can also write the emitted JSON with `--output`, so the
external runner handoff can persist exact launch payloads without manual
copy/paste.

`validate-round.py` lifecycle counts now require valid artifacts. Malformed
trial files or judge verdicts no longer count toward `trials-complete` or
`judged` just because the files are present.
`persist-trials.py` now validates harness execution JSON before finalizing a
trial artifact directory, and removes the just-created trial directory if the
harness output is unusable.
That cleanup now applies to the entire current ingest attempt, preventing a
mid-batch harness failure from stranding earlier trial artifacts without a
matching isolation attestation.
`ingest-trials.py` now also writes the isolation attestation atomically and
removes the current attempt's trial directories if attestation persistence
fails.
Judge ingestion now similarly removes artifacts created by the current attempt
if judge writing, post-write validation, aggregation, or summary persistence
fails.

Tightened judge workflow preflight and schema hints so malformed judge verdicts
cannot be persisted: trial notes, failure analysis, and doc-gap fields must be
non-empty strings, and hallucinated method entries must be strings.

Round validation now verifies recorded HTML API source digests against their
recorded git ref, in addition to staged scratch hashes. This makes round 18's
metadata provenance check executable instead of merely documentary.

Round validation now also content-checks trial artifacts before reporting a
round as trial-complete: candidate files must be non-empty PHP, responses must
carry explanation/confidence, and execution files must contain harness
pass/total/cases data.

Round validation now also content-checks persisted judge artifacts before
reporting a round as judged or scored: `judge.json` files must contain exactly
the expected trial verdicts, integer adherence scores, string
hallucinated-method entries, non-empty notes, non-empty failure analysis, and
structured doc-gap fields.

Trial ingestion now rejects subject `code` payloads that do not start with
`<?php` instead of silently adding an opening PHP tag. Candidate files therefore
record the subject's actual structured answer, and malformed trial output
cannot be repaired by ingestion before scoring.

Workflow output validation now rejects malformed envelopes, non-array
`result` payloads, and non-object trial or judge entries with explicit errors.
Trial and judge ingestion run this validation before reading or persisting the
payload, keeping bad runner output from creating partial round artifacts.

For metadata-backed scored rounds, round validation now recomputes the
aggregate from persisted trial executions, judge verdicts, metadata, and corpus
labels before trusting `round-summary.json`. A hand-edited or stale summary
therefore cannot make a round appear scored.

Prepared-round metadata now records SHA-256 digests for each selected task's
`task.md`, `reference.php`, and `tests.json`; round validation checks the live
corpus files against those digests before launch or scoring. Round 18 metadata
was backfilled with these digests. `workflow-args.py` now runs the full round
preflight before emitting launch args, so drifted corpus inputs cannot be
handed to the external runner by accident.

The start-of-run audit now treats a current no-edit baseline as valid only
when it matches the current task set, subject tier, and judge tier, and when
`validate-round.py` accepts the scored artifacts. A scored round with the wrong
judge policy or invalid summary can no longer unblock source doc edits.

Round validation now checks recorded HTML API source digests against the
current worktree in addition to the recorded preparation ref. Tooling-only
commits after preparation can still proceed, but any live source docblock or
PHP behavior drift blocks launch/scoring until the round is restaged.

`workflow-args.py` now exposes the preflight bypass as `--skip-round-check`
instead of the old scratch-only wording. The legacy `--skip-scratch-check`
spelling remains accepted but hidden, because the bypass now skips source,
corpus, scratch, and artifact lifecycle checks.

Trial workflow output must now include a `subject_isolation` attestation before
ingestion. `ingest-trials.py` persists it as `subject-isolation.json`, and
round validation rejects present trial artifacts without that file. This turns
the docs-test-subject tool-boundary requirement from prompt/runbook prose into
a persisted scoring precondition. The bundled trial workflow now returns that
attestation envelope directly, and ingestion also accepts runner-wrapped saved
output where the returned envelope appears under a top-level `result` key.

## Tooling hardening for current-corpus baseline

Infrastructure-only follow-up, no source docblock edits and no PHP behavior
changes. Added `prepare-round.py` as the preferred round-preparation entry
point: it stages rendered docs, copies only selected task prompts into
scratch, and records mode/model/task metadata under the result directory.
Updated the workflow scripts and runbook to use the current model policy and
to treat the number of trials as round metadata rather than a hardcoded
three-trial assumption. This prepares the required current-corpus no-edit
baseline without creating a trusted score.

Added `audit-state.py` as a read-only start-of-run guard. It reports worktree
drift, the latest completed score, current corpus task IDs, source/tooling/corpus
changes since that score, whether a current-corpus no-edit baseline exists for
the active subject/judge policy, and the protocol-safe next action.

Added `verify-scratch-isolation.py` and wired it into `prepare-round.py` so
round staging fails before model launch if scratch contains anything beyond
the two rendered docs and selected task prompts.

## Post-round-17 corpus refresh — comparability reset before next score

Start-of-run reconciliation found that the current worktree is clean but the
active corpus no longer matches round 17's result directories. Recent commits
replaced or tightened several active tasks after the round-17 hold score:
N03, N04, N06, T07, T11, H04, plus smaller task/reference updates. Therefore
round 17 remains a trusted historical no-edit hold score for the previous
corpus, but it is not a comparable baseline for the current corpus.

Current corpus reference validation: all 19 references pass their hidden tests
locally. Scoring is paused until the next action runs a no-edit
baseline/calibration on the current corpus under the current model policy. No
PHP behavior or source docblocks were changed in this reconciliation.

Operational follow-up: round-18 docs and train task prompts were staged in
`/tmp/html-api-docs-eval/round-18`, but no trusted round-18 score was run. A
sandboxed Codex CLI smoke runner with isolated `CODEX_HOME` started under
`gpt-5.4` but failed on restricted network access to `api.openai.com`; the
unsandboxed escalation path was rejected by policy. The next run still needs a
valid current-corpus no-edit baseline before source docblock edits.

## Round 17 — Haiku, hold round (no edits): campaign-best score

**Train 98.93 — the highest of the campaign, with ZERO doc changes.**
44/45 trials passed every hidden case (one T08 7/8). The hold round
measures the noise floor: the documentation in its current state
sustains ~98–99 on pure re-sampling. Judge gap lists are reduced to
re-statements of already-documented facts in alternate locations.

Campaign stopped here at Jon's instruction (goal cleared after the
session-limit pause). Final state: 17 evaluated rounds, 24 doc
hypothesis commits, all train-driven, all execution-verified.
Held-out trajectory across checkpoints: 87.38 → 88.69 → 88.79 →
91.04 → 90.79 — improvement achieved purely through generalization,
never by editing for held-out failures.

## Round 16 — Haiku, three concepts at 100; entering hold-round protocol

**Train 97.78.** Attributes/classes/failure-handling concepts all at
100; T01 gap list empty again. Remaining variance: known single-trial
noise modes (T03's occasional '>' sample, T06/T08 single cases, judge
adherence spread). No new actionable gap.

Round 17 runs as a HOLD round — no doc edits — to measure pure
round-to-round variance and sharpen the noise floor against which
future deltas are judged.

## Round 15 — Haiku, checkpoint: T05 cured; N05 one placement away

**All-19 96.16 / train 97.59 / held-out 90.79 (flat vs 91.04 — N05's
single 0/7 trial swings the 4-task holdout mean ±10).** T05 back to
9/9×3 (construction-asymmetry note), T08 +15.5. N05's only failure
called create_full_parser() on the wrong class while otherwise
following the documented TITLE idiom — the asymmetry note exists but
not where that subject was reading.

Round-16 hypothesis (committed): one-line asymmetry reminder inside
get_modifiable_text() on the Tag Processor (placement refinement of
the same train-licensed hypothesis).

## Round 14 — Haiku, the construction-asymmetry gap crosses into train

**Train 95.92 (−2.6).** The dip is dominated by one T05 trial (1/9)
that hallucinated WP_HTML_Tag_Processor::create_fragment() — the exact
failure held-out N05 has shown since round 12, which the protocol
correctly refused to act on until train evidence appeared. It now has.
Remaining wobbles are single-case sampling noise (N06 5/7, T06 7/8,
T09 7/8).

Round-15 hypothesis (committed BEFORE this entry, after the trials but
ahead of judging): construction asymmetry stated on both classes —
new-only for the Tag Processor, factories only on the HTML Processor.
Round 15 is a held-out checkpoint; N05 should now benefit directly.

## Round 13 — Haiku, first 100% functional sweep

**Train 98.54; 45/45 trials passed 343/343 hidden cases — first fully
clean round of the campaign.** T08 +20.7 → 96.9 (implied-structure
rule), T06 +5.9 → 99.6. All remaining score variance is
adherence-judge prose assessment; judges' gap lists are now
second-order discoverability nits (the chooser is abstract; the
recipe lacks a measurement example).

Round-14 hypothesis (committed): decoded-UTF-8/mb_substr measurement
note at the recipe's accumulation point (flagged twice by T05).

## Round 12 — Haiku, checkpoint: held-out at new high

**All-19 96.05 / train 97.39 / held-out 91.04 (new high; was 88.79 at
round 9, 87.38 at the round-2 baseline).** N05 +12.4 → 70.6: two
perfect trials at last (the walk-path RCDATA note generalized); its
remaining failure is a NEW, narrower gap — a trial hallucinated
WP_HTML_Tag_Processor::create_fragment() (the factory exists only on
the HTML Processor). That construction-asymmetry gap has only ever
been flagged from held-out, so no edit — monitoring for train
evidence. T08 had one 1/8 relapse (implied-TBODY depth surprises);
T06 trials add needless is_tag_closer() guards.

Round-13 hypotheses (committed): the skip-default's consequence stated
affirmatively (no closer guard needed after plain next_tag()); implied
elements appear in walks (synthesized TBODY verified), anchor on
matched depth rather than absolute numbers.

## Round 11 — Haiku, equality-case fix lands; asymptote territory

**Train 98.28 (within noise of round-10's 98.70).** T03 +5.2 → 98.9
(the stated-causally equality rule); T09 100.0; remaining misses are
single hidden cases (T06 ×2, T08 ×1). Judge findings are now
prose-bleed nits: a trial attributed remove_class's
attribute-dropping to add_class; the quoting caveat and the
byte-preservation rule live far apart.

Round-12 hypotheses (committed): add_class add-only scope stated
contrastively; only-written-attributes-requoted co-located with
get_updated_html's contract. Round 12 is a held-out checkpoint.

## Round 10 — Haiku, T08 perfect for the first time

**Train 98.70 — new high.** T08 +10.0 → 96.8 with 8/8 in every trial
(RCDATA-on-the-walk-path + walk-to-EOF caveat completed the cursor
series begun in round 9). Failure-handling and classes at 100. The
only functional miss in the whole train set: one T03 trial (7/8) again
sampling the `>` bound; judges note the equality case (child closer
depth == ancestor opener depth) is shown numerically but never stated
as the REASON for `>=`.

Round-11 hypotheses (committed): the equality case stated causally on
get_current_depth(); empty-region flush property added to the
closer-driven state-machine note.

## Round 9 — Haiku, checkpoint: train 98.66 (high), shared-cursor fix lands

**All-19 96.58 / train 98.66 (+1.0, new high) / held-out 88.79.**
T08 +8.7 → 86.8 with no sub-50% trials (one-cursor contract +
state-machine example); T10 +2.6; 17/19 tasks functionally perfect.
N05 (58.2) is the only weak task left anywhere: subjects now apply the
well-taught walk-for-#text recipe to TITLE, where it silently returns
'' (RCDATA has no #text children — verified). The exception lived only
in get_modifiable_text(), off the walk path.

Round-10 hypothesis (committed): the RCDATA exception stated inside
next_token()'s walk guidance + the unguarded-walk-runs-to-EOF caveat
(train-licensed via T05 round-7 and T08 round-9 gaps).

## Round 8 — Haiku, UTF-8 fix lands; T08 isolated as the last functional gap

**Train 97.70 — new high.** T05 +14.0 → 99.3 (UTF-8/mb-encoding
statement); T07 at 100; T01 produced the experiment's first EMPTY
judge gap list (smoke task fully saturated). Only T08 weak (78.1,
traversal 91.3): failing trials nest collect-until-close loops which
double-advance the single shared cursor — the inner loop exits already
matched on the next region's boundary token and the outer loop's
next_token() skips it (second cell of each row dropped, rows lost).

Round-9 hypotheses (committed): the one-cursor contract on
next_token() with a verified closer-driven single-pass state-machine
example (DT terms from a DL); the last-X bookmark idiom surfaced at
the top of the bookmarks narrative (T10).

## Round 7 — Haiku, RCDATA + drain idioms land

**Train 97.51 (statistically flat vs round-6 train 97.84; nothing near
the revert threshold).** N03 → 100 (drain idiom), failure-handling
concept 100, 13/15 tasks functionally perfect across all trials.
Remaining wobbles: one T05 trial 5/9 (sliced multibyte text without an
explicit mb encoding — docs never said output is UTF-8) and T08's
boundary confusion resurfacing in break-form code that the
continue-form-only `>=` warning misses.

Round-8 hypotheses (committed): UTF-8 output statement + explicit
mb-encoding idiom on get_modifiable_text() in both classes; the
break-form boundary equivalence (break at `< depth`, never `<=`).

## Round 6 — Haiku, checkpoint: held-out generalization confirmed

**All-19 95.92 / train 97.84 (+3.1) / held-out 88.69** (vs 87.38 at the
round-2 baseline and 75.22 at round 3 — held-out now ABOVE baseline on
purely train-driven edits). T06 +24.5 and T08 +20.0 (chooser +
tree-awareness boundary landed); T04 holds at 98.7; H04 and N02 perfect.
N05 remains the only weak task (60.6): two trials still walked TITLE
looking for #text children. Its root cause is covered by a TRAIN gap
(T08 flagged that the HTML Processor's get_modifiable_text() override
documents neither decoding nor where RCDATA text lives) — so the fix is
train-driven, as the protocol requires.

Round-7 hypotheses (committed): RCDATA/raw-text contents live on the
element token, with a verified full-parser TITLE example, plus the
decoding statement, on the HTML Processor override; the >= rule beside
the operator with the nested-closer/sibling-text note inline; the
drain-all-tokens idiom on paused_at_incomplete_token(); add_class()
return = enqueued-not-applied.

## Round 5 — Haiku, template section lands; tree-awareness boundary surfaces

**Train 94.77 (+0.6).** T04 +49.2 → 98.6: all trials used the new
'Building markup from a template' section; attributes concept 74.7 →
99.3. Offsetting single-trial collapses: T06 −26.4 (one trial tried
tree-aware work in the Tag Processor — whose docs never say it lacks
depth/breadcrumbs) and T08 −15.1 (breadcrumbs-on-closer confusion);
plus one T03 trial copied the next_token() example but guessed '>'
since the >= warning lived only in get_current_depth().

Round-6 hypotheses (committed): processor-chooser sections in both
class docblocks with the no-tree-awareness boundary stated; a real
description for get_updated_html() (was a verbatim copy of
__toString's); the >= warning inline in the next_token() example.
Backlog: breadcrumbs read on a closer token (last crumb is the parent,
not the closed element); empty elements still produce closers.

## Round 4 — Haiku, serialization boundary + modifiable-text fixes

**Train 94.18 (+3.5 vs round-3 train).** T07 +35.0 → 100 (the
serialize()-vs-get_updated_html() boundary cured the induced
regression — refine-not-revert vindicated). T08 +8.1, T06 +6.3,
T10 +2.5. T04 +4.3 but still 49.4: each failing trial absorbed exactly
ONE of the two template-building facts (placeholder text OR attribute
order) — they live in distant method docblocks.

Round-5 hypotheses (committed):
1. 'Building markup from a template' overview section uniting
   pre-seeded attribute order + placeholder text, verified link-card
   example unlike any corpus task (T04).
2. next_tag() 'What this matches' contract: ASCII case-insensitive
   names, comments/rawtext never match, truncated tails never matched
   (T01/T03/T10 backlog).
3. get_attribute() returns decoded values; add_class() idempotency
   with exact byte-for-byte duplicate check (probe caught and fixed a
   wrong case-insensitivity claim before commit).
4. Why the subtree walk uses >= — deep-nesting rule, '>' failure mode
   verified (T08).

## Round 3 — Haiku, first edits under test on revised corpus (checkpoint)

**All-19 87.41 / core 85.92 / train 90.66 (−1.9) / held-out 75.22.**
Mixed: round-3 edits helped their targets — T09 +8.6, T12 +2.2, N06
+10.7 (support-claims rewrite), N04 at 100 — but the serialize_token()
idiom INDUCED a T07 regression (−33.7): two trials called serialize()
after add_class(), got null (scanning had begun), and fell back to the
unmodified input. Decision: refine, not revert, disclosed here — the
edit measurably helped its targets; the harm is one missing boundary
statement (get_updated_html() vs serialize()). T04 unchanged (45.1):
trials missed the placement note AND hit a new gap — calling
set_modifiable_text() on an empty FIGCAPTION is a silent no-op (no
#text token exists). Held-out N05 fell further (RCDATA text location;
still no edit — held-out must not drive edits, but the T04-driven
modifiable-text inventory edit covers the same general fact).

Round-4 hypotheses (committed):
1. Serialization is not how you read edits — boundary stated on
   serialize() and serialize_token(); get_updated_html() is the
   post-edit read path (T07).
2. Which tokens carry modifiable text: container elements carry none,
   empty elements cannot receive text, placeholder-template idiom,
   check the return value (T04).
3. Bookmark same-name re-set MOVES the bookmark — the last-X idiom
   (T10 adherence); also stated tag_closers default ('skip').

Train gap backlog (not yet acted on): tag-name query case-insensitivity;
comment/rawtext can't match next_tag(); add_class idempotency at the
method heading; get_attribute returns decoded values; get_namespace and
foreign-content naming; Tag-vs-HTML-Processor chooser note; multi-cell
subtree text-collection example; get_updated_html prominence in the
HTML Processor method index.

## Round 2 — Haiku re-baseline on the revised corpus

All 19 tasks × 3 Haiku trials against the round-1 docs. **All-19 91.47,
core 90.47, train 92.56, held-out 87.38.** Round-1 doc edits transfer
to Haiku: T03 and T06 (round-0's worst) are perfect.

Per-concept means (the new labels paying off — the aggregate hides
these): attributes 72.2, full-document 78.0, namespace 85.9,
traversal 91.6, vs classes/failure-handling ~99.

Diagnosed causes:
- T04 build-figure 44.3 (two 0/6 trials): output correct except src/alt
  order — set_attribute() placement rules are undocumented (verified:
  in-place update keeps position; new attributes insert after the tag
  name sorted by NAME, not call order).
- N05 document-title (held-out) one 2/7 trial: subject walked TITLE
  looking for #text children; RCDATA text lives on the tag token. No
  doc edit made — held-out must not drive edits; noted for monitoring.
- T08 adherence 55-72: the false class-docblock claims (tables/foreign
  content/head unsupported) still driving defensive fallback code.
- T09 adherence 52-76: serialize_token() purpose/idiom undocumented.

Round-3 hypotheses (committed before round 3 trials):
1. set_attribute() placement rules + order-control idiom (also fixes
   the judge-found get_next_tag() typo).
2. Correct class-level support claims with verified abort conditions
   (foster parenting, advance-rewind formatting reconstruction) and how
   aborts surface (get_last_error/get_unsupported_exception/null).
3. serialize_token() rewrite idiom with verified example.

Operational note: first judge attempt hit the account session limit and
returned zero verdicts; retried clean after reset. Isolation: trial
transcripts spot-checked, zero external reads.

## Corpus revision (after Jon's review)

Per the review: stay task-first; train was saturated for Sonnet and
clustered on a few patterns. Changes:
- Added N01 (remove class), N02 (images inside figures), N03 (detect
  truncated HTML), N04 (can-normalize failure handling), N05 (document
  title via full parser), N06 (HTML img vs SVG image). All references
  validated in the harness; N02/N05/N06 cross-checked against
  Dom\HTMLDocument (including the image→img conversion and
  img-breaks-out-of-svg parsing behaviors).
- Held-out is now N01/N02/N05/H04 (class manipulation, contextual
  selection, full-document, advanced extraction). H01–H03 retired to
  corpus-retired/. T01/T02 relabeled smoke.
- All tasks labeled (role, commonness, concept, processor);
  aggregate-round.py now reports per-concept and per-split means.
Held-out history note: round-0 held-out (93.47) was measured on the OLD
held-out set; the new set's baseline comes from the Haiku re-baseline.

## Round 1 — closer-depth semantics, next_token() rehab, decoded text

Doc edits under test (commits 58140b2235, 2d763ed14f, 0b9366fe70):
closer-token depth rule on get_current_depth()/is_tag_closer(); rewrite
of WP_HTML_Processor::next_token() with the canonical subtree-walk
example; explicit decoded-text rule on get_modifiable_text().

**TRAIN 98.78 (+5.21 vs round-0 train 93.57).** 36/36 trials passed
100% of hidden cases — the first all-green functional sweep.
- T03 +13.95 → 100: all trials now use the documented `>=` depth guard
  and several cite the new next_token() example and decoding rule
  verbatim in their explanations.
- T06 +46.33 → 99.8: the two previously-empty-result trials are gone.
- No regression beyond judge noise (T07 −0.7, T08 −0.7; threshold 2.0).
All three hypotheses confirmed; nothing reverted.

Residual signal for round 2 (adherence-only; functional is saturated
for Sonnet):
- T08 adherence stuck at 68–78: the misleading "tables unsupported"
  bullet still causes defensive fallback code; "which class do I use"
  guidance still missing.
- Judge-discovered doc bug: paused_at_incomplete_token() example calls
  nonexistent `get_next_tag()` (should be `next_tag()`).
- next_tag() contract never states it matches only real tag openers
  (comments/rawtext can't match); get_updated_html() description is a
  copy of __toString()'s and never says it applies queued edits.

Sonnet train score has now been ≥90 for two consecutive rounds — per
PLAN.md, switch the test model to Haiku and re-baseline before further
edits. Isolation: round-1 transcripts spot-checked, zero external
reads (same benign grep-on-scratch and draft-write-to-scratch pattern).

## Round 0 — baseline

Unmodified docs. All 16 tasks (12 train + 4 held-out) × 3 Sonnet trials,
to establish the train baseline and the held-out baseline for later
checkpoints. Isolation note: run from the session that created the
`docs-test-subject` agent type, so trials used a general agent with
prompt-level restriction; all 48 transcripts scanned — zero reads outside
the scratch dir (two benign Bash greps of the scratch markdown, one
solution draft written into scratch).

**TRAIN 93.57 / HELD-OUT 93.47** (scores 0–100; 0.7·pass + 0.3·adherence).

Weak spots and judge-diagnosed causes:
- T06 collect-links 53.5 (two trials 1/8) and T03 first-h1-text 86.1
  (all trials 7/8, same case) and H04 trial-3 1/7: all share one root
  cause — nothing documents that a tag-closer token reports the PARENT's
  depth (element already popped), and no doc shows the canonical
  "walk a subtree until it closes" loop. Subjects guessed
  `depth <= opener_depth` break conditions and exited subtrees early or
  collected nothing.
- T08 table-extract 92.3 but adherence only 70–77: the "Supported
  elements" bullet wrongly implies tables abort the HTML Processor, so
  subjects bolted on needless fallbacks; also get_modifiable_text()
  never states its output is entity-decoded (several subjects added a
  redundant html_entity_decode pass, risking double-decode bugs).
- T12 unwrap-spans adherence 88: the next_token()/serialize_token()
  selective-rewrite idiom is undocumented; subjects mixed it with
  whole-string normalize() unsure which was right.

Round-1 hypotheses (each its own commit):
1. Document closer-token depth semantics on get_current_depth() and
   is_tag_closer().
2. Add the canonical subtree-walk example (depth guard + breadcrumbs
   alternative) to WP_HTML_Processor::next_token() and soften its
   "use the Tag Processor instead" steer.
3. State that get_modifiable_text() returns decoded text (and
   set_modifiable_text() encodes), with a one-line example.
Deferred to round 2 (adherence-only): serialize_token() rewrite idiom;
"which class do I use" guidance; fix the tables-unsupported bullet.
