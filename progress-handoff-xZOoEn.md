# Progress for handoff-xZOoEn

Source handoff: `/var/folders/v7/flqy7j3s3q72cql9ppnrbqth0000gn/T/handoff-xZOoEn.md`

## 2026-06-11

### Current status

- Confirmed step 0 (`_mb_chr()` / `_mb_ord()` coverage) is already committed as `9d15731f8f`.
- Worktree was clean before starting follow-up work.
- Next active slice: step 1, direct `_wp_utf8_codepoint_span()` coverage.

### Step 1: `_wp_utf8_codepoint_span()` coverage

- Status: done; included in the step 1 commit.
- Scope:
  - Add `_wp_utf8_codepoint_span()` target wiring.
  - Add span properties for scrubbed valid text and arbitrary input.
  - Start nonzero-offset checks only at known code point or maximal-subpart boundaries.
  - Add mutation tests for off-by-one span length, invalid subpart byte-counting, incorrect `found_code_points`, and stale `found_code_points` on empty spans.
- Verification:
  - `php -l tools/encoding-fuzz/lib/Checks.php`
  - `php -l tools/encoding-fuzz/lib/Targets.php`
  - `php -l tools/encoding-fuzz/lib/Bootstrap.php`
  - `php -l tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/worker.php --seed 1 --cases 200 --external none`
  - `git diff --check`
- Review gate: satisfied by 3 adversarial reviewers.
  - Reviewer 1: satisfied after checking the independent maximal-subpart span reference and the stale-count update.
  - Reviewer 2: satisfied after checking mutation adequacy, replay/minimize fault behavior, and the README clarification.
  - Reviewer 3: initially found the stale `found_code_points` gap; satisfied after the sentinel and stale-count mutation were added.
- Commit: this step commit.
