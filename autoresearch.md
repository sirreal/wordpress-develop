# Autoresearch: HTML Tag Processor Performance

## Objective

Optimize `WP_HTML_Processor::next_token()` tokenization throughput on html-standard.html (~large real-world HTML). The benchmark iterates all tokens with no modifications — purely read-only tokenization speed.

## Metrics

-   **Primary**: mean execution time (ms, lower is better) via `hyperfine`
-   **Secondary**: peak memory (bytes, lower is better) via `/usr/bin/time -l`

## How to Run

`./autoresearch.sh` — runs hyperfine, outputs `METRIC mean_ms=number` lines.

## Files in Scope

-   `src/wp-includes/html-api/class-wp-html-processor.php` — HTML parser
-   `src/wp-includes/html-api/class-wp-html-tag-processor.php` — HTML syntax parser
-   `src/wp-includes/html-api/class-wp-html-attribute-token.php` — attribute token object (6 props, allocated per attr)
-   `src/wp-includes/html-api/class-wp-html-span.php` — span object (2 props, allocated on dup attrs)

## Off Limits

-   Test files
-   `bench.php` and `bootstrap-html-api.php`
-   Any file outside `src/wp-includes/html-api/`

## Constraints

-   PHPUnit tests must pass: `./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml --stop-on-error --stop-on-failure --stop-on-warning --stop-on-defect`
-   No new dependencies
-   stddev and outliers from hyperfine must remain acceptable
-   Changes must preserve all existing behavior

## What's Been Tried

### Baseline: ?

### Wins (cumulative, all committed)

### Current: ?

### Dead Ends

### Architecture Notes

### Unexplored Ideas
