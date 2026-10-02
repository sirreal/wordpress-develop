# HTML API parsing benchmark

Measures how fast `WP_HTML_Tag_Processor` and `WP_HTML_Processor` parse documents, and whether a code change made parsing faster or slower, with a confidence interval. It is a development tool under `tests/performance/html-api/`; nothing here ships with WordPress.

## What is measured

The timed loop constructs a processor and calls `next_token()` until it returns `false`:

```php
$p = new WP_HTML_Tag_Processor( $html );              // or WP_HTML_Processor::create_full_parser( $html )
while ( $p->next_token() ) { ++$tokens; }
```

Construction is inside the timed region because real use constructs a processor per document. Nothing else is: no `get_tag()`, no attribute reads, no modifications, no output. A document the HTML Processor cannot finish (`get_last_error()` is not null) is still timed, but it is listed separately and left out of the aggregate.

Memory is a secondary metric: the single-tree table shows the allocator peak above the pre-run level for one parse (`peak_alloc_bytes` in the JSON); the JSON also carries `peak_bytes`, the delta of `memory_get_peak_usage( true )`, which moves in 2 MB steps.

The workers load the HTML API files straight from a checkout's `src/wp-includes/` with a few function stubs (`__()`, `esc_url()`, `wp_kses_uri_attributes()` and similar; see `lib/class-benchmark-bootstrap.php`). WordPress itself is not loaded, so hooks, KSES and the rest of the request lifecycle are not measured.

## Running

Single tree, from a checkout:

```
php tests/performance/html-api/bench.php
```

A/B between two checkouts:

```
php tests/performance/html-api/bench.php --base ~/a8c/wordpress-develop/trunk --head .
```

`--head` defaults to the checkout containing `bench.php`. Both checkouts use the same `worker.php` and bootstrap from the tool directory of the `bench.php` you ran, so `--base` may point at a checkout that predates this tool.

Options (`--name value` or `--name=value`):

| Option | Default | Meaning |
| --- | --- | --- |
| `--base <checkout>` | none | Baseline checkout; enables A/B mode. |
| `--head <checkout>` | the checkout containing bench.php | Checkout under test. |
| `--php <binary>` | `php` | PHP binary for the workers. |
| `--php-args "<args>"` | none | Extra arguments for both workers. |
| `--opcache` / `--no-opcache` | on when the extension is available | Adds `-d opcache.enable_cli=1`. |
| `--jit` | off | Adds `-d opcache.jit_buffer_size=64M -d opcache.jit=tracing`. |
| `--parser tag\|html\|both` | `both` | Which parser to time. |
| `--corpus <dir>` | `corpus/real` when it has files | Directory of `*.html` or `*.htm`; repeatable. The id is the file name. |
| `--no-corpus` | | Skip `corpus/real`; only directories named with `--corpus` are read. |
| `--include-optional` | | Include corpus documents whose manifest entry is `optional` (the 15 MB single-page HTML standard). |
| `--synthetic` / `--no-synthetic` | on | Include the generated shapes. |
| `--synthetic-size <bytes>` | 200000 | Target size of each generated document. |
| `--seed <int>` | 1 | Seed for generation and for the bootstrap. |
| `--filter <text>` | none | Keep ids containing the text, or matching it when written as `/regex/`. |
| `--samples <n>` | 10 | Timed samples per document, parser and tree, per round. |
| `--min-sample-ms <n>` | 25 | Each sample runs enough parses to last at least this long on the slower tree. |
| `--warmup <n>` | 2 | Untimed parses before sampling. |
| `--rounds <n>` | 4 | Repeat the whole run with a fresh pair of worker processes each time; see below. |
| `--format table\|markdown\|json` | `table` | Report format. |
| `--save <file>` | none | Write the full JSON (every sample, config, environment) whatever the format. |
| `--quiet` | | No progress on stderr. |
| `--list` | | Print document ids and sizes and exit. |

Progress goes to stderr, one line per document and parser; the report goes to stdout.

## How a run proceeds

