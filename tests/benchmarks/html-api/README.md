# HTML API Benchmarks

This directory contains local PHP benchmarks for the HTML API classes in
`src/wp-includes/html-api`.

The first benchmark operation measures base parsing speed: create a processor
for a document, then call `next_token()` until the processor is done. Additional
operations cover common getters used while traversing a document:

- `get_attribute_names_with_prefix()`
- `get_attribute()`
- `get_modifiable_text()`
- token getters such as `get_token_type()` and `get_token_name()`

Cases cover both `WP_HTML_Tag_Processor` and `WP_HTML_Processor`.

## Run Locally

```sh
php tests/benchmarks/html-api/benchmark.php
```

For a faster smoke run:

```sh
php tests/benchmarks/html-api/benchmark.php --iterations=3 --min-sample-ms=10
```

To run one case:

```sh
php tests/benchmarks/html-api/benchmark.php --case=tag-processor:block-post
```

To run one operation:

```sh
php tests/benchmarks/html-api/benchmark.php --operation=attribute-values
```

By default results are written to `artifacts/performance-results.json`. This
matches the current performance artifact shape used by
`tests/performance/compare-results.js`. Set `TEST_RESULTS_PREFIX=before` or
`TEST_RESULTS_PREFIX=base` to write prefixed artifacts for comparisons.

## Compare Against A Baseline

The comparison wrapper runs the benchmark harness from this checkout against a
baseline WordPress source tree and the current source tree on the same host.
This avoids requiring the baseline checkout to contain the benchmark files.

```sh
php tests/benchmarks/html-api/compare.php --baseline-target=/path/to/trunk/src
```

It writes:

- `artifacts/before-performance-results.json`
- `artifacts/performance-results.json`
- `artifacts/performance-results.md`

The GitHub Actions workflow checks out the current source and the baseline
source into separate directories, then uses this comparison wrapper. For pull
requests the baseline is the pull request base SHA, which tracks historic
performance on trunk or the target branch. For trunk pushes the baseline is the
previous commit.

## Methodology

The runner intentionally avoids external dependencies. It borrows the benchmark
structure used by tools such as PHPBench:

- each measured sample runs in an isolated PHP worker process;
- `hrtime(true)` is used for timing;
- each sample performs warmup work before measurement;
- revolutions are calibrated so each sample runs long enough to reduce timer
  noise;
- raw sample durations are stored in the JSON artifact;
- median, MAD, standard deviation, min, max, and environment metadata are
  recorded.

One measured revolution is:

1. Create the selected processor.
2. Call `$processor->next_token()` until it returns `false`.
3. Perform the selected operation during traversal.

Document generation and HTML API class loading happen outside the measured
section.
