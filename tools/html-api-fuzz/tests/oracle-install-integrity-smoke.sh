#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
FUZZ_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
CHROME_DIR="$FUZZ_ROOT/oracles/chrome"
HTML5EVER_DIR="$FUZZ_ROOT/oracles/html5ever"
CHROME_INSTALLER="$CHROME_DIR/install.sh"
RUST_INSTALLER="$HTML5EVER_DIR/install-rust.sh"
CHROME_VERSION="$(tr -d '[:space:]' < "$CHROME_DIR/VERSION")"
RUSTUP_VERSION='1.28.2'

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{ print $1 }'
	else
		fail 'sha256sum or shasum is required'
	fi
}

file_mode() {
	case "$(uname -s)" in
		Darwin) /usr/bin/stat -f '%Lp' "$1" ;;
		Linux) stat -c '%a' "$1" ;;
		*) fail 'file-mode check requires macOS or Linux' ;;
	esac
}

validate_manifest() {
	local manifest="$1"
	shift
	local expected_count="$#"
	local actual_count
	actual_count="$(awk 'NF && $1 !~ /^#/ { count++ } END { print count + 0 }' "$manifest")"
	[[ "$actual_count" -eq "$expected_count" ]] || fail "$manifest contains $actual_count entries; expected $expected_count"

	local name matches fields digest
	for name in "$@"; do
		matches="$(awk -v name="$name" '$2 == name { count++ } END { print count + 0 }' "$manifest")"
		[[ "$matches" -eq 1 ]] || fail "$manifest must contain exactly one $name entry"
		fields="$(awk -v name="$name" '$2 == name { print NF }' "$manifest")"
		digest="$(awk -v name="$name" '$2 == name { print $1 }' "$manifest")"
		[[ "$fields" -eq 2 ]] || fail "$name checksum entry must contain exactly two fields"
		[[ "$digest" =~ ^[0-9a-f]{64}$ ]] || fail "$name checksum is not lowercase SHA-256"
	done
}

validate_manifest "$CHROME_DIR/SHA256SUMS" \
	"chrome-$CHROME_VERSION-mac-arm64.zip" \
	"chrome-$CHROME_VERSION-mac-x64.zip" \
	"chrome-$CHROME_VERSION-linux64.zip"
validate_manifest "$HTML5EVER_DIR/RUSTUP_SHA256SUMS" \
	"rustup-init-$RUSTUP_VERSION-aarch64-apple-darwin" \
	"rustup-init-$RUSTUP_VERSION-x86_64-apple-darwin" \
	"rustup-init-$RUSTUP_VERSION-aarch64-unknown-linux-gnu" \
	"rustup-init-$RUSTUP_VERSION-x86_64-unknown-linux-gnu"

case "$(uname -s):$(uname -m)" in
	Darwin:arm64)
		CHROME_PLATFORM='mac-arm64'
		CHROME_ARCHIVE_DIR='chrome-mac-arm64'
		CHROME_EXECUTABLE_RELATIVE='Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
		RUST_TARGET='aarch64-apple-darwin'
		;;
	Darwin:x86_64)
		CHROME_PLATFORM='mac-x64'
		CHROME_ARCHIVE_DIR='chrome-mac-x64'
		CHROME_EXECUTABLE_RELATIVE='Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
		RUST_TARGET='x86_64-apple-darwin'
		;;
	Linux:x86_64|Linux:amd64)
		CHROME_PLATFORM='linux64'
		CHROME_ARCHIVE_DIR='chrome-linux64'
		CHROME_EXECUTABLE_RELATIVE='chrome'
		RUST_TARGET='x86_64-unknown-linux-gnu'
		;;
	Linux:aarch64)
		fail 'Chrome installer does not support Linux aarch64'
		;;
	*)
		fail "unsupported smoke-test host: $(uname -s) $(uname -m)"
		;;
esac

CHROME_ARCHIVE_NAME="chrome-$CHROME_VERSION-$CHROME_PLATFORM.zip"
CHROME_SHA256="$(awk -v name="$CHROME_ARCHIVE_NAME" '$2 == name { print $1 }' "$CHROME_DIR/SHA256SUMS")"
MARKER_CONTENT="$(printf 'version=%s\nplatform=%s\narchive_sha256=%s' "$CHROME_VERSION" "$CHROME_PLATFORM" "$CHROME_SHA256")"

TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/html-api-fuzz-install-integrity.XXXXXX")"
trap 'rm -rf "$TMP_ROOT"' EXIT HUP INT TERM
NO_EXTERNAL_BIN="$TMP_ROOT/no-external-bin"
EXTERNAL_LOG="$TMP_ROOT/external-invocations"
mkdir -p "$NO_EXTERNAL_BIN"
for external_command in curl unzip; do
	{
		printf '%s\n' '#!/bin/sh'
		printf 'printf "%%s\\n" %s >> "$HTML_API_FUZZ_EXTERNAL_LOG"\n' "$external_command"
		printf '%s\n' 'exit 97'
	} > "$NO_EXTERNAL_BIN/$external_command"
	chmod 0755 "$NO_EXTERNAL_BIN/$external_command"
done

assert_no_external() {
	[[ ! -e "$EXTERNAL_LOG" ]] || fail "unexpected external command invocation: $(tr '\n' ' ' < "$EXTERNAL_LOG")"
}

# --print-path is deliberately only a path calculation. It must not depend on
# either the checksum manifest or an installer-side external command.
print_fixture="$TMP_ROOT/chrome-print-path"
print_root="$TMP_ROOT/chrome-print-root"
mkdir -p "$print_fixture"
cp "$CHROME_INSTALLER" "$print_fixture/install.sh"
cp "$CHROME_DIR/VERSION" "$print_fixture/VERSION"
PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$print_root" \
	"$print_fixture/install.sh" --print-path > "$TMP_ROOT/print-path.out"
expected_print_path="$print_root/$CHROME_VERSION/$CHROME_PLATFORM/$CHROME_ARCHIVE_DIR/$CHROME_EXECUTABLE_RELATIVE"
[[ "$(cat "$TMP_ROOT/print-path.out")" = "$expected_print_path" ]] || fail '--print-path returned an unexpected path'
assert_no_external

write_fake_chrome() {
	local executable="$1"
	mkdir -p "$(dirname "$executable")"
	{
		printf '%s\n' '#!/bin/sh'
		printf '%s\n' 'printf "%s\n" invoked >> "$HTML_API_FUZZ_INVOCATION_LOG"'
		printf '%s\n' 'printf "Google Chrome for Testing %s\n" "$HTML_API_FUZZ_FAKE_VERSION"'
		printf '%s\n' 'exit "${HTML_API_FUZZ_FAKE_EXIT_STATUS:-0}"'
	} > "$executable"
	chmod 0755 "$executable"
}

# An unmarked or stale legacy install must not execute before its archive is
# authenticated. The corrupt cached archive also makes this path network-free.
legacy_root="$TMP_ROOT/chrome-legacy"
legacy_destination="$legacy_root/$CHROME_VERSION/$CHROME_PLATFORM"
legacy_executable="$legacy_destination/$CHROME_ARCHIVE_DIR/$CHROME_EXECUTABLE_RELATIVE"
legacy_archive="$legacy_root/.downloads/$CHROME_ARCHIVE_NAME"
legacy_log="$TMP_ROOT/legacy-invocations"
write_fake_chrome "$legacy_executable"
mkdir -p "$(dirname "$legacy_archive")"
printf '%s\n' 'corrupt chrome archive' > "$legacy_archive"

if PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$legacy_root" \
	HTML_API_FUZZ_INVOCATION_LOG="$legacy_log" \
	HTML_API_FUZZ_FAKE_VERSION="$CHROME_VERSION" \
	"$CHROME_INSTALLER" > "$TMP_ROOT/legacy.out" 2> "$TMP_ROOT/legacy.err"; then
	fail 'corrupt Chrome archive was accepted for an unmarked legacy install'
fi
[[ ! -e "$legacy_log" ]] || fail 'unmarked Chrome executable was invoked before archive verification'
grep -q 'SHA-256 mismatch' "$TMP_ROOT/legacy.err" || fail 'legacy rejection did not report a checksum mismatch'
assert_no_external

printf '%s\n' 'stale marker' > "$legacy_destination/.html-api-fuzz-verified"
if PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$legacy_root" \
	HTML_API_FUZZ_INVOCATION_LOG="$legacy_log" \
	HTML_API_FUZZ_FAKE_VERSION="$CHROME_VERSION" \
	"$CHROME_INSTALLER" > "$TMP_ROOT/stale.out" 2> "$TMP_ROOT/stale.err"; then
	fail 'corrupt Chrome archive was accepted for a stale marker'
fi
[[ ! -e "$legacy_log" ]] || fail 'stale-marker Chrome executable was invoked before archive verification'
assert_no_external

