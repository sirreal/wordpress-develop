# Progress for handoff-91xXCG

Source handoff: `/var/folders/v7/flqy7j3s3q72cql9ppnrbqth0000gn/T/handoff-91xXCG.md`

## Status

- [x] Confirmed no active `html-decoder-fuzz` run before editing.
- [x] Tier 1 item 1: run both decoder contexts per generated case.
- [x] Tier 1 item 2: add oracle-free arbitrary byte-space lane.
- [x] Tier 1 item 3: add reference-at-EOF generation strategy.
- [x] Tier 1 item 4: add `attribute_starts_with()` monotonicity invariants.
- [x] Tier 1 item 5: exercise multi-code-point `attribute_starts_with()` prefix paths.
- [x] Tier 1 item 6: add range-based numeric code point generation.
- [x] Tier 2 item 7: add exhaustive deterministic name sweep lane.
- [x] Tier 2 item 8: add edit-distance-1 lookalike generation.
- [x] Tier 2 item 9: add full follower-byte sweep after legacy names.
- [x] Tier 2 item 10: add prefix-family stress generation.
- [ ] Tier 2 items 11-15.
- [ ] Tier 3 items 16-26.
- [ ] Cross-cutting concerns.

## Verification

- 2026-06-11: `php -l tools/html-decoder-fuzz/lib/Generator.php` passed.
- 2026-06-11: `php -l tools/html-decoder-fuzz/tests/harness-smoke.php` passed.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 20 --progress-every 20` passed and reported `by_context: {"both":20}`.
- 2026-06-11: `php -l` passed for `Generator.php`, `Checks.php`, `Targets.php`, `worker.php`, `runner.php`, `replay.php`, `minimize.php`, and `tests/harness-smoke.php`.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --mode bytes --seed 1 --cases 200 --progress-every 200` passed.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=byte-no-amp-identity php tools/html-decoder-fuzz/worker.php --mode bytes --seed 1 --cases 200 --progress-every 200` reported findings as expected.
- 2026-06-11: `php tools/html-decoder-fuzz/runner.php --mode bytes --lanes 1 --duration-seconds 0 --max-cases 200 --cases-per-batch 200 --summary-mode none --output-dir /tmp/html-decoder-fuzz-byte-check` passed.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=byte-no-amp-identity php tools/html-decoder-fuzz/runner.php --mode bytes --lanes 1 --duration-seconds 0 --max-cases 200 --cases-per-batch 200 --max-artifacts-per-signature 1 --output-dir /tmp/html-decoder-fuzz-byte-fault-runner` reported findings as expected.
- 2026-06-11: `php tools/html-decoder-fuzz/replay.php --mode bytes --seed 1 --case 0` passed.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after byte-space lane coverage was added.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding mode-aware artifact separation, oracle-trap, and bogus-mode malformed-record coverage.
- 2026-06-11: `git diff --check` passed.
- 2026-06-11: `php -l tools/html-decoder-fuzz/lib/Generator.php` passed after adding the reference-at-EOF strategy.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500` passed and reported `reference-at-eof: 46`.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding reference-at-EOF coverage.
- 2026-06-11: Documented that adding the new weighted strategy intentionally changes generated-case `--seed --case` payload mapping; failure-manifest replay remains payload-stable.
- 2026-06-11: Verified `reference-at-eof` still ends in a reference for `max-bytes` 1, 2, 3, 4, 5, and 8 after reserving suffix space.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after tightening EOF suffix-shape coverage.
- 2026-06-11: `php -l tools/html-decoder-fuzz/lib/Checks.php`, `php -l tools/html-decoder-fuzz/lib/Targets.php`, and `php -l tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding `attribute_starts_with()` monotonicity checks.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding `attribute_starts_with()` prefix, extension, case monotonicity, and fault-target coverage.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500` passed with no real-target findings after adding `attribute_starts_with()` monotonicity checks.
- 2026-06-11: `git diff --check` passed after adding `attribute_starts_with()` monotonicity checks.
- 2026-06-11: `php -l tools/html-decoder-fuzz/lib/Checks.php`, `php -l tools/html-decoder-fuzz/lib/Generator.php`, `php -l tools/html-decoder-fuzz/lib/Targets.php`, and `php -l tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding multi-code-point `attribute_starts_with()` prefix coverage.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding byte-slice search probes, multi-code-point generator cases, and the `attribute-multicodepoint-prefix` fault target.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500` passed with no real-target findings after adding multi-code-point prefix coverage.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=attribute-multicodepoint-prefix php tools/html-decoder-fuzz/worker.php --seed 1 --start-case 681 --cases 1 --progress-every 1` reported findings as expected and verified invalid-UTF-8 search details remain JSON-safe.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=attribute-multicodepoint-prefix php tools/html-decoder-fuzz/replay.php --seed 1 --case 681` reproduced the multi-code-point prefix finding.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=attribute-multicodepoint-prefix php tools/html-decoder-fuzz/minimize.php --failure /tmp/html-decoder-fuzz-multicodepoint-fault-681/failure-seed1-case681/failure.json` minimized the finding from 18 to 6 bytes.
- 2026-06-11: `git diff --check` passed after adding multi-code-point prefix coverage.
- 2026-06-11: `php -l tools/html-decoder-fuzz/lib/Generator.php` and `php -l tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding range-based numeric code point generation.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after asserting numeric range buckets, all 32 C1 remap rows, and all 16 noncharacter planes.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500` passed with no real-target findings after adding range-based numeric code points.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=skip-c1-remap php tools/html-decoder-fuzz/worker.php --seed 1 --start-case 128 --cases 1 --progress-every 1` reported findings as expected after the range generator shifted the deterministic C1 fault case from 170 to 128.
- 2026-06-11: `git diff --check` passed after adding range-based numeric code points.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php`, `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500`, and `git diff --check` passed after addressing reviewer feedback on post-surrogate BMP coverage and multi-reference numeric smoke classification.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php`, `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500`, and `git diff --check` passed after adding explicit BMP terminal noncharacter coverage for `0xFFFE` and `0xFFFF`.
- 2026-06-11: `php -l` passed for `Cli.php`, `Generator.php`, `worker.php`, `runner.php`, `replay.php`, `minimize.php`, and `tests/harness-smoke.php` after adding the deterministic name-sweep lane.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding full-period name-sweep generator coverage plus worker, runner, and replay smoke checks for `--mode names`.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --mode names --seed 1 --cases 1000 --progress-every 1000` passed and reported `by_strategy: {"name-sweep":1000}` and `by_context: {"both":1000}`.
- 2026-06-11: `php tools/html-decoder-fuzz/replay.php --mode names --seed 1 --case 11593` passed for the deterministic `&Aacutex` case.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=attribute-semicolonless php tools/html-decoder-fuzz/replay.php --mode names --seed 1 --case 11593` reproduced the expected attribute decode mismatch for `&Aacutex`.
- 2026-06-11: `HTML_DECODER_FUZZ_FAULT=attribute-semicolonless php tools/html-decoder-fuzz/minimize.php --failure /tmp/html-decoder-fuzz-name-fault-11593/failure-seed1-case11593/failure.json` minimized the finding from 8 to 7 bytes.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding reviewer-requested checks for distinct `names` runner start-case windows and the faulted name-sweep worker/replay/minimize pipeline.
- 2026-06-11: `php -l tools/html-decoder-fuzz/lib/Generator.php` and `php -l tools/html-decoder-fuzz/tests/harness-smoke.php` passed after adding edit-distance-1 lookalike generation.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after asserting lookalike samples produce edit-distance-1 name misses and a sparse-name corpus exercises delete, insert, substitute, and transpose branches.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500` passed with no real-target findings after adding dynamic lookalikes.
- 2026-06-11: `git diff --check` passed after adding dynamic lookalikes.
- 2026-06-11: `php -l` passed for `Cli.php`, `Generator.php`, `worker.php`, `runner.php`, `replay.php`, `tests/harness-smoke.php`, `class-wp-html-decoder.php`, and `wpHtmlDecoder.php` after adding the legacy-follower sweep and ASCII-only ambiguous follower fix.
- 2026-06-11: `php tools/html-decoder-fuzz/replay.php --mode legacy-followers --seed 1 --case 124` initially reproduced a real attribute decode mismatch for `&Aacute\xC2\x80`; after replacing locale-sensitive `ctype_alnum()` with ASCII byte checks, the replay passed.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php`, `vendor/bin/phpunit --group html-api tests/phpunit/tests/html-api/wpHtmlDecoder.php`, `php tools/html-decoder-fuzz/worker.php --mode legacy-followers --seed 1 --cases 300 --progress-every 300`, `php tools/html-decoder-fuzz/runner.php --mode legacy-followers --lanes 2 --duration-seconds 0 --max-cases 200 --cases-per-batch 100 --summary-mode all --output-dir /tmp/html-decoder-fuzz-legacy-followers-check-fixed`, `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500`, and `git diff --check` passed.
- 2026-06-11: `php -l` passed for `Cli.php`, `Generator.php`, `worker.php`, `runner.php`, `replay.php`, and `tests/harness-smoke.php` after adding the prefix-family sweep mode.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed after asserting prefix-family full-period mapping over the exact expected reference set, reference splits, and ambiguous followers plus worker, runner, replay, seed-replay fault, and failure-manifest fault-pipeline coverage.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --mode prefix-families --seed 1 --cases 300 --progress-every 300`, `php tools/html-decoder-fuzz/runner.php --mode prefix-families --lanes 2 --duration-seconds 0 --max-cases 200 --cases-per-batch 100 --summary-mode all --output-dir /tmp/html-decoder-fuzz-prefix-families-runner-check`, `php tools/html-decoder-fuzz/replay.php --mode prefix-families --seed 1 --case 37`, `HTML_DECODER_FUZZ_FAULT=attribute-semicolonless php tools/html-decoder-fuzz/worker.php --mode prefix-families --seed 1 --start-case 37 --cases 1 --progress-every 1 --output-dir /tmp/html-decoder-fuzz-prefix-families-fault-check`, `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 500 --progress-every 500`, and `git diff --check` passed.

