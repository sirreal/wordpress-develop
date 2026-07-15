#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd -P)"
MAX_SEEDS="${MAX_SEEDS:-100}"
START_SEED="${START_SEED:-1}"
SEED_STRIDE="${SEED_STRIDE:-1}"
BATCH_SIZE="${BATCH_SIZE:-25}"
MAX_INPUT_BYTES="${MAX_INPUT_BYTES:-4096}"
OUTPUT_DIR="${OUTPUT_DIR:-${TMPDIR:-/tmp}/html-api-fuzz-preflight-$(date -u +%Y%m%dT%H%M%SZ)}"

if [[ -e "$OUTPUT_DIR" ]]; then
	echo "Preflight output already exists: $OUTPUT_DIR" >&2
	exit 1
fi

LEXBOR_BIN="$SCRIPT_DIR/oracles/lexbor/build/lexbor-tree-oracle"
HTML5EVER_BIN="$SCRIPT_DIR/oracles/html5ever/build/html5ever-tree-oracle"
CHROME_BIN="$($SCRIPT_DIR/oracles/chrome/install.sh --print-path)"

for binary in "$LEXBOR_BIN" "$HTML5EVER_BIN" "$CHROME_BIN"; do
	if [[ ! -x "$binary" ]]; then
		echo "Required pinned oracle is not built/installed: $binary" >&2
		exit 1
	fi
done

mkdir -p "$OUTPUT_DIR"
common=(
	--start-seed "$START_SEED"
	--seed-stride "$SEED_STRIDE"
	--max-seeds "$MAX_SEEDS"
	--duration-seconds 0
	--batch-size "$BATCH_SIZE"
	--max-input-bytes "$MAX_INPUT_BYTES"
)

cd "$REPO_ROOT"
php tools/html-api-fuzz/runner.php "${common[@]}" \
	--output-dir "$OUTPUT_DIR/lexbor" \
	--dom-oracle lexbor-source \
	--lexbor-oracle-bin "$LEXBOR_BIN"

php tools/html-api-fuzz/runner.php "${common[@]}" \
	--output-dir "$OUTPUT_DIR/html5ever" \
	--dom-oracle html5ever-source \
	--html5ever-oracle-bin "$HTML5EVER_BIN"

php tools/html-api-fuzz/runner.php "${common[@]}" \
	--output-dir "$OUTPUT_DIR/chrome" \
	--dom-oracle chrome-cdp \
	--chrome-executable "$CHROME_BIN" \
	--oracle-timeout-ms 15000

php tools/html-api-fuzz/verify-preflight.php \
	--run-dir "$OUTPUT_DIR" \
	--max-seeds "$MAX_SEEDS" \
	--start-seed "$START_SEED" \
	--seed-stride "$SEED_STRIDE"
