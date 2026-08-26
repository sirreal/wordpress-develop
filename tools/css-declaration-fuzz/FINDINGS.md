# CSS declaration-list fuzzer findings

## Audited baseline run

Date: 2026-08-26

API base: `css-token-style-attr-processor` at `0f7d2823b1`

PHP: 8.5.9

The baseline process-isolated run completed 20,000 deterministic seeds with zero
invariant failures, crashes, or timeouts.

Bucket distribution:

| bucket | cases |
|---|---:|
| structured | 11,005 |
| mutated | 5,019 |
| eof-repair | 2,038 |
| raw-bytes | 1,938 |

Mutation outcomes included 17,420 successful value updates, 17,420 successful
direct removals, 17,420 successful empty-value removals, 17,081 successful
priority changes, 17,677 successful appends through each of the normal and
exhausted-cursor paths, and 2,323 safe append refusals on malformed input through
each path. All 11,005 structured cases also completed a mixed set, priority,
append, replace, remove, and advance sequence. Repeated-update supersession was
checked on 8,659 string tokens and 2,864 URL tokens. Every tokenizer token kind
was reached, including bad-string and bad-url tokens.

phpdbg line coverage over 2,000 seeds is 486/567 executable tokenizer lines
(85.7%) and 551/609 executable style-processor lines (90.5%), for 88.2% across
the declaration target. This excludes `WP_CSS_Builder`; the fuzzer exercises
its `string()` serializer as a supporting invariant but does not claim general
builder coverage.

## Core finding

The audit found that two successful `set_token_value()` calls on one URL or
string token queued overlapping replacements instead of superseding the first.
For example, updating `url(old.png)` to `one.png` and then `two.png` produced
`url("one.png""two.png")`. Foundation commit `76de39dd0c` makes updates to the
same source range replace one another and adds URL/string regression coverage.

The post-fix 20,000-case run found no additional invariant failure. This is a
baseline, not evidence of full correctness: see the oracle limitations in
`README.md`.