# A correctly marked install can take the archive-free fast path.
fast_root="$TMP_ROOT/chrome-fast"
fast_destination="$fast_root/$CHROME_VERSION/$CHROME_PLATFORM"
fast_executable="$fast_destination/$CHROME_ARCHIVE_DIR/$CHROME_EXECUTABLE_RELATIVE"
fast_log="$TMP_ROOT/fast-invocations"
write_fake_chrome "$fast_executable"
printf '%s\n' "$MARKER_CONTENT" > "$fast_destination/.html-api-fuzz-verified"
PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$fast_root" \
	HTML_API_FUZZ_INVOCATION_LOG="$fast_log" \
	HTML_API_FUZZ_FAKE_VERSION="$CHROME_VERSION" \
	"$CHROME_INSTALLER" > "$TMP_ROOT/fast.out"
[[ "$(cat "$TMP_ROOT/fast.out")" = "$fast_executable" ]] || fail 'valid-marker fast path returned the wrong executable'
[[ "$(wc -l < "$fast_log" | tr -d '[:space:]')" -eq 1 ]] || fail 'valid-marker fast path did not invoke Chrome exactly once'
assert_no_external

# Once the marker authenticates the install provenance, a reported version
# mismatch is an explicit hard failure.
wrong_root="$TMP_ROOT/chrome-wrong-version"
wrong_destination="$wrong_root/$CHROME_VERSION/$CHROME_PLATFORM"
wrong_executable="$wrong_destination/$CHROME_ARCHIVE_DIR/$CHROME_EXECUTABLE_RELATIVE"
wrong_log="$TMP_ROOT/wrong-version-invocations"
write_fake_chrome "$wrong_executable"
printf '%s\n' "$MARKER_CONTENT" > "$wrong_destination/.html-api-fuzz-verified"
if PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$wrong_root" \
	HTML_API_FUZZ_INVOCATION_LOG="$wrong_log" \
	HTML_API_FUZZ_FAKE_VERSION='0.0.0.0' \
	"$CHROME_INSTALLER" > "$TMP_ROOT/wrong.out" 2> "$TMP_ROOT/wrong.err"; then
	fail 'marker-backed wrong Chrome version was accepted'
fi
[[ -s "$wrong_log" ]] || fail 'marker-backed Chrome was not invoked for its version check'
grep -q 'expected' "$TMP_ROOT/wrong.err" || fail 'wrong Chrome version failure was not explicit'
assert_no_external

# A successful-looking version string cannot mask a nonzero browser exit.
nonzero_root="$TMP_ROOT/chrome-nonzero-version"
nonzero_destination="$nonzero_root/$CHROME_VERSION/$CHROME_PLATFORM"
nonzero_executable="$nonzero_destination/$CHROME_ARCHIVE_DIR/$CHROME_EXECUTABLE_RELATIVE"
nonzero_log="$TMP_ROOT/nonzero-version-invocations"
write_fake_chrome "$nonzero_executable"
printf '%s\n' "$MARKER_CONTENT" > "$nonzero_destination/.html-api-fuzz-verified"
if PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$nonzero_root" \
	HTML_API_FUZZ_INVOCATION_LOG="$nonzero_log" \
	HTML_API_FUZZ_FAKE_VERSION="$CHROME_VERSION" \
	HTML_API_FUZZ_FAKE_EXIT_STATUS='23' \
	"$CHROME_INSTALLER" > "$TMP_ROOT/nonzero.out" 2> "$TMP_ROOT/nonzero.err"; then
	fail 'nonzero Chrome version command was accepted'
fi
[[ -s "$nonzero_log" ]] || fail 'nonzero Chrome version command was not invoked'
grep -q 'failed its version check' "$TMP_ROOT/nonzero.err" || fail 'nonzero Chrome version failure was not explicit'
assert_no_external

