# HTML API parsing benchmark: design spec (2026-10-01)

Worktree: ~/a8c/wordpress-develop/html-api-benchmark, branch `html-api/benchmark`, off origin/trunk 4277884c40.
Tool root: `tests/performance/html-api/` in that worktree. Everything below is relative to the tool root unless stated.
PHP on this machine: `php` is 8.5.11; `/opt/homebrew/opt/php@8.4/bin/php` is 8.4. No 7.4. Code must still be PHP 7.2-compatible in syntax where it loads core files, but the tool itself may require PHP 8.1+ (it is a dev tool, not shipped code). Use WordPress coding style (tabs, snake_case, Yoda conditions, spaces inside parens) so it passes the repo's phpcs if ever run.

## Purpose

Measure parsing throughput of `WP_HTML_Tag_Processor` and `WP_HTML_Processor` with enough rigor to say whether a code change made parsing faster or slower, and by how much, with a confidence interval. Parsing only: the timed loop calls `next_token()` until it returns false. No `get_tag()`, no attribute reads, no modifications in the timed loop.

## Layout

```
tests/performance/html-api/
  README.md                 usage, design, how to read the output, how to add a shape
  bench.php                 CLI entry: orchestrates workers, collects samples, computes stats, prints report
  worker.php                subprocess: loads one checkout's HTML API, runs timed jobs read from stdin as JSON lines, writes JSON lines to stdout
  compare.php               compares two saved JSON result files (no interleaving; weaker than live A/B, says so in output)
  fetch-corpus.php          restores corpus/real/*.html from corpus/manifest.json via HTTP range requests to data.commoncrawl.org
  lib/
    class-benchmark-bootstrap.php   loads the HTML API files from a given checkout root plus WordPress function stubs
    class-benchmark-synthetic.php   seeded synthetic document generator
    class-benchmark-stats.php       median, bootstrap CI, geometric mean, formatting
    class-benchmark-report.php      table / markdown / json output
  corpus/
    manifest.json           Common Crawl record pointers + sha256 of expected body (committed)
    real/                   fetched documents (gitignored)
    .gitignore              `real/`
```

## Bootstrap (lib/class-benchmark-bootstrap.php)

`Benchmark_Bootstrap::load( string $checkout_root ): void` requires, in this order, from `$checkout_root`:

```
src/wp-includes/compat.php
src/wp-includes/compat-utf8.php     (only if the file exists; older trunks lack it)
src/wp-includes/utf8.php            (only if exists)
src/wp-includes/class-wp-token-map.php
src/wp-includes/html-api/html5-named-character-references.php
src/wp-includes/html-api/class-wp-html-attribute-token.php
src/wp-includes/html-api/class-wp-html-span.php
src/wp-includes/html-api/class-wp-html-doctype-info.php
src/wp-includes/html-api/class-wp-html-text-replacement.php
src/wp-includes/html-api/class-wp-html-decoder.php
src/wp-includes/html-api/class-wp-html-tag-processor.php
src/wp-includes/html-api/class-wp-html-unsupported-exception.php
src/wp-includes/html-api/class-wp-html-active-formatting-elements.php
src/wp-includes/html-api/class-wp-html-open-elements.php
src/wp-includes/html-api/class-wp-html-token.php
src/wp-includes/html-api/class-wp-html-stack-event.php
src/wp-includes/html-api/class-wp-html-processor-state.php
src/wp-includes/html-api/class-wp-html-processor.php
```

Before those, define stubs guarded by `function_exists`: `__`, `_doing_it_wrong` (no-op), `_deprecated_argument`, `wp_trigger_error`, `esc_url` (identity), `wp_kses_uri_attributes` (the usual list), `wp_scrub_utf8` (identity if missing; check whether utf8.php defines it). Reference: the fuzz harness stubs at ~/a8c/wordpress-develop/html-api-commoncrawl/tools/html-api-fuzz/lib/wp-stubs.php. If a required file is missing in the checkout, fail with a clear message naming the file.

## Worker protocol (worker.php)

