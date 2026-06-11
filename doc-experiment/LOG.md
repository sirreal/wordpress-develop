# Experiment log

Hypothesis → outcome narrative, one entry per round. Newest first.

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