## Review Log

- Tier 1 item 1:
  - Curie: APPROVE, determinism/API behavior.
  - Dewey: APPROVE, harness and fault-injection coverage.
  - Mencius: APPROVE, runtime/replay compatibility.
- Tier 1 item 2:
  - Jason: APPROVE, byte generator/check semantics.
  - Rawls: APPROVE, CLI/artifact compatibility after mode-aware artifact keying fix.
  - Sartre: APPROVE, tests and documentation after oracle-trap and bogus-mode coverage.
- Tier 1 item 3:
  - Hegel: APPROVE, generator semantics after max-bytes suffix reservation fix.
  - Lovelace: APPROVE, smoke coverage after strict EOF suffix-shape checks.
  - Erdos: APPROVE, docs/replay compatibility after documenting generated-case mapping drift.
- Tier 1 item 4:
  - Copernicus: APPROVE, invariant semantics and exception handling.
  - Maxwell: APPROVE, fault-target and smoke coverage.
  - Poincare: APPROVE, integration/runtime compatibility.
- Tier 1 item 5:
  - Banach: APPROVE, generator and fault-target coverage.
  - Meitner: APPROVE, byte-slice search semantics and JSON-safe failure details.
  - Carver: APPROVE, worker/replay/minimize integration and runtime compatibility.
