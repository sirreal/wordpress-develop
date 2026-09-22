#!/usr/bin/env sh
set -eu

# rustup and rustc are both pinned. The downloaded rustup-init is checked
# against a checked-in SHA-256 obtained from the publisher's sidecar.
rustup_version='1.28.2'
toolchain='1.88.0'
script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
checksum_manifest="$script_dir/RUSTUP_SHA256SUMS"
repo_root="$(CDPATH= cd -- "$script_dir/../../../.." && pwd)"
install_root="${HTML5EVER_RUST_ROOT:-$repo_root/.cache/html5ever/rust}"
cargo_home="${CARGO_HOME:-$install_root/cargo}"
rustup_home="${RUSTUP_HOME:-$install_root/rustup}"

case "$(uname -s)-$(uname -m)" in
	Darwin-arm64) target='aarch64-apple-darwin' ;;
	Darwin-x86_64) target='x86_64-apple-darwin' ;;
	Linux-aarch64) target='aarch64-unknown-linux-gnu' ;;
	Linux-x86_64) target='x86_64-unknown-linux-gnu' ;;
	*)
		printf 'Unsupported rustup host: %s-%s\n' "$(uname -s)" "$(uname -m)" >&2
		exit 1
		;;
esac

archive_url="https://static.rust-lang.org/rustup/archive/$rustup_version/$target/rustup-init"
download_dir="$install_root/downloads"
rustup_init="$download_dir/rustup-init-$rustup_version-$target"
archive_name="rustup-init-$rustup_version-$target"

mkdir -p "$download_dir" "$cargo_home" "$rustup_home"

if [ ! -f "$checksum_manifest" ]; then
	printf 'Missing rustup checksum manifest: %s\n' "$checksum_manifest" >&2
	exit 1
fi

entry_count="$(awk -v name="$archive_name" '$2 == name { count++ } END { print count + 0 }' "$checksum_manifest")"
if [ "$entry_count" -ne 1 ]; then
	printf 'Expected exactly one checksum for %s; found %s.\n' "$archive_name" "$entry_count" >&2
	exit 1
fi

entry_field_count="$(awk -v name="$archive_name" '$2 == name { print NF }' "$checksum_manifest")"
expected="$(awk -v name="$archive_name" '$2 == name { print $1 }' "$checksum_manifest")"
if [ "$entry_field_count" -ne 2 ] || [ "${#expected}" -ne 64 ]; then
	printf 'Malformed checksum entry for %s.\n' "$archive_name" >&2
	exit 1
fi
case "$expected" in
	*[!0-9a-f]*)
		printf 'Malformed checksum entry for %s.\n' "$archive_name" >&2
		exit 1
		;;
esac

if [ ! -f "$rustup_init" ]; then
	partial="$rustup_init.partial.$$"
	trap 'rm -f "$partial"' EXIT HUP INT TERM
	curl --proto '=https' --tlsv1.2 --fail --location --silent --show-error \
		--retry 3 "$archive_url" --output "$partial"
	mv "$partial" "$rustup_init"
	trap - EXIT HUP INT TERM
fi

if command -v sha256sum >/dev/null 2>&1; then
	actual="$(sha256sum "$rustup_init" | awk '{ print $1 }')"
elif command -v shasum >/dev/null 2>&1; then
	actual="$(shasum -a 256 "$rustup_init" | awk '{ print $1 }')"
else
	printf '%s\n' 'sha256sum or shasum is required.' >&2
	exit 1
fi

if [ "$actual" != "$expected" ]; then
	printf '%s\n' "rustup-init SHA-256 mismatch: expected $expected, got $actual" >&2
	exit 1
fi

chmod 0755 "$rustup_init"
CARGO_HOME="$cargo_home" RUSTUP_HOME="$rustup_home" RUSTUP_VERSION="$rustup_version" \
	"$rustup_init" -y --no-modify-path --profile minimal --default-toolchain none
CARGO_HOME="$cargo_home" RUSTUP_HOME="$rustup_home" \
	"$cargo_home/bin/rustup" set auto-self-update disable
CARGO_HOME="$cargo_home" RUSTUP_HOME="$rustup_home" \
	"$cargo_home/bin/rustup" toolchain install "$toolchain" --profile minimal --no-self-update

printf '%s\n' "$cargo_home/bin"
