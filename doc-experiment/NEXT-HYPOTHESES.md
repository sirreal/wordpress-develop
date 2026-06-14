# Next hypotheses and test strategy

This document captures the next phase after round 17. The current train
score is high enough that another ordinary "add the latest judge gap" loop
has weak signal. The next tests should deliberately lower model capability,
increase the signal density of the rendered docs, and separate content gaps
from discoverability gaps.

## Current read

Owner override after the round-67 pause asked for 10 additional reduction and
simplification ideas, each as a scratch-only `shadow-doc-a/b` variant before
any source promotion. That queue is complete in rounds 68-77; round 78
confirmed that the only aggregate/core winner, round 70, is not reproducible as
a standalone reduction; and round 79 tested the only justified salvage
variant. Keep the selected subject policy at `gpt-5.4-mini` / `low` /
`priority` with judge policy `gpt-5.5` / `xhigh` / `priority`. Compare against
the current weak-tier source-doc baseline, round 56. No tested reduction is
currently promotable.

Reduction queue:

1. Tested/rejected in round 68: property-section-only ablation removed
   rendered `Properties` sections from both docs while keeping overview and
   method docs. It cut 604 rendered lines but lost badly, **97.64 train /
   97.28 core** versus round 56's **99.61 / 99.55**, with traversal dropping
   to **93.69**. Do not promote.
2. Tested/rejected in round 69: HTML Processor non-public method detail
   ablation removed 1,093 rendered lines from `html-processor.md` under
   `## Methods` whose signatures were `private` or `protected`, while keeping
   property sections and public/inherited methods. It lost, **98.17 train /
   97.89 core** versus round 56's **99.61 / 99.55**, with serialization
   dropping to **87.85** and `T12-unwrap-spans` falling **99.30 -> 76.70**.
   Do not promote.
3. Tested/promising but not promoted in round 70: Tag Processor
   internal-parser-method ablation removed 460 rendered lines of selected
   private helper method detail from `html-tag-processor.md`, keeping overview,
   property sections, and public method docs. It beat aggregate/core,
   **99.67 train / 99.62 core** versus round 56's **99.61 / 99.55**, with all
   hidden cases passing, but it was not a clean promotion candidate because
   `T10-last-h2` fell **100.00 -> 98.20** from one HTML Processor
   processor-choice slip and `T04-build-figure` fell **100.00 -> 99.30**.
   Revisit only after the 10-candidate queue or with a confirmation/control.
4. Tested/rejected in round 71: private-method index row pruning removed only
   non-public rows from rendered method indexes, leaving method detail sections
   intact. It failed badly, **94.07 train / 93.15 core** versus round 56's
   **99.61 / 99.55**, with `N03-first-list-count` falling **99.70 -> 64.70**
   and `T12-unwrap-spans` falling **99.30 -> 53.30**. Do not promote.
5. Tested/rejected in round 72: Tag Processor design/limitations compression
   replaced the `Design and limitations`, `Scripting Flag`, and `Text
   Encoding` sections with a shorter contract summary, removing only 12
   rendered lines. It lost, **98.74 train / 98.55 core** versus round 56's
   **99.61 / 99.55**, with `N06-extract-toc` falling **99.80 -> 91.13** from
   one inline-descendant subtree-text failure. Do not promote.
6. Tested/rejected in round 73: HTML Processor unsupported-features
   compression consolidated the abort policy and unsupported cases, removing
   only 8 rendered lines. It failed badly, **97.08 train / 96.63 core** versus
   round 56's **99.61 / 99.55**, with `N03-first-list-count` falling
   **99.70 -> 65.40** from two bookmark/seek failures. Do not promote.
7. Tested/rejected in round 74: CSS-class example compression replaced the
   multi-case class before/after block with one complete lifecycle example
   showing construct, match, `add_class()`/`remove_class()`, and
   `get_updated_html()`, removing 15 rendered lines. It failed, **97.91 train /
   97.59 core** versus round 56's **99.61 / 99.55**, with
   `T12-unwrap-spans` falling **99.30 -> 76.70** from one subtree-deletion
   rewrite. Do not promote.
8. Tested/rejected in round 75: attribute-template example compression
   shortened the template-building section while preserving seeded attributes
   for output order, placeholder text for `set_modifiable_text()`, plain-value
   encoding, and `get_updated_html()`, removing 4 rendered lines. It failed,
   **97.15 train / 96.72 core** versus round 56's **99.61 / 99.55**, with
   `N03-first-list-count` falling **99.70 -> 64.40** from two unset-bookmark
   `seek()` crashes. The target `T04-build-figure` task stayed **100.00**, but
   do not promote.
9. Tested/rejected in round 76: bookmark overview/method deduplication removed
   only the shorter top-level Tag Processor bookmark overview example,
   preserving overview prose and method-local bookmark contracts/examples. It
   removed 21 rendered lines but failed, **96.98 train / 96.51 core** versus
   round 56's **99.61 / 99.55**, with `N03-first-list-count` falling
   **99.70 -> 82.15** from one unset `seek( '1' )` crash and
   `N06-extract-toc` falling **99.80 -> 78.50** from one generic PHP
   `preg_match()` mistake. The direct `T10-last-h2` bookmark task stayed
   **100.00**, but do not promote.
10. Tested/rejected in round 77: method-local inherited duplication
    compression replaced inherited flat Tag Processor helper bodies in
    `html-processor.md` with concise cross-reference stubs while leaving the
    Tag Processor originals intact and preserving headings/signatures/parameter
    tables. It removed 68 rendered lines but failed, **97.88 train / 97.55
    core** versus round 56's **99.61 / 99.55**, with traversal falling to
    **94.27**. The targeted flat helper tasks stayed strong (`T01`, `T02`,
    `T06`, and `T11` all **100.00**; `T04` **99.50**), but `N06-extract-toc`
    fell **99.80 -> 78.40** from one generic `preg_match()` bug and
    `N03-first-list-count` fell **99.70 -> 94.06** from one plain
    `next_tag()` depth-boundary scan that skipped closers. Do not promote.