Started as `php [ini flags] worker.php --checkout <root>`. Loads bootstrap once. Then reads one JSON object per line on stdin and answers one JSON object per line on stdout. The worker never writes anything else to stdout; diagnostics go to stderr.

Request kinds:

- `{"op":"hello"}` → `{"ok":true,"php":"8.5.11","opcache":bool,"jit":"<opcache.jit value or null>","checkout":"<root>","head":"<git rev-parse --short HEAD of checkout or null>"}`
- `{"op":"load","id":"<doc id>","path":"<file>"}` or `{"op":"load","id":"...","html":"<inline html>"}` → stores the document in memory keyed by id; replies `{"ok":true,"id":..., "bytes":N}`.
- `{"op":"calibrate","id":"...","parser":"tag"|"html"}` → runs one parse, replies `{"ok":true,"ns":<hrtime ns>,"tokens":N,"bailed":null|"<reason>"}`.
- `{"op":"run","id":"...","parser":"tag"|"html","iterations":N}` → runs N full parses back to back inside one hrtime window, replies `{"ok":true,"ns":<total ns>,"tokens":<tokens from the last parse>,"bailed":null|"<reason>","peak_bytes":<memory_get_peak_usage(true) delta observed, int>}`. Before timing, call `gc_collect_cycles()`. Create a fresh processor per iteration (construction is part of parsing a document in real use; keep it in).
- `{"op":"quit"}` → exits 0.

Tag Processor parse: `$p = new WP_HTML_Tag_Processor( $html ); while ( $p->next_token() ) { ++$tokens; }`.
HTML Processor parse: `$p = WP_HTML_Processor::create_full_parser( $html ); while ( $p->next_token() ) { ++$tokens; }` then if `$p->get_last_error() !== null` the parse bailed: report `get_last_error()` plus `get_unsupported_exception()?->getMessage()` as the reason. For older checkouts without `create_full_parser`, fall back to `create_fragment`. A bailed document is still timed (time to bail), but bench.php reports it separately (see Report).

Memory: `memory_get_peak_usage(true)` before and after; report peak. Secondary metric only.

## Orchestrator (bench.php)

Options (long form, `--name=value` or `--name value`):

- `--head <checkout>` defaults to the checkout containing bench.php (resolve by walking up to the dir containing `src/wp-includes/html-api`).
- `--base <checkout>` optional. When given, A/B mode. When omitted, single-tree mode.
- `--php <binary>` default `php`. `--php-args "<extra args>"` passed to both workers.
- `--opcache` (default on if the opcache extension is available) adds `-d opcache.enable_cli=1`; `--no-opcache`. `--jit` adds `-d opcache.jit_buffer_size=64M -d opcache.jit=tracing`; default off. The report header prints what was in effect.
- `--parser tag|html|both` default both.
- `--corpus <dir>` repeatable; default `corpus/real` if it exists and is non-empty. Reads `*.html` and `*.htm`, document id is the filename.
- `--synthetic` / `--no-synthetic` default on. `--synthetic-size <bytes>` default 200000. `--seed <int>` default 1.
- `--filter <substring or regex>` restrict document ids.
- `--samples N` default 20: number of timed samples per (document, parser, tree).
- `--min-sample-ms N` default 25: iterations per sample are calibrated so a sample lasts at least this long on the slower tree (one calibration parse per tree, take the max, iterations = max(1, ceil(min_sample_ms / ms_per_parse))).
- `--warmup N` default 2 untimed parses per (document, parser, tree) before sampling.
- `--format table|markdown|json` default table. `--save <file>` writes the full JSON (every sample, config, environment) regardless of format. `--quiet`.
- `--list` prints document ids and sizes and exits.

Scheduling: both workers start at once and stay alive for the whole run. For each (document, parser): load into both, warm up both, calibrate, then for s in 1..samples: run on A, run on B, alternating which goes first each sample (ABBA ordering: sample 1 A then B, sample 2 B then A, ...). This interleaving is the point of the design: machine noise drifts over time and alternating cancels drift. Print progress to stderr with a one-line-per-document status unless `--quiet`.