1. `bench.php` starts one worker per tree (`php [ini flags] worker.php --checkout <root>`). Both workers stay alive for the whole run.
2. For each document and parser: the document is loaded into both workers; each runs `--warmup` untimed parses; each runs one calibration parse, and the slower of the two sets the number of parses per sample so that a sample lasts at least `--min-sample-ms`.
3. The samples are taken alternating between trees, and the tree that goes first alternates each sample (A B, B A, A B, ...). Each sample calls `gc_collect_cycles()` first, then times its parses with `hrtime( true )`.
4. The per-sample measure is nanoseconds per parse. Per document, parser and tree: median, min, mean, standard deviation, coefficient of variation (CV), MB/s and tokens/s from the median.

## Reading the output

A/B table, one row per document: size, median per tree, change in percent (head over base; negative is faster), a 95% confidence interval on that change, the verdict, and the CV of each tree's samples.

- `faster`: the whole interval is below 0%.
- `slower`: the whole interval is above 0%.
- `no difference`: the interval contains 0%. This is "not shown to differ at this precision", not "identical".

The overall line per parser is the geometric mean of the per-document ratios with its own interval, and the throughput of each tree as total bytes over the sum of the median times. Documents that bailed on either tree are listed after it and are not in the aggregate.

The CV columns say how noisy the samples were. On an otherwise idle machine expect 0.2% to 1%. Several percent means something else was running, or the machine is throttling; rerun. A row whose CV is above 10% on either tree is marked `(noisy)`: its absolute times are not usable, although the interleaving still protects the ratio when both trees were slowed alike. On Apple Silicon a worker that lands on an efficiency core while other work saturates the performance cores runs several times slower for as long as it stays there, and both workers move together; this showed up as 6x slower samples on both trees at once while other jobs ran on this machine.

### Where the interval comes from

With one round (`--rounds 1`), the interval is a bootstrap: 2000 times, draw head samples and base samples with replacement, take the ratio of their medians; the interval is the 2.5th to 97.5th percentile of those ratios. The overall interval resamples documents and then samples within each. The bootstrap is seeded from `--seed`, so a saved run recomputes to the same numbers.

The bootstrap only sees the spread between samples of one process. Two PHP processes running identical code do not run at identical speed: memory layout and scheduling give each process its own offset, which on this machine is 0.3% to 1% and which is constant for the life of the process, so interleaving cannot cancel it. When the samples are tight (CV around 0.3%), the one-round interval is narrower than that offset, and an A/A run returns `faster` or `slower` on some rows at the 0.5% level.

`--rounds N` with N of 2 or more (the default is 4) starts a fresh pair of workers for each round and repeats the whole schedule. Each round then has its own ratio of medians per document, and the interval is a t-interval over the per-round log ratios (N minus 1 degrees of freedom); the overall interval is a t-interval over the per-round geometric means. The point estimate becomes the geometric mean of the per-round ratios, and the per-tree columns show the mean of the per-round medians. With 3 rounds the interval covers changes of about 2%; with 5 rounds about 1%. Use rounds when the claim is below a few percent.

### A/A validation

Before trusting a result on a given machine, run with the same checkout on both sides:

```
php tests/performance/html-api/bench.php --base . --head .
```

Expect the overall line per parser to say `no difference` and the per-row verdicts to be `no difference` with at most the occasional row crossing 0% by a few tenths of a percent. With 64 rows at 95% confidence, a few false verdicts per run are within expectation even on a perfect machine. If many rows disagree, or the overall interval excludes 0%, the machine is too noisy: close other work, raise `--samples`, or use `--rounds 5`. With `--rounds 1` an A/A run on this machine reported `slower` at +1.8% on every HTML Processor row with CVs of 0.2%: that is the per-process offset, and it is why the default is several rounds. The second-started worker of a pair is the slower one, so the round count defaults to an even number: with 3 rounds, base starts first in two of them and an A/A run reported `slower` on 8 of 64 rows, all in the same direction; with 4 rounds the start order is balanced.

### Why interleave and bootstrap, rather than run each tree once and compare means