Queue result: the 10-candidate reduction pass, round-70 confirmation, and one
salvage attempt are complete. Nine candidates lost against the comparable
weak-tier source-doc baseline. Round 70 was the only aggregate/core win,
**99.67 train / 99.62 core**, but round 78 repeated the exact rendered-doc
ablation and failed badly, **97.66 train / 97.30 core**. Round 79 added compact
clarifications while keeping the 460-line pruning and recovered `T10-last-h2`
to **100.00** and `T12-unwrap-spans` to **98.90**, but still failed:
**99.16 train / 99.04 core**, with `N03-first-list-count` at **94.16**. Do not
promote any tested reduction.

Latest update: round 81 tested that method-local follow-up as a scratch-only
full-train `shadow-doc-a/b` variant. Source docblocks were unchanged. The
variant added 18 rendered lines to `html-processor.md` beside
`WP_HTML_Processor::next_token()` and `get_current_depth()`: a compact
break-before-filter loop and an explicit warning that a container's own closer
may be the first depth-below-boundary token.

Result: **96.46 train / 95.92 core**, a clear loss against the current
source-doc round-80 baseline (**98.82 / 98.64**). The target task improved:
`N03-first-list-count` rose from **94.56** to **99.70**, with all three trials
passing **11/11** and following the boundary-before-filter shape. The variant
still failed overall because it damaged other tasks: `T04-build-figure` fell
to **73.07** when one trial copied a tag-only `next_token()` guard and made
the later `#text` branch unreachable, and `N06-extract-toc` fell to **80.93**
when one trial built heading entries but never appended `#text` /
`get_modifiable_text()` content.

Do not promote the round-81 method-local wording. It confirms that the N03
boundary placement can help, but the rendered example overemphasizes a
tag-only guard that weaker models transplant into mixed tag/text loops. This
is a cross-task damage signal, not a source-promotion signal.

Next action: classify as `state-reconciliation` / stop for owner review under
the signal-exhaustion rule. Keep the selected subject policy at
`gpt-5.4-mini` / `low` / `priority` and judge policy at `gpt-5.5` / `xhigh` /
`priority`. Keep source reduction paused; no tested reduction from rounds
68-79 is promotable, and the round-81 boundary-warning scratch variant is not
promotable.

Previous update: round 75 tested Tag Processor attribute-template example
compression as a full-train scratch ablation. It removed 4 rendered lines from
`html-tag-processor.md` and preserved the direct template-building task:
`T04-build-figure` stayed at **100.00**, with all three subjects choosing
`WP_HTML_Tag_Processor`. It still failed overall: **97.15 train / 96.72 core**
versus the comparable round-56 weak-tier source-doc baseline at **99.61 /
99.55**. The damage was concentrated in traversal:
`N03-first-list-count` fell **99.70 -> 64.40** because two trials called
`seek()` on bookmark names that had never been set, causing crashes on seven
hidden cases. Do not promote the attribute-template compression.

Previous update: round 74 tested Tag Processor CSS-class example compression as
a full-train scratch ablation. It removed 15 rendered lines from
`html-tag-processor.md` and preserved the direct class task, with
`T01-add-image-class` staying at **100.00**, but failed overall:
**97.91 train / 97.59 core** versus the comparable round-56 weak-tier
source-doc baseline at **99.61 / 99.55**. The damage was concentrated in
serialization: `T12-unwrap-spans` fell **99.30 -> 76.70** because one trial
used a skip-depth subtree deletion pattern instead of skipping only SPAN
opener/closer tokens. Do not promote the CSS-class example compression.

Previous update: round 73 tested HTML Processor unsupported-features
compression as a full-train scratch ablation. It removed only 8 rendered lines
from `html-processor.md` but failed badly: **97.08 train / 96.63 core** versus
the comparable round-56 weak-tier source-doc baseline at **99.61 / 99.55**.
The damage was concentrated in traversal: `N03-first-list-count` fell **99.70
-> 65.40** because two trials scanned away from the list opener and tried to
seek a bookmark that did not exist, or an internal-looking bookmark name.
`T07-nested-lists` also fell **99.40 -> 96.50** from one functionally passing
but low-adherence Tag Processor lexical-stack solution for an ancestry task.
Do not promote the unsupported-features compression.

Previous update: round 72 tested Tag Processor design/limitations compression
as a full-train scratch ablation. It removed only 12 rendered lines from
`html-tag-processor.md` but did not win: **98.74 train / 98.55 core** versus
the comparable round-56 weak-tier source-doc baseline at **99.61 / 99.55**.
The damage was concentrated in traversal: `N06-extract-toc` fell **99.80 ->
91.13** because one subject treated an inline descendant opener as ending the
current heading text subtree. `T04-build-figure` also fell **100.00 -> 97.00**
from one functionally passing trial that called `new WP_HTML_Processor(...)`
directly, and `T10-last-h2` fell **100.00 -> 98.40** from an HTML Processor
processor-choice slip. Do not promote the design/limitations compression.

Previous update: round 71 tested private/non-public method index row pruning as
a full-train scratch ablation. It removed only 58 rendered index rows while
leaving all method detail sections visible, but failed badly: **94.07 train /
93.15 core** versus the comparable round-56 weak-tier source-doc baseline at
**99.61 / 99.55**. The damage was concentrated in `N03-first-list-count`,
**99.70 -> 64.70**, from missing bookmark/seek-back patterns, and
`T12-unwrap-spans`, **99.30 -> 53.30**, from subtree-pruning instead of
boundary-token skipping. Do not promote private index-row pruning.

Previous update: round 70 tested Tag Processor private parser/helper method
detail removal as a full-train scratch ablation. It cut 460 rendered lines and
scored **99.67 train / 99.62 core** versus the comparable round-56 weak-tier
source-doc baseline at **99.61 / 99.55**, with all hidden cases passing.
However, it was not a clean source-promotion result: `T10-last-h2` fell
**100.00 -> 98.20** because one subject used `WP_HTML_Processor` for a flat
source-order class edit, and `T04-build-figure` fell **100.00 -> 99.30** from
adherence-only deductions. Keep round 70 as the best current reduction
candidate, but do not promote from it alone.

