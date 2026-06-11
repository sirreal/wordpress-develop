#!/bin/sh
# Stages a round: regenerates the parsed-doc JSON from current source,
# renders deterministic markdown, and copies ONLY the markdown into an
# isolated scratch directory for test subagents.
#
# Usage: sh stage-round.sh <round-number>
# Prints the scratch directory path on success.

set -e

if [ -z "$1" ]; then
	echo "Usage: sh stage-round.sh <round-number>" >&2
	exit 2
fi

ROUND=$(printf '%02d' "$1")
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
GENERATOR="/Users/jonsurrell/a8c/phpdoc-parser/generate-json-manually.php"
SCRATCH="/tmp/html-api-docs-eval/round-${ROUND}"

php -d display_errors=0 "$GENERATOR" \
	-d "$REPO/src/wp-includes/html-api/class-wp-html-tag-processor.php" \
	-o "$REPO/artifacts/html-tag-processor.json" 2>/dev/null
php -d display_errors=0 "$GENERATOR" \
	-d "$REPO/src/wp-includes/html-api/class-wp-html-processor.php" \
	-o "$REPO/artifacts/html-processor.json" 2>/dev/null

rm -rf "$SCRATCH"
mkdir -p "$SCRATCH"

python3 "$REPO/doc-experiment/render-docs-markdown.py" \
	-i "$REPO/artifacts/html-tag-processor.json" \
	-o "$SCRATCH/html-tag-processor.md"
python3 "$REPO/doc-experiment/render-docs-markdown.py" \
	-i "$REPO/artifacts/html-processor.json" \
	-o "$SCRATCH/html-processor.md"

echo "$SCRATCH"
