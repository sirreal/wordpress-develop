# Experiment log

Hypothesis → outcome narrative, one entry per round. Newest first.

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