Previous update: round 69 tested HTML Processor non-public method-detail removal
as a full-train scratch ablation. It removed 1,093 rendered lines from
`html-processor.md` but did not win: **98.17 train / 97.89 core** versus the
comparable round-56 weak-tier source-doc baseline at **99.61 / 99.55**. The
damage was concentrated in serialization, with `T12-unwrap-spans` falling
**99.30 -> 76.70** because one trial used depth state to prune the whole SPAN
subtree instead of skipping only SPAN boundary tokens. Do not promote this
HTML Processor non-public method-detail ablation.

Previous update: round 68 tested property-section removal as a full-train
scratch ablation. It removed 604 rendered lines from the staged docs but did
not win: **97.64 train / 97.28 core** versus the comparable round-56
weak-tier source-doc baseline at **99.61 / 99.55**. The damage was
concentrated in traversal, with `N03-first-list-count` falling **99.70 ->
82.55** from an unset-bookmark `seek()` failure and `N06-extract-toc` falling
**99.80 -> 87.80** from depth/rank confusion and ignored closer boundaries.
Do not promote property-section removal.

Previous update: round 67 isolated the processor-choice-only simplification on
the same six-task subset as round 66. It removed only top-level roadmap/future
prose and rewrote the HTML Processor opening so flat first/last/Nth matching
tags and source-order attribute/class edits point to `WP_HTML_Tag_Processor`,
while tree position, implied structure, parsed text, and normalized output
point to `WP_HTML_Processor`. Bookmark sections and `set_bookmark()` examples
were left unchanged. It did not win: **99.15** on the six-task subset versus
**99.85** for the same round-56 weak-tier source-doc subset. Flat tasks were
perfect, including `T10-last-h2` with all three subjects choosing
`WP_HTML_Tag_Processor`, but traversal dropped: `N03` fell 99.70 -> 96.58 from
one sequential filtered-search failure, and `T07` fell 99.40 -> 98.30 from
lower-adherence traversal choices. Do not promote the processor-choice-only
wording.

Previous next action: the loop was paused under the protocol's
signal-exhaustion rule after rounds 62-67. The owner has since explicitly
overridden that pause with a request to test 10 additional reduction ideas, so
continue the scratch-only queue above.

Previous update: round 66 tested a combined scratch simplification on
`T01-add-image-class`, `T02-link-targets`, `T10-last-h2`,
`T11-strip-tracking-attributes`, `N03-first-list-count`, and
`T07-nested-lists`. It removed 61 rendered lines net by deleting
roadmap/future prose, rewriting the HTML Processor overview to stop presenting
it as the more-capable default, making the flat-source-order decision rule
explicit in both overviews, and carrying forward the round-65 bookmark
simplification. It did not win: **97.21** on the six-task subset versus
**99.85** for the same round-56 weak-tier source-doc subset. The
processor-choice cue worked for flat edits: `T01`, `T02`, `T10`, `T11`, and
`T07` all scored 100.00, and all three `T10` subjects chose
`WP_HTML_Tag_Processor`. `N03` fell 99.70 -> 83.25 because one subject counted
correctly but tried `set_attribute()` after scanning away from the list opener,
without bookmarking and seeking back. Do not promote the combined variant.

Previous update: round 65 tested a focused scratch bookmark-contract
simplification on `N03-first-list-count`, `T07-nested-lists`, and
`T10-last-h2`. It removed 46 rendered lines net and made the bookmark
preconditions explicit: set the bookmark while matched on the token to revisit,
do not use `seek()` as an existence probe, track a successful `set_bookmark()`
or use `has_bookmark()` before optional seeks, and re-set one literal bookmark
name for the last-match idiom. It did not win: **98.33** on the three-task
subset versus **99.70** for the same round-56 weak-tier source-doc subset.
`N03` improved 99.70 -> 100.00 and the unset-bookmark failure did not recur,
but `T10` fell 100.00 -> 96.30 because two of three subjects still chose
`WP_HTML_Processor` for a flat source-order class edit. Do not promote the
bookmark simplification as-is.

Previous update: round 64 tested low-risk roadmap/future-prose pruning as a
full-train scratch ablation. It removed only 17 rendered lines: the Tag
Processor file-level "Possible future direction" section and the HTML
Processor "Eventually the HTML Processor will also support" list. It did not
win: 98.25 train / 97.98 core versus the comparable round-56 source-doc score
of 99.61 / 99.55. The main regression was `N03-first-list-count`, 99.70 ->
82.65, because one subject called `seek( 'first-list' )` before any bookmark by
that name had been set, then tried to set the bookmark after scanning away from
the opener. Judges tied this to bookmark-contract ambiguity, not to the removed
roadmap prose. Do not promote roadmap prose removal from this sample.

Previous update: round 63 tested text-policy de-duplication on the focused
`T03`/`N06`/`T05`/`T06`/`T08` subset. The scratch variant kept the overview
DOM-style text recipe and compact policy table, but removed 14 rendered lines:
the duplicate special-element opt-in paragraph from `next_token()` and the
method-local special-element example from `get_modifiable_text()`. It lost
badly: 95.87 vs 99.28 for the same round-56 subset. `N06-extract-toc` fell
99.80 -> 81.13 because one subject placed `#text` handling behind a `#tag`
guard, making text collection unreachable. Do not promote this
de-duplication; the method-local text-policy reminders remain load-bearing for
repeated-subtree text extraction.

Previous update: round 62 began the new reduction directive with a scratch-only
public-API-only ablation. It removed rendered `Properties` sections and all
non-public method index/detail sections from both staged docs, cutting the
subject-visible docs from 5,265 to 3,049 lines. The full train score was
99.18 / core 99.05 at `gpt-5.4-mini` / `low` / `priority`, versus the
comparable current source-doc round 56 score of 99.61 / core 99.55. Every
trial passed every hidden case, `T08-table-extract` improved to 100.00, and
text rose slightly, but `T10-last-h2` fell from 100.00 to 95.60 because two
subjects used `WP_HTML_Processor` for a flat source-order class edit. Do not
promote the broad public-API-only ablation as-is. It shows large reductions
can preserve functional behavior, but processor-choice cues must remain
dominant for flat Tag Processor tasks.