In single-tree mode, run the same loop with one worker.

## Statistics (lib/class-benchmark-stats.php)

Per sample, the measure is ns per parse = ns / iterations. Per (document, parser, tree): median, min, mean, stddev, CV (stddev/mean), and throughput MB/s = bytes / median, tokens/s.

A/B per document: ratio = median_head / median_base (<1 is faster). 95% CI from a bootstrap of the ratio of medians: 2000 resamples, each drawing with replacement from head samples and base samples, percentile interval. Verdict: `faster` if the whole CI is below 1, `slower` if whole CI above 1, else `no difference`. Also report the point change as a percentage.

Overall per parser: geometric mean of per-document ratios, with a bootstrap CI (resample documents and samples). Also overall throughput: sum(bytes) / sum(median ns) per tree. Report documents that bailed on either tree in their own list, excluded from the aggregate.

Use `hrtime(true)` only. The bootstrap must be deterministic given `--seed` (mt_srand).

## Synthetic documents (lib/class-benchmark-synthetic.php)

`Benchmark_Synthetic::shapes(): array` of ids. `Benchmark_Synthetic::generate( string $shape, int $target_bytes, int $seed ): string`. Deterministic: same (shape, bytes, seed) gives identical bytes across runs and PHP versions (use `mt_srand( $seed, MT_RAND_MT19937 )` and `mt_rand`; never `random_int`). Output size within 2% of target. Every shape is a full document (doctype, html, head, body) so the HTML Processor runs it in full-parser mode without bailing; verify each shape parses to the end with `WP_HTML_Processor::create_full_parser` on trunk (no `get_last_error()`), and note any that cannot in the README.

Shapes (ids):
- `tags-dense`: short elements with no attributes, little text: `<div><span></span><b></b></div>` style, mixed nesting depth up to 8.
- `text-long`: a few `<p>` elements holding long runs of plain ASCII prose (no entities, no `<` or `&`).
- `text-entities`: prose with frequent named and numeric character references (`&amp;`, `&nbsp;`, `&#8217;`, `&hellip;`, some invalid like `&notanentity;`).
- `text-utf8`: prose of multi-byte UTF-8 (mixed scripts), no entities.
- `comments-many`: thousands of short comments between elements, including some with `--` inside and some bogus comments `<!x>` and `<?pi?>`.
- `attributes-heavy`: elements with 10 to 30 attributes each, quoted and unquoted values, duplicates, boolean, long `class` and `style` values, `data-*` names.
- `nesting-deep`: nesting to depth 200 then unwinding, repeated (the HTML Processor stack of open elements under load).
- `script-style`: large `<script>` and `<style>` bodies with `<` and `</` inside that are not closers, and `<textarea>`/`<title>` RCDATA.
- `whitespace-runs`: long runs of spaces, tabs and newlines between tags and inside text.
- `foreign-content`: inline `<svg>` and `<math>` subtrees with namespaced attributes, `<foreignObject>` containing HTML, self-closing tags.
- `formatting-adoption`: misnested formatting elements (`<b><i></b></i>`, `<a>` inside `<a>`, `<p>` closing by `<div>`) to exercise the adoption agency on the HTML Processor; the Tag Processor sees it as ordinary tags.
- `tables`: tables with and without `<tbody>`, stray text and tags inside tables (foster parenting path on the HTML Processor).
- `wordpress-post`: a mix shaped like a block-themed WordPress page: `<!-- wp:paragraph -->` style block comments, paragraphs, headings, figures with img and srcset, lists, nested group divs with class and style attributes, inline SVG icons, a few script tags with JSON.

## Real corpus (corpus/manifest.json and fetch-corpus.php)

Decided by Jon 2026-10-01: named public pages, not Common Crawl. Nothing committed to the repo except the manifest; documents are fetched into the gitignored `corpus/real/`.

