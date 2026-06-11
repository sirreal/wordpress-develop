# Progress for handoff-91xXCG

Source handoff: `/var/folders/v7/flqy7j3s3q72cql9ppnrbqth0000gn/T/handoff-91xXCG.md`

## Status

- [x] Confirmed no active `html-decoder-fuzz` run before editing.
- [x] Tier 1 item 1: run both decoder contexts per generated case.
- [x] Tier 1 item 2: add oracle-free arbitrary byte-space lane.
- [x] Tier 1 item 3: add reference-at-EOF generation strategy.
- [x] Tier 1 item 4: add `attribute_starts_with()` monotonicity invariants.
- [ ] Tier 1 items 5-6.
- [ ] Tier 2 items 7-15.
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