Previous update: rounds 58/59 and 60 tested two weak-tier traversal-boundary
scratch A/B variants against the round-58 control. Both lost: the compact
closer card scored 90.74 vs 97.35, and the full bounded-loop/regional
completion recipe scored 90.18 vs 97.35. Round 61 then ran citation-only
probes on current source docs for the remaining method-local contracts:
plain `next_tag()` is not a subtree-boundary detector, bounded-region
completion does not require EOF draining for unrelated suffix markup,
breadcrumbs include the current node and breadcrumb queries are DOM sub-paths,
and `WP_HTML_Processor` should be created through `create_fragment()` or
`create_full_parser()`. All probes passed 3/3 at `gpt-5.4-mini` / `low`.
A follow-up attribute-value probe also passed 3/3 for the
`get_attribute()` return cases (`null`, `true`, `''`, decoded strings), but
subjects noted that the docs do not explicitly name the
`is_string( $value ) && '' !== $value` style predicate for usable non-empty URL
strings.

Do not promote either traversal variant, and do not promote a constructor or
breadcrumbs source edit from these probes alone. The facts are discoverable
when asked directly, and the transfer-oriented A/B variants lost.

Next action: keep the selected subject policy at `gpt-5.4-mini` / `low` /
`priority` and pause under the signal-exhaustion rule instead of adding
speculative prose.
Full-round reanalysis found no remaining non-held-out, non-noise train pattern
strong enough to justify a source docblock edit. Keep the usable-attribute
predicate as backlog unless a train task repeats the confusion; held-out N02
alone is not a source-edit driver. Resume only if the corpus changes, a future
trusted train round repeats one of the backlogged patterns, or the experiment
owner explicitly asks to test a new hypothesis despite the weak signal.

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

Round 20 calibrated the next subject setting,
`gpt-5.4` / `low` / `priority`, against the same current docs. It scored
99.43 train / 99.34 core with every hidden test passing, so this tier is still
too saturated to be the main source-edit driver. Its adherence-only signal
does support generic class-level recipe candidates, especially DOM-style text
collection and token-rewrite completion policy. The next protocol-consistent
action is a no-edit calibration one step lower, `gpt-5.4-mini` / `high` /
`priority`, or a scratch A/B for the generic recipe idea if the owner chooses
diagnostics over another ladder step.

Round 21 scored a broad HTML Processor recipe edit. It did not cross the
revert threshold, but it was not a clean win: T09 improved slightly, while T05
fell because all three subjects still chose the Tag Processor's lexical token
walk for a BODY-fragment text-content task. Treat the next text hypothesis as
processor-choice/discoverability work in the Tag Processor docs, not as more
HTML Processor recipe prose.

Round 22 restored the current-docs no-edit calibration at
`gpt-5.4` / `medium` / `priority`. It scored 99.45 with all hidden tests
passing and reproduced the same T05 signal: all three T05 trials chose
`WP_HTML_Tag_Processor`, passed hidden tests, and lost adherence because the
Tag Processor token-walk example competed with the HTML Processor
text-content guidance. This makes the Tag Processor lexical-text boundary the
best next source hypothesis.

A round-22 citation-only probe confirmed that this is placement/transfer
rather than a missing fact: all three `gpt-5.4` / `medium` subjects correctly
selected `WP_HTML_Processor::create_fragment()` for parsed BODY-fragment
text-content extraction when asked directly, and cited both the Tag Processor
lexical sections and the HTML Processor text recipe. Promote only a short
contrast near the Tag Processor text example, not another broad HTML Processor
recipe.

Round 23 confirmed that source hypothesis. The narrow Tag Processor placement
edit moved T05 from 96.70 to 99.20, and all three subjects chose
`WP_HTML_Processor::create_fragment()` for the parsed BODY-fragment text task.
All hidden tests passed across the round, with train 99.50 / core 99.42.
Treat the lexical-text boundary as resolved for now.

The next text signal is the extraction policy boundary inside the HTML
Processor docs: ordinary subtree text means `#text` tokens by default;
TITLE/TEXTAREA/SCRIPT/STYLE opener-token modifiable text is an explicit
caller opt-in; and read-only text walks need a caller policy for
`get_last_error()` or `paused_at_incomplete_token()` rather than automatically
discarding already collected text. Round-23 T03, N06, and T05 judge notes all
pointed at this shape.

Round 24 checkpoint stayed stable after the Tag Processor source edit:
99.35 all / 99.41 train / 99.12 held-out, with every hidden test passing.
T05 held at 99.80, so the processor-choice fix generalized through the
checkpoint. The next diagnostic should be citation-only, not a direct source
edit: ask whether the rendered docs already distinguish ordinary `#text`
subtree extraction, special-element opener text as opt-in, and read-only
fallback policy after `get_last_error()` or `paused_at_incomplete_token()`.
Keep the T09/T12 serialization fallback and decoded-text reparse signal as a
separate hypothesis.

The round-24 read-only text policy probe passed 3/3 at
`gpt-5.4` / `medium`: subjects found the ordinary `#text` rule, the
special-element opt-in rule, and the caller-policy distinction for read-only
fallbacks. Treat this as a placement/density problem before editing source.
The next diagnostic should be a scratch rendered-doc A/B that adds a compact
policy matrix near the HTML Processor text recipe and/or `next_token()`, then
tests whether task implementation stops over-including special-element opener
text.

Round 25/26 tested that scratch policy matrix. It raised the three-task
paired subset from 98.70 to 99.17 and made T05 perfect, but it was not a
clean source-promotion win: T03 moved from one special-element over-inclusion
in the control to three in the variant, and N06 still over-included
special-element opener text inside heading text. Treat the matrix as mixed/no
promotion. If continuing this hypothesis, test a narrower scratch variant
with a negative example that makes the default exclusion rule dominant:
ordinary heading/subtree text reads only `#text`; SCRIPT/STYLE/TITLE/TEXTAREA
opener text is explicit opt-in, not automatically part of ordinary text.