# Even a checksum-valid archive is harmless until its temporary extraction has
# the expected executable and version. Use a copied fixture and fake unzip to
# model a malformed layout without changing the production manifest.
fixture_dir="$TMP_ROOT/chrome-fixture"
fixture_root="$TMP_ROOT/chrome-layout"
fixture_bin="$TMP_ROOT/fixture-bin"
mkdir -p "$fixture_dir" "$fixture_bin" "$fixture_root/.downloads"
cp "$CHROME_INSTALLER" "$fixture_dir/install.sh"
cp "$CHROME_DIR/VERSION" "$fixture_dir/VERSION"
fixture_archive="$fixture_root/.downloads/$CHROME_ARCHIVE_NAME"
printf '%s\n' 'checksum-valid synthetic archive' > "$fixture_archive"
fixture_sha256="$(sha256_file "$fixture_archive")"
printf '%s  %s\n' "$fixture_sha256" "$CHROME_ARCHIVE_NAME" > "$fixture_dir/SHA256SUMS"
{
	printf '%s\n' '#!/bin/sh'
	printf '%s\n' 'destination=' 'while [ "$#" -gt 0 ]; do' \
		'  if [ "$1" = "-d" ]; then shift; destination="$1"; fi' \
		'  shift' 'done' 'mkdir -p "$destination/unexpected-layout"'
} > "$fixture_bin/unzip"
chmod 0755 "$fixture_bin/unzip"
layout_destination="$fixture_root/$CHROME_VERSION/$CHROME_PLATFORM"
layout_executable="$layout_destination/$CHROME_ARCHIVE_DIR/$CHROME_EXECUTABLE_RELATIVE"
layout_log="$TMP_ROOT/layout-invocations"
write_fake_chrome "$layout_executable"
printf '%s\n' 'preserve me' > "$layout_destination/preserved"
if PATH="$fixture_bin:$PATH" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$fixture_root" \
	HTML_API_FUZZ_INVOCATION_LOG="$layout_log" \
	HTML_API_FUZZ_FAKE_VERSION="$CHROME_VERSION" \
	"$fixture_dir/install.sh" > "$TMP_ROOT/layout.out" 2> "$TMP_ROOT/layout.err"; then
	fail 'malformed Chrome archive layout was accepted'
fi
[[ -f "$layout_destination/preserved" ]] || fail 'malformed Chrome archive replaced the existing install'
[[ ! -e "$layout_log" ]] || fail 'unmarked Chrome executable ran during malformed-layout handling'
grep -q 'did not contain the expected executable' "$TMP_ROOT/layout.err" || fail 'malformed layout failure was not explicit'

# A checksum-valid archive with the right layout but wrong executable version
# is also rejected while the old destination is still intact.
version_fixture_dir="$TMP_ROOT/chrome-version-fixture"
version_fixture_root="$TMP_ROOT/chrome-extracted-version"
version_fixture_bin="$TMP_ROOT/version-fixture-bin"
mkdir -p "$version_fixture_dir" "$version_fixture_bin" "$version_fixture_root/.downloads"
cp "$CHROME_INSTALLER" "$version_fixture_dir/install.sh"
cp "$CHROME_DIR/VERSION" "$version_fixture_dir/VERSION"
version_fixture_archive="$version_fixture_root/.downloads/$CHROME_ARCHIVE_NAME"
printf '%s\n' 'checksum-valid wrong-version archive' > "$version_fixture_archive"
version_fixture_sha256="$(sha256_file "$version_fixture_archive")"
printf '%s  %s\n' "$version_fixture_sha256" "$CHROME_ARCHIVE_NAME" > "$version_fixture_dir/SHA256SUMS"
extracted_source="$TMP_ROOT/wrong-extracted-chrome"
{
	printf '%s\n' '#!/bin/sh'
	printf '%s\n' 'printf "%s\n" invoked >> "$HTML_API_FUZZ_TEMP_INVOCATION_LOG"'
	printf '%s\n' 'printf "%s\n" "Google Chrome for Testing 0.0.0.0"'
} > "$extracted_source"
chmod 0755 "$extracted_source"
{
	printf '%s\n' '#!/bin/sh'
	printf '%s\n' 'destination=' 'while [ "$#" -gt 0 ]; do' \
		'  if [ "$1" = "-d" ]; then shift; destination="$1"; fi' \
		'  shift' 'done'
	printf '%s\n' 'executable="$destination/$HTML_API_FUZZ_TEST_ARCHIVE_DIR/$HTML_API_FUZZ_TEST_EXECUTABLE_RELATIVE"'
	printf '%s\n' 'mkdir -p "$(dirname "$executable")"'
	printf '%s\n' 'cp "$HTML_API_FUZZ_TEST_EXTRACTED_EXECUTABLE" "$executable"'
	printf '%s\n' 'chmod 0755 "$executable"'
} > "$version_fixture_bin/unzip"
chmod 0755 "$version_fixture_bin/unzip"
version_destination="$version_fixture_root/$CHROME_VERSION/$CHROME_PLATFORM"
version_old_executable="$version_destination/$CHROME_ARCHIVE_DIR/$CHROME_EXECUTABLE_RELATIVE"
version_old_log="$TMP_ROOT/version-old-invocations"
version_temp_log="$TMP_ROOT/version-temp-invocations"
write_fake_chrome "$version_old_executable"
printf '%s\n' 'preserve me too' > "$version_destination/preserved"
if PATH="$version_fixture_bin:$PATH" \
	HTML_API_FUZZ_CHROME_INSTALL_ROOT="$version_fixture_root" \
	HTML_API_FUZZ_INVOCATION_LOG="$version_old_log" \
	HTML_API_FUZZ_TEMP_INVOCATION_LOG="$version_temp_log" \
	HTML_API_FUZZ_FAKE_VERSION="$CHROME_VERSION" \
	HTML_API_FUZZ_TEST_ARCHIVE_DIR="$CHROME_ARCHIVE_DIR" \
	HTML_API_FUZZ_TEST_EXECUTABLE_RELATIVE="$CHROME_EXECUTABLE_RELATIVE" \
	HTML_API_FUZZ_TEST_EXTRACTED_EXECUTABLE="$extracted_source" \
	"$version_fixture_dir/install.sh" > "$TMP_ROOT/extracted-version.out" 2> "$TMP_ROOT/extracted-version.err"; then
	fail 'wrong extracted Chrome version was accepted'
