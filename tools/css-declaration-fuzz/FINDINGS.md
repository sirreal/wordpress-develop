# CSS declaration-list fuzzer findings

## Initial run

Date: 2026-08-26

API base: `css-token-style-attr-processor` at `62b1cbdcea`

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
each path. Every
tokenizer token kind was reached, including bad-string and bad-url tokens.

phpdbg line coverage over 2,000 seeds is 454/560 executable tokenizer lines
(81.1%) and 551/609 executable style-processor lines (90.5%), for 86.0% across
the declaration target. This excludes `WP_CSS_Builder`; the fuzzer exercises
its `string()` serializer as a supporting invariant but does not claim general
builder coverage.

No Core finding is recorded from this run. This is a baseline, not evidence of
full correctness: see the oracle limitations in `README.md`.
