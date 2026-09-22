#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
FUZZ_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
HTML5EVER_DIR="$FUZZ_ROOT/oracles/html5ever"
INSTALLER="$HTML5EVER_DIR/install-rust.sh"
CHECKSUMS="$HTML5EVER_DIR/RUSTUP_SHA256SUMS"
RUSTUP_VERSION='1.28.2'

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

file_mode() {
	case "$(uname -s)" in
		Darwin) /usr/bin/stat -f '%Lp' "$1" ;;
		Linux) stat -c '%a' "$1" ;;
		*) fail 'file-mode check requires macOS or Linux' ;;
	esac
}

case "$(uname -s)-$(uname -m)" in
	Darwin-arm64) target='aarch64-apple-darwin' ;;
	Darwin-x86_64) target='x86_64-apple-darwin' ;;
	Linux-aarch64) target='aarch64-unknown-linux-gnu' ;;
	Linux-x86_64|Linux-amd64) target='x86_64-unknown-linux-gnu' ;;
	*) fail "unsupported smoke-test host: $(uname -s)-$(uname -m)" ;;
esac

expected_names=(
	"rustup-init-$RUSTUP_VERSION-aarch64-apple-darwin"
	"rustup-init-$RUSTUP_VERSION-x86_64-apple-darwin"
	"rustup-init-$RUSTUP_VERSION-aarch64-unknown-linux-gnu"
	"rustup-init-$RUSTUP_VERSION-x86_64-unknown-linux-gnu"
)
entry_count="$(awk 'NF && $1 !~ /^#/ { count++ } END { print count + 0 }' "$CHECKSUMS")"
[[ "$entry_count" -eq "${#expected_names[@]}" ]] || fail "checksum manifest has $entry_count entries"
for name in "${expected_names[@]}"; do
	matches="$(awk -v name="$name" '$2 == name { count++ } END { print count + 0 }' "$CHECKSUMS")"
	fields="$(awk -v name="$name" '$2 == name { print NF }' "$CHECKSUMS")"
	digest="$(awk -v name="$name" '$2 == name { print $1 }' "$CHECKSUMS")"
	[[ "$matches" -eq 1 ]] || fail "$name must have exactly one checksum"
	[[ "$fields" -eq 2 ]] || fail "$name checksum must have exactly two fields"
	[[ "$digest" =~ ^[0-9a-f]{64}$ ]] || fail "$name checksum is not lowercase SHA-256"
done

tmp_root="$(mktemp -d "${TMPDIR:-/tmp}/html5ever-install-integrity.XXXXXX")"
trap 'rm -rf "$tmp_root"' EXIT HUP INT TERM
fake_bin="$tmp_root/fake-bin"
external_log="$tmp_root/external.log"
mkdir -p "$fake_bin"
for command in curl; do
	{
		printf '%s\n' '#!/bin/sh'
		printf '%s\n' 'printf "%s\n" "$0" >> "$HTML_API_FUZZ_EXTERNAL_LOG"'
		printf '%s\n' 'exit 97'
	} >"$fake_bin/$command"
	chmod 0755 "$fake_bin/$command"
done

run_installer() {
	local fixture_dir="$1"
	local rust_root="$2"
	PATH="$fake_bin:$PATH" \
	HTML_API_FUZZ_EXTERNAL_LOG="$external_log" \
	HTML5EVER_RUST_ROOT="$rust_root" \
	CARGO_HOME="$rust_root/cargo" \
	RUSTUP_HOME="$rust_root/rustup" \
	HTML_API_FUZZ_INVOCATION_LOG="$tmp_root/invocations.log" \
		"$fixture_dir/install-rust.sh"
}

