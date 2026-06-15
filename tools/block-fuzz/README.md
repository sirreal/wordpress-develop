# Block Fuzzer

Pure PHP fuzzer for WordPress block parsing, serialization, streaming block
processing, and block attribute filtering.

The fuzzer is deterministic: seed `N` always generates the same input for the
same generator version. It uses a lightweight bootstrap that loads only the
WordPress block, KSES, and support files needed by the oracles; no database,
browser, Node, or `wp-env` is required.

## Oracles

Each generated input checks:

- `serialize_blocks( parse_blocks( $input ) )` reaches a fixed point after one
  round.
- Serialized block attribute delimiters do not expose raw `--`, `<`, `>`, `&`,
  `\"`, or `\\` in JSON string values. Literal quotes and backslashes in values
  must be serialized through unicode escapes such as `\u0022` and `\u005c`.
- `WP_Block_Processor` token scanning and full-block extraction remain bounded
  and non-fatal.
- `WP_Block_Processor::extract_full_block_and_advance()` matches
  `parse_blocks()` for generated inputs whose delimiter structure and JSON
  attributes are well formed. Intentionally invalid nesting and incomplete
  delimiters still run the non-fatal and fixed-point oracles, but skip this
  agreement check.
- `filter_block_content()` is idempotent, and every serialized block attribute
  value remaining after filtering is already clean under `wp_kses( ..., 'post' )`.

The fixture corpus mode also checks that every block fixture with a
`.serialized.html` expectation preserves its canonical serialized identity and
that the canonical output is stable.

## Commands

Run one generated seed:

```sh
php tools/block-fuzz/worker.php --seed 1
```

Run one seed and write replay artifacts:

```sh
php tools/block-fuzz/worker.php --seed 1 --output-dir artifacts/block-fuzz/seed-1
```

Run a bounded batch:

```sh
php tools/block-fuzz/runner.php --max-seeds 100
```

Run only one profile:

```sh
php tools/block-fuzz/runner.php --max-seeds 100 --profile invalid-nesting
```

Run the block fixture corpus:

```sh
php tools/block-fuzz/worker.php --fixtures
```

Replay a retained failure:

```sh
php tools/block-fuzz/replay.php --replay artifacts/block-fuzz/run-.../seed-123/replay.json
```

Useful options:

- `--max-input-bytes N`: caps generated input after generation. Truncation marks
  the seed ineligible for processor/parser extraction comparison.
- `--max-tokens N`: caps the processor token scan.
- `--max-serialized-bytes N`: caps parser and filter output growth.
- `--expect-processor-agreement=true|false`: overrides the generator metadata
  for the processor/parser extraction comparison oracle.
- `--fail-fast`: stops `runner.php` on the first failing seed.

## Artifact Layout

`runner.php` writes a run directory under `artifacts/block-fuzz/` by default:

- `run.json`: aggregate run status.
- `summary.ndjson`: one compact record per seed.
- `seed-N/input.bin`, `result.json`, and `replay.json`: retained for failing
  seeds. `input.bin` and `replay.json` are written before each seed runs, so a
  fatal error leaves enough data to replay the in-progress seed.

Passing seeds are reproducible from their seed number and profile, so they are
not retained as individual directories.