Round 27/28 tested that narrower scratch variant. It improved the paired
subset from 99.27 to 99.50, moved N06 from 98.20 to 98.90, and eliminated the
special-element over-inclusion pattern in both T03 and N06 while preserving
T05's explicit TITLE/TEXTAREA inclusion behavior. This is promotable as an
adapted source hypothesis: add default-first ordinary-text policy and
explicit opt-in wording near the HTML Processor text recipe. Do not copy the
scratch negative example's `null !== get_modifiable_text()` guard; teach
token-type/name guards instead because `get_modifiable_text()` returns a
string and is not a presence test.

Round 29 promoted that adapted source edit. It is mixed: T03 and T05 improved,
but N06 still over-included special-element opener text in all three trials.
Judges identified the method-local `next_token()` special-element paragraph as
the remaining competing cue. Keep the source edit under the revert rule, but
do not spend more source budget on broad class-level text recipes. A further
text hypothesis should be method-local and scratch-tested against the
`next_token()` wording before promotion.

Round 29 also exposed a stronger current train functional failure unrelated
to the text edit: T07 trial 2 ran one `next_tag()` scan for `UL`, then another
for `OL`, assuming the second scan restarted from the beginning. It did not;
`next_tag()` is cursor-relative. This same family appeared earlier in
N03-style sequential tag searches. Treat HTML Processor `next_tag()` cursor
semantics and first-of-several-tags idiom as a strong next source candidate.

Rounds 30/31 confirmed that candidate in scratch rendered docs, and round 32
confirmed it as a source edit. The method-local `WP_HTML_Processor::next_tag()`
card raised train from 98.31 to 99.67, recovered T07 from 81.13 to 99.30, and
kept N03 perfect. Treat the cursor/OR-search gap as resolved for now.

The next diagnostic tested the user-suggested "generic recipes in the main
class documentation" direction as a compact depth-bounded traversal card.
Rounds 33/34 showed that this was promotable after a held-out checkpoint:
variant 99.08 vs control 97.34 on N03/N06/T06/T08, with N03 recovering from
94.46 to 100.00 and T08 improving from 96.50 to 98.00. The remaining
special-element over-inclusion signal did not disappear and should stay
separate. Round 35 supplied the checkpoint: all 99.47 / train 99.50 /
held-out 99.38, with all hidden cases passing and held-out above round 24.
Round 36 confirmed the source promotion: train 99.65 / core 99.59, all 45
subject trials passed all hidden cases, N03 stayed 100.00, T07 rose to
100.00, and T08 rose to 98.50. Treat the depth/direct-child card as resolved
for now. Next action: analyze the remaining trusted judge notes and choose a
separate diagnostic; the strongest recurring candidates are the
special-element ordinary-text policy near `next_token()` /
`get_modifiable_text()` and normalized-output fallback policy for
`serialize_token()` rewriters.

Rounds 37/38 tested a method-local text-policy scratch variant near
`next_token()` and `get_modifiable_text()`. It lost 98.72 vs 99.18 on the
paired subset and did not eliminate special-element opener over-inclusion.
Do not promote that wording. The next best action is the separate
normalized-output / `serialize_token()` fallback citation-only probe.

Round 39 ran that citation-only probe. It passed 3/3: subjects found the
factory-null versus later parser-abort distinction, incomplete-token policy,
the accumulated `serialize_token()` output rule, and the warning that
`normalize( $html )` discards emitted rewrites. Treat this as evidence that
the facts are present and discoverable when directly asked. The next
diagnostic, if pursuing this hypothesis, should be scratch A/B transfer
testing on implementation tasks, not a source edit from the probe alone.

Rounds 40/41 tested that transfer with a scratch-only fallback-policy card.
The variant won 99.83 vs 99.57 on T09/T12/N04, mainly by moving T12 to
100.00 while keeping N04 perfect. T09 dipped 99.80 -> 99.50 because one
variant trial still used `normalize( $html )` after the rewrite loop, so
source promotion should adapt rather than copy the scratch wording. Next
action: run a checkpoint before promoting another source docblock edit.

Round 42 supplied that checkpoint: all 99.29 / train 99.54 / held-out 98.38,
with all 57 subject trials passing hidden cases. Held-out fell 1.0 from round
35, mostly one N05 adherence-only trial, but this is below the revert
threshold and not a source-edit driver. The promotion gate is clear. Next
action: promote one adapted source docblock hypothesis for serialization
fallback policy, emphasizing that after a `serialize_token()` rewrite loop the
accumulated string is the rewrite, while `normalize( $html )` on the original
input and raw-input return paths both abandon emitted changes unless the
caller deliberately chooses them as fallbacks.

Round 43 scored that source promotion. It was neutral, not a clean win: train
fell 99.65 -> 98.18 versus the comparable scored-train source round, below the
2-point revert threshold and without an all-trial task regression. The drop
came from one T05 PHP `preg_match_all()` bug that the judge classified as not
HTML API misuse. Serialization targets stayed stable (N04 100.00, T12 99.80,
T09 99.10) but the raw-input fallback near-miss persisted. Keep the source
edit under the revert rule, but do not immediately add more fallback-policy
source prose without a fresh diagnostic.

Rounds 44/45 revisited the text-policy transfer problem with a scratch-only
decision-table variant. The variant won 99.56 vs 98.94 on T03/T05/T06/T08/N06,
with all hidden cases passing. It eliminated the special-element opener-text
over-inclusion pattern in T03, T08, and N06, while T06 dipped only 0.5 from an
unchanged read-only partial-scan policy near-miss. Treat this as promotable
after the checkpoint gate: run a checkpoint before editing source, then promote
an adapted compact table / method-local opt-in reminder if held-out remains
stable.

Round 46 supplied that checkpoint: all 99.36 / train 99.63 / held-out 98.33,
with all 57 subject trials passing hidden cases. Held-out was effectively flat
versus round 42 and did not show a functional regression. The promotion gate is
clear. Next action: promote one adapted source docblock hypothesis for the
text-policy decision table in `WP_HTML_Processor`, keeping the compact
decision-table shape and method-local opt-in reminder while preserving the
caller-policy framing for read-only partial scans.

