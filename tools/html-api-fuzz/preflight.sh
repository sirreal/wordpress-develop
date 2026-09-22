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

require_positive_decimal() {
	local name="$1"
	local value="$2"
	if [[ ! "$value" =~ ^[1-9][0-9]*$ ]]; then
		echo "Expected $name to be a positive decimal integer." >&2
		exit 1
	fi
}

require_integer() {
	local name="$1"
	local value="$2"
	if [[ ! "$value" =~ ^(0|-?[1-9][0-9]*)$ ]]; then
		echo "Expected $name to be an integer." >&2
		exit 1
	fi
}

require_positive_decimal MAX_SEEDS "$MAX_SEEDS"
require_integer START_SEED "$START_SEED"
require_positive_decimal SEED_STRIDE "$SEED_STRIDE"
require_positive_decimal BATCH_SIZE "$BATCH_SIZE"
require_positive_decimal MAX_INPUT_BYTES "$MAX_INPUT_BYTES"

php -r '
$names = array( "MAX_SEEDS", "START_SEED", "SEED_STRIDE", "BATCH_SIZE", "MAX_INPUT_BYTES" );
$values = array();
foreach ( $names as $index => $name ) {
	$value = filter_var( $argv[ $index + 1 ], FILTER_VALIDATE_INT );
	if ( false === $value ) {
		fwrite( STDERR, "Expected {$name} to fit in a PHP integer.\n" );
		exit( 1 );
	}
	$values[ $name ] = (int) $value;
}
if ( $values["MAX_SEEDS"] > intdiv( PHP_INT_MAX, $values["SEED_STRIDE"] ) ) {
	fwrite( STDERR, "MAX_SEEDS * SEED_STRIDE exceeds the PHP integer range.\n" );
	exit( 1 );
}
$delta = $values["MAX_SEEDS"] * $values["SEED_STRIDE"];
if ( $values["START_SEED"] > PHP_INT_MAX - $delta ) {
	fwrite( STDERR, "The final nextSeed exceeds the PHP integer range.\n" );
	exit( 1 );
}
' "$MAX_SEEDS" "$START_SEED" "$SEED_STRIDE" "$BATCH_SIZE" "$MAX_INPUT_BYTES"

if [[ -e "$OUTPUT_DIR" ]]; then
	echo "Preflight output already exists: $OUTPUT_DIR" >&2
	exit 1
fi

LEXBOR_BIN="$SCRIPT_DIR/oracles/lexbor/build/lexbor-tree-oracle"
HTML5EVER_BIN="$SCRIPT_DIR/oracles/html5ever/build/html5ever-tree-oracle"
CHROME_BIN="$($SCRIPT_DIR/oracles/chrome/install.sh)"

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
	--profile text-fragment
	--mode fragment-body
	--payload-policy valid-utf8
	--fragment-context body
	--corpus-mutate-percent 0
	--force-primary-oracle
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
