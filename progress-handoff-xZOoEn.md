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

### Step 4: bounded `_wp_scan_utf8()` properties

- Status: done; included in the step 4 commit.
- Prior step commit: `a0f6820eb1`.
- Scope:
  - Add direct `_wp_scan_utf8()` probes for `max_bytes`, `max_code_points`, negative limits, nonzero boundary starts, invalid spans, by-ref noncharacter flag reset, and scanned-region noncharacter reporting.
  - Pin current scan semantics: valid multibyte characters that start before the byte limit are scanned whole, while invalid spans are bounded by `max_bytes`.
  - Add mutation tests for ignored `max_bytes`, noncharacter leakage from outside the scanned region, missed noncharacters inside the scanned region, ASCII fast-path overrun of `max_code_points`, and stale noncharacter flags.
- Verification:
  - `php -l tools/encoding-fuzz/lib/Checks.php`
  - `php -l tools/encoding-fuzz/lib/Targets.php`
  - `php -l tools/encoding-fuzz/lib/Bootstrap.php`
  - `php -l tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/worker.php --seed 1 --cases 200 --external none`
  - `git diff --check`
- Review gate: satisfied by 3 adversarial reviewers.
  - Reviewer 1: initially found stale `has_noncharacters` and negative-bound gaps; satisfied after adding stale-true probes and negative limit probes.
  - Reviewer 2: initially found missing false-negative noncharacter mutation coverage; satisfied after adding `scan-miss-nonchars` and selector wiring checks.
  - Reviewer 3: satisfied after checking probe volume, performance, README mutation count/list, fault list, and progress ordering.
- Commit: this step commit.

### Step 5: deterministic short-boundary corpus

- Status: done; included in the step 5 commit.
- Prior step commit: `1c208acee0`.
- Scope:
  - Add a deterministic short-boundary corpus separate from the random generator so random `(seed, case)` derivation remains stable.
  - Cover lead-byte boundary classes crossed with boundary continuation positions, adjacent invalid maximal subparts, valid/malformed sandwiches, EOF truncations, and noncharacter boundary neighbors.
  - Add a standalone corpus runner and smoke coverage for the new fixed cases.
- Verification:
  - `php -l tools/encoding-fuzz/lib/Checks.php`
  - `php -l tools/encoding-fuzz/lib/Targets.php`
  - `php -l tools/encoding-fuzz/lib/Bootstrap.php`
  - `php -l tools/encoding-fuzz/lib/Corpus.php`
  - `php -l tools/encoding-fuzz/corpus.php`
  - `php -l tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/corpus.php --external none`
  - `php -d disable_functions=utf8_encode,utf8_decode tools/encoding-fuzz/corpus.php --external none`
  - `ENCODING_FUZZ_FAULT=scan-ignore-bytes php tools/encoding-fuzz/corpus.php --external none --output-dir /tmp/encoding-fuzz-corpus-fault`
  - `php tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/worker.php --seed 1 --cases 200 --external none`
  - `git diff --cached --check`
- Review gate: satisfied by 3 adversarial reviewers.
  - Reviewer 1: initially noted byte-level dedupe hid intended labels; satisfied after preserving label-level corpus entries.
  - Reviewer 2: noted smoke skipped the new CLI/artifact path; satisfied after adding CLI smoke coverage, fail-closed artifact writes, and manual faulted artifact verification.
  - Reviewer 3: noted count/fingerprint/runtime and oracle-event ordering gaps; satisfied after pinning corpus count/fingerprint, updating smoke docs, and making CLI smoke parse NDJSON by record type.
- Commit: this step commit.

### Step 6: environment matrix

- Status: done; included in the step 6 commit.
- Prior step commit: `4005f40d3c`.
- Scope:
  - Add a compact environment matrix command that runs the fixed corpus under current environment, forced no-PCRE-u target branch, simulated PHP 9 native `utf8_encode()` / `utf8_decode()` absence, and missing primary mbstring oracle functions.
  - Add a fuzzer-only PCRE-u override in `wp-stubs.php` so the fallback `wp_has_noncharacters()` branch can be exercised without a separate PHP build.
  - Document that a true no-mbstring target run still requires a PHP build without mbstring because the local harness fails closed without its mb-backed primary oracle.
- Verification:
  - `php -l tools/encoding-fuzz/lib/Checks.php`
  - `php -l tools/encoding-fuzz/lib/Targets.php`
  - `php -l tools/encoding-fuzz/lib/Bootstrap.php`
  - `php -l tools/encoding-fuzz/lib/Cli.php`
  - `php -l tools/encoding-fuzz/lib/wp-stubs.php`
  - `php -l tools/encoding-fuzz/matrix.php`
  - `php -l tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/matrix.php`
  - `env ENCODING_FUZZ_FORCE_PCRE_U=0 php tools/encoding-fuzz/matrix.php`
  - `php tools/encoding-fuzz/corpus.php --external none`
  - `php -d disable_functions=utf8_encode,utf8_decode tools/encoding-fuzz/corpus.php --external none`
  - `php tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/worker.php --seed 1 --cases 200 --external none`
  - `git diff --cached --check`
- Review gate: satisfied by 3 adversarial reviewers.
  - Reviewer 1: initially noted PCRE override metadata and force-on risks; satisfied after adding `pcre_u_override` metadata and making the override force-off only.
  - Reviewer 2: initially found matrix pipe-deadlock, exit-code, and NDJSON-shape issues; satisfied after nonblocking pipe reads, harness-error exit `2`, and stricter record parsing.
  - Reviewer 3: initially found the matrix exit-code contract mismatch; satisfied after preserving exit `2` for harness-error-shaped failures and checking docs/progress accuracy.
- Commit: this step commit.

### Step 7: invalid-input noncharacter policy

- Status: done; included in the step 7 commit.
- Prior step commit: `a6d67b18f0`.
- Scope:
  - Do not broaden invalid-input noncharacter fuzzing.
  - Document the currently pinned divergence between the PCRE-u public path and `_wp_has_noncharacters_fallback()` on ill-formed input.
  - Record that further fuzz expansion is blocked on a Core policy decision: document `wp_has_noncharacters()` as valid-input-only or align public/fallback behavior on ill-formed input.
- Verification:
  - `php -l tools/encoding-fuzz/lib/Checks.php`
  - `php -l tools/encoding-fuzz/lib/Targets.php`
  - `php -l tools/encoding-fuzz/lib/Bootstrap.php`
  - `php -l tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/tests/harness-smoke.php`
  - `php tools/encoding-fuzz/worker.php --seed 1 --cases 200 --external none`
  - `git diff --cached --check`
  - Manual probe confirmed `wp_has_noncharacters( "\xC0\xEF\xBF\xBE" ) === false` and `_wp_has_noncharacters_fallback( "\xC0\xEF\xBF\xBE" ) === true` in the current PCRE-u environment.
- Review gate: satisfied by 3 adversarial reviewers.
  - Reviewer 1: satisfied after checking the README policy text against current public/fallback behavior and the handoff.
  - Reviewer 2: satisfied after confirming the diff is docs/progress only and does not broaden invalid-input noncharacter fuzzing.
  - Reviewer 3: satisfied after checking previous steps are complete, staged scope is limited, and this section is updated before commit.
- Commit: this step commit.
