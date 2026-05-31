#!/bin/bash
set -euo pipefail

# Quick syntax check before benchmarking
php -l src/wp-includes/html-api/class-wp-html-tag-processor.php > /dev/null 2>&1
php -l src/wp-includes/html-api/class-wp-html-processor.php > /dev/null 2>&1
php -l src/wp-includes/html-api/class-wp-html-attribute-token.php > /dev/null 2>&1

TMPFILE=$(mktemp)
trap "rm -f $TMPFILE" EXIT

# Run benchmark
hyperfine --warmup 2 --min-runs 10 --export-json "$TMPFILE" './bench.php' > /dev/null

# Extract metrics
php -r '
$data = json_decode(file_get_contents($argv[1]), true);
$r = $data["results"][0];
printf("METRIC mean_ms=%.1f\n", $r["mean"] * 1000);
printf("METRIC stddev_ms=%.1f\n", $r["stddev"] * 1000);
printf("METRIC min_ms=%.1f\n", $r["min"] * 1000);
printf("METRIC max_ms=%.1f\n", $r["max"] * 1000);
' "$TMPFILE"
