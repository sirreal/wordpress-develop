#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
FUZZ_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
BUILD_SCRIPT="$FUZZ_ROOT/oracles/lexbor/build.sh"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/html-api-fuzz-lexbor-source.XXXXXX")"
trap 'rm -rf "$TMP_ROOT"' EXIT HUP INT TERM

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

SOURCE_DIR="$TMP_ROOT/source"
FAKE_BIN="$TMP_ROOT/fake-bin"
BUILD_LOG="$TMP_ROOT/build-tools.log"
mkdir -p "$SOURCE_DIR" "$FAKE_BIN"

git -C "$SOURCE_DIR" init -q
git -C "$SOURCE_DIR" config user.name 'HTML API fuzz smoke'
git -C "$SOURCE_DIR" config user.email 'html-api-fuzz@example.invalid'
printf 'tracked\n' > "$SOURCE_DIR/tracked.txt"
printf 'ignored.cache\n' > "$SOURCE_DIR/.gitignore"
git -C "$SOURCE_DIR" add tracked.txt .gitignore
git -C "$SOURCE_DIR" commit -qm 'seed clean source'
REF="$(git -C "$SOURCE_DIR" rev-parse HEAD)"

for tool in cmake cc; do
	cat > "$FAKE_BIN/$tool" <<'EOF'
#!/usr/bin/env sh
printf '%s\n' "$(basename "$0")" >> "$HTML_API_FUZZ_LEXBOR_BUILD_LOG"
exit 97
EOF
	chmod 0700 "$FAKE_BIN/$tool"
done

reset_source() {
	git -C "$SOURCE_DIR" reset --hard -q "$REF"
	git -C "$SOURCE_DIR" clean -fdxq
	: > "$BUILD_LOG"
}

expect_dirty_rejection() {
	local label="$1"
	local expected_status="$2"
	local output status
	set +e
	output="$(
		env \
			PATH="$FAKE_BIN:$PATH" \
			HTML_API_FUZZ_LEXBOR_BUILD_LOG="$BUILD_LOG" \
			LEXBOR_COMMIT="$REF" \
			LEXBOR_SOURCE_DIR="$SOURCE_DIR" \
			LEXBOR_BUILD_DIR="$TMP_ROOT/build-$label" \
			LEXBOR_INSTALL_DIR="$TMP_ROOT/install-$label" \
			"$BUILD_SCRIPT" 2>&1
	)"
	status=$?
	set -e
	[[ "$status" -ne 0 ]] || fail "$label source dirt was accepted"
	[[ "$output" == *'Lexbor source checkout is dirty; refusing to build unpinned bytes:'* ]] || fail "$label source dirt did not report the integrity rejection: $output"
	[[ "$output" == *"$expected_status"* ]] || fail "$label source dirt did not report $expected_status: $output"
	[[ ! -s "$BUILD_LOG" ]] || fail "$label source dirt reached a build tool: $(cat "$BUILD_LOG")"
}

reset_source
printf 'modified\n' >> "$SOURCE_DIR/tracked.txt"
expect_dirty_rejection tracked ' M tracked.txt'

reset_source
printf 'untracked\n' > "$SOURCE_DIR/untracked.txt"
expect_dirty_rejection untracked '?? untracked.txt'

reset_source
printf 'ignored\n' > "$SOURCE_DIR/ignored.cache"
expect_dirty_rejection ignored '!! ignored.cache'

printf 'PASS: Lexbor rejects tracked, untracked, and ignored source dirt before building\n'