Pick about 20 documents that span sizes (10 KB to over 1 MB, plus the single-page HTML standard at about 13 MB as an opt-in extreme), authoring styles and scripts. Prefer URLs that pin a specific revision so the bytes are reproducible: Wikipedia `https://<lang>.wikipedia.org/w/index.php?title=<Title>&oldid=<rev>` (look up the current revision id through the MediaWiki API, `action=query&prop=revisions&titles=...&rvprop=ids`), WHATWG commit snapshots `https://html.spec.whatwg.org/commit-snapshots/<sha>/` (and the multipage `parsing.html` section under the same snapshot). Candidates:

- WHATWG HTML standard: multipage `parsing.html` (about 1 MB) and the single-page spec (opt-in, `"optional": true`, excluded unless `--include-optional`).
- Wikipedia, pinned oldid: `HTML`, `WordPress`, `PHP` (en); one page each from ja, ar, ru or similar for multi-byte text; one long list article (tables).
- WordPress-rendered pages: `https://wordpress.org/news/` front page, one wordpress.org/news post, `https://developer.wordpress.org/reference/classes/wp_html_tag_processor/`, one `https://make.wordpress.org/core/` post, `https://sirre.al/` (Jon's block-theme blog).
- MDN: `https://developer.mozilla.org/en-US/docs/Web/HTML/Element`.
- php.net manual page, e.g. `https://www.php.net/manual/en/function.strspn.php`.
- Hacker News front page `https://news.ycombinator.com/` (table layout, small).

Avoid sites whose terms forbid automated fetching and anything behind consent walls. Fetch with curl, `--compressed`, a plain User-Agent naming the tool, and save the response body bytes as served (no re-encoding). Record the final URL after redirects.

Manifest entry:
```
{ "id": "whatwg-parsing", "url": "<pinned url>", "pinned": true|false, "bytes": N, "sha256": "<hex>", "fetched": "2026-10-01", "optional": false, "bails": null|"<reason on trunk>", "note": "<one line: what the page is>" }
```
Top level: `{ "generated": "2026-10-01", "documents": [...] }`.

`fetch-corpus.php [--out corpus/real] [--jobs 4] [--include-optional] [--force]`: fetches each entry, verifies sha256. For `pinned: true` a mismatch is an error (exit non-zero, file removed). For `pinned: false` a mismatch is a warning: keep the file, print the new hash, and say the page drifted since the manifest date. Skip entries already present with the right hash. Print a summary table of id, bytes, status.

Also provide `php fetch-corpus.php --update-manifest` that refetches everything and rewrites `bytes` and `sha256` and `fetched` (for unpinned pages), so the manifest can be refreshed on purpose.

Verify on trunk which documents bail in `WP_HTML_Processor::create_full_parser` (loop `next_token()` until false, then `get_last_error()`), and record the reason in `bails`. Keep at most a few that bail; they exercise the bail path and are reported separately by bench.php.

## Report

Header: date, php version per tree, opcache/jit state, checkout path and git head per tree, samples, min-sample-ms, seed.

A/B table per parser, one row per document: id, bytes, base median ms, head median ms, change %, 95% CI, verdict, CV base/head. Then an overall line per parser: geomean ratio with CI, and throughput MB/s on each tree. Then the bailed list.

Single-tree table: id, bytes, tokens, median ms, MB/s, tokens/s, CV, peak MB.

Markdown format is the same with pipes. JSON is the full structure.

## README must include

- What is measured and what is not.
- How to run A/B: `php tests/performance/html-api/bench.php --base ~/a8c/wordpress-develop/trunk --head .`
- A/A validation: run with base = head and expect every verdict to be "no difference"; if not, the machine is too noisy, raise `--samples` or close other work.
- How to add a synthetic shape, how to fetch the real corpus, how to point at your own documents.
- Why interleaving and bootstrap CIs rather than a mean of two runs.

## Validation before reporting done

1. `php bench.php --list` shows all synthetic shapes and the fetched corpus.
2. A/A run (`--base` = `--head`, same checkout) over synthetic shapes with `--samples 20`: every document reports "no difference"; the overall CI contains 1.
3. Single-tree JSON saved, then `compare.php` over two saved files works.
4. All code passes `php -l` on 8.5 and 8.4.
