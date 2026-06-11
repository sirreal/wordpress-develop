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

### Step 2: `_mb_substr()` property coverage

- Status: done; included in the step 2 commit.
- Prior step commit: `6ea247f9da`.
- Scope:
  - Load `_mb_substr()` and its `_is_utf8_charset()` dependency into the fuzzer bootstrap.
  - Add UTF-8 substring properties over valid and arbitrary input.
  - Pin current invalid-input semantics: invalid maximal subparts count as one code point, but the returned substring preserves the original bytes rather than returning scrubbed text.
  - Add explicit non-UTF-8 encoding fallback checks against byte-level `substr()`.
  - Add mutation tests for byte-offset slicing, scrubbed-input slicing, negative length handling, and non-UTF-8 fallback drift.
- Verification:
  - `php -l tools/encoding-fuzz/lib/Checks.php`
  - `php -l tools/encoding-fuzz/lib/Targets.php`
  - `php -l tools/encoding-fuzz/lib/Bootstrap.php`
  - `php -l tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/worker.php --seed 1 --cases 200 --external none`
  - `git diff --check`
- Review gate: satisfied by 3 adversarial reviewers.
  - Reviewer 1: satisfied after checking invalid-input expected substrings, negative start/length semantics, and valid native `mb_substr()` comparison.
  - Reviewer 2: satisfied after checking mutation adequacy and faulted worker/replay/minimize behavior.
  - Reviewer 3: satisfied after checking bootstrap/stub wiring, edge coverage, performance, and docs/progress accuracy.
- Commit: this step commit.

### Step 3: bounded `_wp_utf8_codepoint_count()` coverage

- Status: done; included in the step 3 commit.
- Prior step commit: `1f875a1f21`.
- Scope:
  - Add bounded `_wp_utf8_codepoint_count()` probes for negative offsets, zero lengths, oversized lengths, nonzero byte offsets, and ranges ending before/at/after code point boundaries.
  - Pin current byte-window semantics: a range ending inside a valid multibyte character or invalid maximal subpart counts the truncated prefix as one invalid subpart.
  - Add mutation tests for invalid-byte counting, range-end off-by-one behavior, and ignored byte offsets.
- Verification:
  - `php -l tools/encoding-fuzz/lib/Checks.php`
  - `php -l tools/encoding-fuzz/lib/Targets.php`
  - `php -l tools/encoding-fuzz/lib/Bootstrap.php`
  - `php -l tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/worker.php --seed 1 --cases 200 --external none`
  - `git diff --check`
- Review gate: satisfied by 3 adversarial reviewers.
  - Reviewer 1: satisfied after checking the bounded-window model, negative offsets, truncation semantics, and reference independence.
  - Reviewer 2: satisfied after checking the new mutation modes through worker/replay/minimize.
  - Reviewer 3: satisfied after checking probe coverage, performance, docs/progress accuracy, and the smoke comment cleanup.
- Commit: this step commit.