Round 47 confirmed that source promotion: train 99.55 / core 99.48, all 45
train trials passed hidden cases, and the ordinary `#text` vs special-element
opener-text boundary held across T03/T05/T06/T08/N06. Keep the source edit.
The remaining train near-miss is narrower: read-only extractors still often
discard already visited tokens when `paused_at_incomplete_token()` is true.
Because the fact is already present but weakly transferred, the next valid
action is a scratch rendered-doc A/B, not a direct source edit. Test a compact
read-only completion-policy note/example against T05/T06/T08/N06, with the
decision framed as best-effort extraction versus complete-source validation.

Rounds 48/49 tested that scratch variant. It won 99.65 vs 99.03 on the
paired T05/T06/T08/N06 subset with all hidden cases passing. T05 moved to
100.00, T08 to 99.80, and N06 to 100.00; T06 dipped to 98.80 because one
trial still failed closed on `get_last_error()`. Treat the note as promotable
after the checkpoint gate, but adapt rather than copy: keep it short, keep the
caller-contract framing, and do not imply that all read-only extraction should
keep partial results.

Round 50 supplied the checkpoint: all 99.08 / train 99.65 / held-out 96.93.
The held-out decline is below the revert threshold, but N02 had one functional
holdout miss from treating a breadcrumbs query as arbitrary-depth containment.
Keep that as sentinel-only evidence; held-out must not drive the next edit.
Per owner direction, pause source promotion and move to weaker-tier testing.
Next action: run a no-edit `weak-tier-calibration` on current docs with the
next protocol subject tier, `gpt-5.4` / `low` / `priority`.

Round 51 supplied that calibration: train 99.65 / core 99.59 with all 45
subject trials passing hidden cases. This tier is still saturated enough that
the remaining signal is adherence-only, concentrated in read-only completion
policy for T05/T06/N06 and normalized rewrite fallback for T09. Record
`gpt-5.4` / `low` as a current-docs no-edit baseline, but do not promote a
source edit from it. The next protocol-consistent action is to step down to
`gpt-5.4-mini` / `high` / `priority` and run another no-edit
`weak-tier-calibration`.

Round 52 supplied the `gpt-5.4-mini` / `high` calibration: train 99.53 / core
99.46, again with all 45 subject trials passing hidden cases. This tier is
also saturated. The strongest adherence-only signal is now serialization
fallback policy for string-returning `serialize_token()` rewrites: T09 scored
98.60 and T12 scored 98.90 because candidates still used raw input or
`normalize( $html )` as generic fallbacks after accumulating rewritten output.
Text extraction remained strong, with T05 and T06 at 99.60 and N06 at 99.20.
Do not promote source docs from this saturated calibration alone. The next
protocol-consistent action is to step down to `gpt-5.4-mini` / `low` /
`priority` and run one more no-edit `weak-tier-calibration`.

Round 53 supplied the final `gpt-5.4-mini` / `low` calibration: train 99.51 /
core 99.43, with all 45 subject trials still passing hidden cases. The ladder
is exhausted and still saturated, so use `gpt-5.4-mini` / `low` as the
selected weak diagnostic tier rather than looking for another model. The
strongest repeated signal is serialization fallback policy for string-returning
`serialize_token()` rewrites: T12 scored 98.60 and T09 scored 99.10, again
because candidates used raw input or `normalize( $html )` as generic recovery
after accumulating rewrite output. Next action: run a focused scratch
`shadow-doc-a/b` diagnostic on T09/T12, and optionally N04 as a normalization
control, testing a compact generic class-level recipe/card for rewrite output
and explicit fallback policy. Do not edit source docs until that variant wins.

Rounds 54/55 supplied that diagnostic. The scratch-only variant won 99.53 vs
98.87, raised serialization from 98.30 to 99.55, moved T09 from 98.50 to
99.60, and moved T12 from 98.10 to 99.50. N04 dipped from 100.00 to 99.50
because one variant trial used `create_fragment()` + `serialize()` rather than
the direct `normalize()` helper, but all N04 hidden cases still passed. The
variant eliminated the worst control behavior of rebuilding a text token from
decoded `get_modifiable_text()` plus `htmlspecialchars()`, and improved the
fallback-policy transfer. Promote an adapted source edit in
`WP_HTML_Processor`: a compact class-level string-rewrite checklist plus a
method-local `serialize_token()` wrapper / anti-pattern example. Keep fallback
wording as caller policy; do not prescribe one universal return value.

Round 56 confirmed that adapted source edit under `scored-train`:
train 99.61 / core 99.55 with subjects `gpt-5.4-mini` / `low` / `priority`.
All 45 subject trials passed hidden cases. Against the comparable weak-tier
no-edit baseline, round 53, train moved 99.51 -> 99.61, serialization moved
98.85 -> 99.35, T09 moved 99.10 -> 99.40, and T12 moved 98.60 -> 99.30. Keep
the source edit. The remaining serialization pattern is narrower: candidates
still sometimes choose `normalize( $html ) ?? $html` after a rewrite loop,
which can abandon emitted changes and return raw source bytes if normalization
fails. Record this as a future scratch-test candidate, not an immediate source
edit. Next action: run a checkpoint/regression sentinel with
`gpt-5.4-mini` / `low` / `priority` before any further source promotion.

Round 57 supplied that checkpoint: all 97.90 / train 97.95 / held-out 97.73 /
core 97.66. Two audit-only tooling commits occurred between round 56 and this
checkpoint to keep next-action selection autonomous; they did not change source
docs, corpus, runners, harness, or aggregation. The source edit stays under the
revert rule: train fell 1.66 from round 56, below the 2-point threshold, and no
task regressed across all trials. T09 held at 99.40 and T12 moved 99.30 ->
98.80. Held-out N02 exposed the valueless-attribute `true`/`''` distinction
again, but it remains sentinel-only evidence. T06's low trial was a PHP array-key
typo, not an HTML API misconception. The strongest train documentation signal is
N03: one trial used plain `next_tag()` plus `get_current_depth()` as a bounded
subtree scan, forgetting that plain `next_tag()` skips closers and therefore may
miss the depth boundary. Next action: run a focused `shadow-doc-a/b` diagnostic
on N03 and nearby traversal controls, testing a compact contrast card that
states depth-boundary scans must use `next_token()` or
`next_tag( array( 'tag_closers' => 'visit' ) )`; plain `next_tag()` skips the
closing boundary.

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