- Tier 1 item 6:
  - Kepler: APPROVE, numeric generator ranges after post-surrogate BMP coverage fix.
  - Pascal: APPROVE, numeric smoke coverage after BMP noncharacter and multi-reference fixes.
  - Beauvoir: APPROVE, integration/runtime compatibility after explicit `0xFFFE`/`0xFFFF` coverage.
- Tier 2 item 7:
  - Mendel: APPROVE, generator semantics and deterministic mapping after smoke additions.
  - Pasteur: APPROVE, CLI/worker/replay/minimize/runner integration and mode handling.
  - Popper: APPROVE, smoke and fault-pipeline coverage after requested start-window and name-fault checks.
- Tier 2 item 8:
  - Hilbert: APPROVE, generator semantics and single-edit mutation filtering after sparse smoke fix.
  - Sagan: APPROVE, smoke rigor after branch-specific sparse corpus coverage replaced inferred operation coverage.
  - Turing: APPROVE, runtime/integration compatibility and deterministic replay behavior.
- Tier 2 item 9:
  - Chandrasekhar: APPROVE, `legacy-followers` generator/mode semantics and deterministic sharding.
  - Linnaeus: APPROVE, ASCII-only ambiguous follower decoder fix and PHPUnit coverage.
  - Leibniz: APPROVE, smoke/integration coverage for full-period sweep, runner windows, and fault pipeline.
- Tier 2 item 10:
  - Gauss: APPROVE, prefix-family generator semantics after exact reference-set and replay smoke tightening.
  - Peirce: APPROVE, CLI/worker/replay/runner integration and oracle-backed deterministic sharding.
  - Noether: APPROVE, smoke coverage after requested exact reference and seed/case replay checks.