fi
[[ -f "$version_destination/preserved" ]] || fail 'wrong-version archive replaced the existing install'
[[ ! -e "$version_old_log" ]] || fail 'old unmarked Chrome executable ran during extracted-version handling'
[[ "$(wc -l < "$version_temp_log" | tr -d '[:space:]')" -eq 1 ]] || fail 'temporary wrong-version Chrome was not validated exactly once'
grep -q 'does not match pin' "$TMP_ROOT/extracted-version.err" || fail 'wrong extracted version failure was not explicit'

# A corrupt cached rustup bootstrap must fail before chmod or execution, with
# no checksum fetched from the same origin.
rust_root="$TMP_ROOT/rust"
rustup_init="$rust_root/downloads/rustup-init-$RUSTUP_VERSION-$RUST_TARGET"
rust_log="$TMP_ROOT/rustup-invocations"
mkdir -p "$(dirname "$rustup_init")"
{
	printf '%s\n' '#!/bin/sh'
	printf '%s\n' 'printf "%s\n" invoked >> "$HTML_API_FUZZ_INVOCATION_LOG"'
} > "$rustup_init"
chmod 0644 "$rustup_init"
rustup_mode_before="$(file_mode "$rustup_init")"
if PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML5EVER_RUST_ROOT="$rust_root" \
	CARGO_HOME="$rust_root/cargo" \
	RUSTUP_HOME="$rust_root/rustup" \
	HTML_API_FUZZ_INVOCATION_LOG="$rust_log" \
	"$RUST_INSTALLER" > "$TMP_ROOT/rust.out" 2> "$TMP_ROOT/rust.err"; then
	fail 'corrupt cached rustup-init was accepted'
fi
[[ ! -e "$rust_log" ]] || fail 'corrupt rustup-init was executed'
[[ ! -x "$rustup_init" ]] || fail 'corrupt rustup-init was made executable before verification'
[[ "$(file_mode "$rustup_init")" = "$rustup_mode_before" ]] || fail 'corrupt rustup-init mode changed before verification'
grep -q 'SHA-256 mismatch' "$TMP_ROOT/rust.err" || fail 'rustup rejection did not report a checksum mismatch'
assert_no_external

# Keep a separately executable corrupt fixture so no-execution coverage does
# not rely on the bootstrap's initial mode.
rust_exec_root="$TMP_ROOT/rust-executable"
rust_exec_init="$rust_exec_root/downloads/rustup-init-$RUSTUP_VERSION-$RUST_TARGET"
rust_exec_log="$TMP_ROOT/rustup-executable-invocations"
mkdir -p "$(dirname "$rust_exec_init")"
{
	printf '%s\n' '#!/bin/sh'
	printf '%s\n' 'printf "%s\n" invoked >> "$HTML_API_FUZZ_INVOCATION_LOG"'
} > "$rust_exec_init"
chmod 0755 "$rust_exec_init"
if PATH="$NO_EXTERNAL_BIN:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$EXTERNAL_LOG" \
	HTML5EVER_RUST_ROOT="$rust_exec_root" \
	CARGO_HOME="$rust_exec_root/cargo" \
	RUSTUP_HOME="$rust_exec_root/rustup" \
	HTML_API_FUZZ_INVOCATION_LOG="$rust_exec_log" \
	"$RUST_INSTALLER" > "$TMP_ROOT/rust-exec.out" 2> "$TMP_ROOT/rust-exec.err"; then
	fail 'executable corrupt cached rustup-init was accepted'
fi
[[ ! -e "$rust_exec_log" ]] || fail 'executable corrupt rustup-init was executed'
grep -q 'SHA-256 mismatch' "$TMP_ROOT/rust-exec.err" || fail 'executable rustup rejection did not report a checksum mismatch'
assert_no_external

printf '%s\n' 'oracle install integrity smoke passed'