### 1. Depth-boundary equivalence card — confirmed in round 36

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

Round-33/34 scratch A/B result: the compact class-level traversal card won the
paired subset, 99.08 vs 97.34. It made subtree/direct-child checks more
mechanical without source edits: N03 went from one incomplete-token functional
miss in the control to 100.00 in the variant, T08 improved 96.50 to 98.00,
N06 was effectively flat/slightly up, and T06 had only a -0.2 adherence dip.
Round 35 checkpoint satisfied the held-out gate: all 99.47 / held-out 99.38,
with no hidden failures.

Round-36 result: source promotion confirmed. Train scored 99.65 / core 99.59
against round 32's same-mode 99.67 / core 99.62, with no functional misses.
The target traversal tasks held or improved: N03 100.00, T07 100.00, and T08
98.50. Do not spend more source-edit budget on this depth/direct-child card
unless a future weaker tier or task exposes a distinct traversal failure.

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

Round-39 citation probe result: passed 3/3 at the current subject tier.
Subjects correctly distinguished factory `null` from later `get_last_error()`,
found `paused_at_incomplete_token()` as a separate complete-input policy
check, and identified `normalize( $html )` after a token rewrite as discarding
the accumulated changes. This is not source-edit evidence by itself. Use a
scratch A/B next to test whether a compact method-local fallback card improves
T09/T12/N04 transfer.

Rounds 40/41 scratch A/B result: variant won 99.83 vs 99.57. T12 improved
98.90 -> 100.00 and N04 stayed 100.00; T09 dipped slightly because one
variant trial still normalized the original input in an error branch. This is
promotable after checkpoint, but adapt the wording to foreground the exact
anti-pattern: after a `serialize_token()` rewrite, `normalize( $html )` and
raw input both discard the accumulated rewrite and are not normalized
rewrites.

Risk: low.

### 2b. HTML Processor next_tag() cursor and OR-search contract — confirmed in round 32

Core idea: make `WP_HTML_Processor::next_tag()` cursor movement and
multi-name searches explicit near the method heading.

Contract to test:

- Each `next_tag()` search starts after the current cursor position.
- When `next_tag()` returns false, a later call with a different query will
  not rescan earlier tags.
- To find the first of several tag names, do one forward walk and branch on
  `get_tag()`, or use bookmarks/new processor instances when a true rescan is
  required.
- `tag_name` is a single tag name, not an array of alternatives.

Evidence: round 21 N03 had a sequential filtered-search failure, and round 29
T07 repeated the same cursor misconception as a functional failure: a subject
scanned for `UL`, then scanned for `OL` on the same processor and missed
earlier nested `OL` elements because the cursor was already at EOF. Judges
noted that the Tag Processor overview has the cursor warning, but the HTML
Processor `next_tag()` method docs do not make it local enough.

Probe result: `round-29-next-tag-cursor-or-search` passed 3/3. Directly asked
subjects found the cursor rule and OR-search idiom, but they cited Tag
Processor "Finding tags"/"Custom queries" and HTML Processor `next_token()`
one-cursor guidance rather than local HTML Processor `next_tag()` wording.
Treat this as a placement/transfer hypothesis. Next diagnostic: scratch
method-local `next_tag()` card near the HTML Processor method docs, then test
T07/N03-style tasks before source promotion.

Sidecar doc-location check: the cursor movement rule is currently under
Tag Processor "Finding tags" / "When matching fails"; the only OR-style idiom
is under Tag Processor "Custom queries". The rendered HTML Processor
`next_tag()` method section has neither a local cursor warning nor an
HTML Processor first-of-several-tags idiom.

Scratch A/B result: round 31's method-local `next_tag()` cursor card beat the
fresh round-30 control (99.80 vs 99.30) on N03/T07. N03 remained perfect and
T07 improved from 98.60 to 99.60, with all variant T07 trials using one
forward scan rather than sequential filtered searches. This justified a
source edit near `WP_HTML_Processor::next_tag()`.

Round-32 result: source promotion confirmed. The full train score rose from
round 29's 98.31 to 99.67, all hidden tests passed, T07 recovered to 99.30,
and N03 stayed 100.00. Do not keep spending source-edit budget here unless a
future weaker tier or checkpoint exposes a new cursor variant.

Risk: low-medium. Keep it generic and avoid a nested-list recipe; teach cursor
state and first-of-several-tags search.

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

Round-19 probe result: a direct citation-only text-content recipe probe passed
3/3 at the current `gpt-5.4` / `medium` tier. Subjects found the existing
depth-bounded `#text` accumulation recipe and the SCRIPT/STYLE/TITLE/TEXTAREA
element-token exception. Keep this as a weaker-tier or shadow-doc A/B
candidate, not the next immediate source edit at the current tier.

Round-20 calibration result: `gpt-5.4` / `low` remained functionally
saturated, but gave repeated adherence-only evidence for this hypothesis.
T05 was the lowest task (96.70) with all trials passing hidden tests but
showing uncertainty about a general DOM-style text-extraction recipe. N06 had
a passed near-miss where a subject appended `get_modifiable_text()` from
comment-like tokens. If a weaker tier exposes the same pattern functionally,
or a scratch A/B shows improvement, promote this as a generic main-class
recipe/matrix rather than a task-shaped answer.

Round-20 follow-up probe result: a direct generic recipe probe at
`gpt-5.4` / `low` found the DOM-style text recipe in all three trials, so the
text rows alone are still a placement/density hypothesis rather than a missing
fact. The same probe exposed a stronger rewrite-policy gap: all three trials
over-applied `paused_at_incomplete_token()` and recommended rejecting every
rewrite after incomplete trailing syntax, even when a best-effort normalized
rewrite of visited tokens would be acceptable. This supports promoting a
generic HTML Processor recipe that separates unsupported parser aborts from
caller policy for incomplete trailing tokens.

