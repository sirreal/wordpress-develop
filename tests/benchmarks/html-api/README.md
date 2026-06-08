# HTML API Benchmarks

This directory contains local PHP benchmarks for the HTML API classes in
`src/wp-includes/html-api`.

The first benchmark case measures base parsing speed: create a processor for a
document, then call `next_token()` until the processor is done. Cases cover both
`WP_HTML_Tag_Processor` and `WP_HTML_Processor`.

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

By default results are written to `artifacts/performance-results.json`. This
matches the current performance artifact shape used by
`tests/performance/compare-results.js`. Set `TEST_RESULTS_PREFIX=before` or
`TEST_RESULTS_PREFIX=base` to write prefixed artifacts for comparisons.

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

Document generation and HTML API class loading happen outside the measured
section.