Machine speed drifts over seconds and minutes (thermal state, background work, frequency scaling). Two back-to-back runs put all of that drift into the difference. Alternating A and B within each sample, and alternating which goes first, puts both trees into the same drift so it cancels in the ratio. The mean of timing samples is pulled by outliers (a GC pass, a scheduler interruption); the median is not. A single difference of means has no error bar; the bootstrap gives the ratio an interval without assuming a distribution for the samples, so a reader can tell a 1% change with a 0.3% interval from a 1% change with a 4% interval.

`compare.php` exists for saved files from two separate runs. It computes the same statistics but says in its header that the runs were not interleaved, so drift between the runs is in the result.

## Synthetic documents

`lib/class-benchmark-synthetic.php` generates one document per shape, each a full document (doctype, html, head, body) sized to `--synthetic-size` within 2%, deterministic for a given shape, size and seed on every PHP version. Shape ids and what each exercises are in that file; `bench.php --list` prints them.

To add a shape: add its id to `Benchmark_Synthetic::shapes()` and a branch to `generate()` that builds the body with `mt_rand()` only (never `random_int()` or `rand()`), pads or trims to the target size, and wraps it in the shared document skeleton. Check that `WP_HTML_Processor::create_full_parser()` parses it to the end on trunk (`bench.php --filter <id> --parser html --samples 2` shows `bailed` if not), and say in the file header if it cannot.

## Real corpus

`corpus/manifest.json` lists about twenty public pages by URL with the size and sha256 of the bytes fetched on the manifest date: the WHATWG HTML standard's parsing section, the W3C HTML5 Recommendation's syntax chapter, RFC 9110, Wikipedia articles in several scripts pinned by revision id, pages rendered by WordPress (wordpress.org, developer.wordpress.org, make.wordpress.org, sirre.al), an MDN reference page, a php.net manual page and the Hacker News front page. Each entry says what the page is, whether its bytes are pinned, and whether the HTML Processor bails on it on trunk (one does: a caching-plugin comment after `</html>`). `corpus/real/` is gitignored. To fetch:

```
php tests/performance/html-api/fetch-corpus.php
```

It fetches with curl, verifies the hash, and skips documents already present. A hash mismatch on a pinned entry is an error and the file is removed; on an unpinned entry it is a warning, the file is kept, and the page is reported as drifted since the manifest date. `--include-optional` also fetches the single-page HTML standard (15 MB), which `bench.php` likewise skips unless given `--include-optional`. `--update-manifest` refetches everything, rewrites sizes, hashes and dates, and re-checks which documents bail. `bench.php` picks up `corpus/real` automatically when it has files.

## Your own documents

Point `--corpus` at any directory of `.html` files, repeatable, with `--no-synthetic` to measure only those:

```
php tests/performance/html-api/bench.php --corpus ~/pages --no-synthetic --base ~/a8c/wordpress-develop/trunk
```

## Comparing saved runs

```
php tests/performance/html-api/bench.php --save before.json
# change code
php tests/performance/html-api/bench.php --save after.json
php tests/performance/html-api/compare.php before.json after.json
```

The head tree of each file is used, matched by document id and parser. Prefer a live A/B when both checkouts are available.

## Files

- `bench.php`: the orchestrator.
- `worker.php`: a subprocess that loads one checkout's HTML API and runs timed jobs read as JSON lines on stdin, answering on stdout.
- `compare.php`: compares two `--save` files.
- `fetch-corpus.php`: restores `corpus/real/` from `corpus/manifest.json`.
- `lib/class-benchmark-bootstrap.php`: loads the HTML API from a checkout root with stubs for the WordPress functions it calls.
- `lib/class-benchmark-synthetic.php`: the shape generator.
- `lib/class-benchmark-stats.php`: median, bootstrap, t-interval, geometric mean, formatting.
- `lib/class-benchmark-report.php`: table, markdown and JSON output.

`bench.php` and `compare.php` need PHP 8.1 or newer; the workers run on whatever `--php` names, as long as the checkout's HTML API loads there.