Round-21 result: a broad HTML Processor class-level recipe plus
`serialize_token()` policy note was mixed. The rewrite portion improved
T09-mark-keyword slightly, but the text portion did not improve processor
choice in T05; all three subjects still selected `WP_HTML_Tag_Processor`.
Before adding more text recipes, clarify the Tag Processor text-walk example
as lexical token processing and point BODY-fragment text-content callers to
`WP_HTML_Processor::create_fragment()`.

Round-23 result: the Tag Processor placement edit fixed the processor-choice
part of this hypothesis for T05. The remaining text evidence is narrower:
subjects can still over-include special element opener text in ordinary
heading/subtree extraction, and may reject all read-only text collected before
an unsupported parser abort. Promote a future source edit here only after a
checkpoint or focused probe confirms this is still the best next train signal.

Round-24 checkpoint result: held-out stayed stable and T05 held at 99.80.
N06 still showed over-inclusion of special-element opener text in ordinary
heading text, and N02 repeated the read-only `get_last_error()` partial-result
policy concern. This is now ready for a citation-only probe focused on
read-only text extraction policy.

Risk: medium-low if phrased as a token model instead of a task recipe.

### 3b. Read-only text extraction policy

Core idea: separate three caller policies that the docs currently place near
each other:

- Ordinary subtree/DOM-style text: append only tokens where
  `get_token_type() === '#text'`.
- Special element opener text (`SCRIPT`, `STYLE`, `TITLE`, `TEXTAREA`) is
  modifiable text on the element token and must be an explicit opt-in.
- After a read-only extraction walk, `get_last_error()` or
  `paused_at_incomplete_token()` tells the caller the walk stopped early or the
  input was incomplete; it does not by itself define whether to return
  already-collected best-effort text, an empty result, or a failure sentinel.

Evidence: round-23 T03/N06 over-included special-element opener text in
ordinary heading/subtree extraction; round-23 T05 sometimes discarded collected
text after an unsupported parser abort. Round-24 repeated the N06
over-inclusion pattern and N02 repeated the read-only partial-result policy
concern. All hidden tests still passed, so this needs a citation-only probe
before source promotion.

Next diagnostic: ask subjects to cite the rendered docs for a read-only
fragment text extractor that collects ordinary subtree text, decides whether
to include TITLE/TEXTAREA/SCRIPT/STYLE opener text, and states a caller policy
for `get_last_error()` and `paused_at_incomplete_token()`.

Probe result: passed 3/3. Directly asked subjects cited the existing
`Recipe: collect DOM-style text from a subtree`, `next_token()`, and Tag
Processor lexical-boundary sections, and correctly answered that ordinary text
uses `#text` only, special-element opener text is opt-in, and read-only
fallback is caller policy. Do not promote source prose yet; test whether a
scratch-only policy matrix improves transfer in task code.

Scratch A/B result: mixed/no promotion. Round 26's policy matrix improved the
paired subset numerically versus round 25 (99.17 vs 98.70) and fixed T05
adherence, but it also encouraged all three T03 subjects to include
SCRIPT/STYLE/TITLE/TEXTAREA opener text in ordinary heading text. N06 remained
the target near-miss, with all three variant candidates still over-including
special-element text. A promotable source edit needs sharper negative
placement: ordinary `#text` is the default; special-element opener text is
available for explicit caller contracts only.

Follow-up scratch A/B result: round 28's default-first negative-example
variant beat the fresh round-27 control (99.50 vs 99.27). The target behavior
changed in the right direction: control T03/N06 still over-included
special-element opener text, while variant T03/N06 used ordinary `#text` only;
T05 still correctly opted into TITLE/TEXTAREA while excluding SCRIPT/STYLE.
Promote an adapted source edit now. Keep it generic and avoid the scratch
variant's misleading null-check negative example.

Source result: round 29 was mixed. T03/T05 improved after promotion, but N06
still over-included special-element opener text, with judges pointing at the
`next_token()` method-local special-element paragraph rather than the overview
recipe. If this hypothesis is revisited, use a scratch A/B that rewrites that
method-local paragraph to say "only if the caller's definition of text includes
special-element contents" and points back to the ordinary subtree-text recipe.

Follow-up scratch A/B result: rounds 37/38 tested that method-local rewrite
plus a `get_modifiable_text()` warning. The variant lost 98.72 vs 99.18 and
did not remove the target over-inclusion pattern. Do not promote this wording;
any future text-policy attempt needs a different shape, likely a compact
decision table or a task-independent token-category matrix, and should not be
mixed with serialization fallback guidance.

Risk: medium. Avoid replacing the processor-choice win with a task-shaped text
recipe. Phrase the edit, if promoted, as a token/policy matrix.

### 3a. Tag Processor lexical-text boundary — confirmed in round 23

Core idea: the Tag Processor docs contain a useful `next_token()` text example
that is lexical, not parsed-tree textContent. Label it that way and
cross-reference the HTML Processor when the caller needs BODY-fragment
semantics, implied closing behavior, tree order, or unsupported-markup policy.

Evidence: T05 in both round 20 and round 21 passed functionally but selected
`WP_HTML_Tag_Processor` in all three trials. Round-21's added HTML Processor
text recipe did not change this; judges identified the Tag Processor
"Tokens and finer-grained processing" example as the stronger entry point.
Round 22 reproduced the same T05 behavior at `gpt-5.4` / `medium`, so the
signal is no longer only low-effort noise.

Round-22 probe result: direct citation-only questioning passed 3/3 at
`gpt-5.4` / `medium`. Subjects found the processor boundary when prompted,
so the source hypothesis should improve transfer at the Tag Processor example
itself rather than add more facts elsewhere.

Round-23 result: confirmed. T05 improved from 96.70 to 99.20, and all three
subjects chose `WP_HTML_Processor::create_fragment()` for parsed fragment text
extraction. Do not keep spending source-edit budget here unless a future tier
or checkpoint exposes a new variant.

Risk: low-medium. Avoid saying the Tag Processor cannot read text; it can read
lexical token text. The distinction is parsed fragment/DOM semantics versus
flat lexical scanning.

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