# Manifest validation must fail before a network tool or cached bootstrap can run.
for mutation in missing duplicate malformed; do
	fixture="$tmp_root/manifest-$mutation"
	rust_root="$tmp_root/rust-$mutation"
	mkdir -p "$fixture"
	cp "$INSTALLER" "$fixture/install-rust.sh"
	case "$mutation" in
		missing)
			awk -v target="rustup-init-$RUSTUP_VERSION-$target" '$2 != target' "$CHECKSUMS" >"$fixture/RUSTUP_SHA256SUMS"
			;;
		duplicate)
			cp "$CHECKSUMS" "$fixture/RUSTUP_SHA256SUMS"
			awk -v target="rustup-init-$RUSTUP_VERSION-$target" '$2 == target { print }' "$CHECKSUMS" >>"$fixture/RUSTUP_SHA256SUMS"
			;;
		malformed)
			awk -v target="rustup-init-$RUSTUP_VERSION-$target" '$2 == target { print "not-a-digest  " $2; next } { print }' "$CHECKSUMS" >"$fixture/RUSTUP_SHA256SUMS"
			;;
	esac
	if run_installer "$fixture" "$rust_root" >"$tmp_root/$mutation.out" 2>"$tmp_root/$mutation.err"; then
		fail "$mutation checksum manifest was accepted"
	fi
	[[ ! -e "$external_log" ]] || fail "$mutation manifest reached an external command"
	[[ ! -e "$tmp_root/invocations.log" ]] || fail "$mutation manifest executed a bootstrap"
done

# A corrupt cached bootstrap must be hashed before chmod or execution.
rust_root="$tmp_root/rust-corrupt"
rustup_init="$rust_root/downloads/rustup-init-$RUSTUP_VERSION-$target"
mkdir -p "$(dirname "$rustup_init")"
{
	printf '%s\n' '#!/bin/sh'
	printf '%s\n' 'printf "%s\n" invoked >> "$HTML_API_FUZZ_INVOCATION_LOG"'
} >"$rustup_init"
chmod 0644 "$rustup_init"
mode_before="$(file_mode "$rustup_init")"
if run_installer "$HTML5EVER_DIR" "$rust_root" >"$tmp_root/corrupt.out" 2>"$tmp_root/corrupt.err"; then
	fail 'corrupt cached rustup-init was accepted'
fi
[[ "$(file_mode "$rustup_init")" = "$mode_before" ]] || fail 'corrupt bootstrap mode changed before verification'
[[ ! -x "$rustup_init" ]] || fail 'corrupt bootstrap was made executable'
[[ ! -e "$tmp_root/invocations.log" ]] || fail 'corrupt bootstrap was executed'
[[ ! -e "$external_log" ]] || fail 'corrupt cache unexpectedly reached the network'
grep -q 'SHA-256 mismatch' "$tmp_root/corrupt.err" || fail 'corrupt bootstrap rejection lacked a checksum diagnostic'

# Also prove an already-executable corrupt cache is not trusted.
rust_root="$tmp_root/rust-corrupt-executable"
rustup_init="$rust_root/downloads/rustup-init-$RUSTUP_VERSION-$target"
mkdir -p "$(dirname "$rustup_init")"
{
	printf '%s\n' '#!/bin/sh'
	printf '%s\n' 'printf "%s\n" invoked >> "$HTML_API_FUZZ_INVOCATION_LOG"'
} >"$rustup_init"
chmod 0755 "$rustup_init"
if run_installer "$HTML5EVER_DIR" "$rust_root" >"$tmp_root/executable.out" 2>"$tmp_root/executable.err"; then
	fail 'executable corrupt cached rustup-init was accepted'
fi
[[ ! -e "$tmp_root/invocations.log" ]] || fail 'executable corrupt bootstrap was executed'
[[ ! -e "$external_log" ]] || fail 'executable corrupt cache unexpectedly reached the network'
grep -q 'SHA-256 mismatch' "$tmp_root/executable.err" || fail 'executable corrupt rejection lacked a checksum diagnostic'

printf '%s\n' 'OK html5ever-install-integrity-smoke'
