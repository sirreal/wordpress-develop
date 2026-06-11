# Progress for handoff-91xXCG

Source handoff: `/var/folders/v7/flqy7j3s3q72cql9ppnrbqth0000gn/T/handoff-91xXCG.md`

## Status

- [x] Confirmed no active `html-decoder-fuzz` run before editing.
- [x] Tier 1 item 1: run both decoder contexts per generated case.
- [ ] Tier 1 items 2-6.
- [ ] Tier 2 items 7-15.
- [ ] Tier 3 items 16-26.
- [ ] Cross-cutting concerns.

## Verification

- 2026-06-11: `php -l tools/html-decoder-fuzz/lib/Generator.php` passed.
- 2026-06-11: `php -l tools/html-decoder-fuzz/tests/harness-smoke.php` passed.
- 2026-06-11: `php tools/html-decoder-fuzz/tests/harness-smoke.php` passed.
- 2026-06-11: `php tools/html-decoder-fuzz/worker.php --seed 1 --cases 20 --progress-every 20` passed and reported `by_context: {"both":20}`.

## Review Log

- Tier 1 item 1:
  - Curie: APPROVE, determinism/API behavior.
  - Dewey: APPROVE, harness and fault-injection coverage.
  - Mencius: APPROVE, runtime/replay compatibility.
