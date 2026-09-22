#!/usr/bin/env sh
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/../../../.." && pwd)"
target_dir="${HTML5EVER_TARGET_DIR:-$repo_root/.cache/html5ever/target}"
oracle_build_dir="$script_dir/build"
oracle_bin="$oracle_build_dir/html5ever-tree-oracle"
rust_root="${HTML5EVER_RUST_ROOT:-$repo_root/.cache/html5ever/rust}"

if [ -z "${CARGO_HOME:-}" ] && [ -z "${RUSTUP_HOME:-}" ] && [ -x "$rust_root/cargo/bin/cargo" ]; then
	CARGO_HOME="$rust_root/cargo"
	RUSTUP_HOME="$rust_root/rustup"
	export CARGO_HOME RUSTUP_HOME
	PATH="$CARGO_HOME/bin:$PATH"
	export PATH
fi

cd "$script_dir"

if ! command -v "${CARGO:-cargo}" >/dev/null 2>&1; then
	printf '%s\n' 'cargo is required. Run ./install-rust.sh, then add the printed bin directory to PATH.' >&2
	exit 1
fi

rustc_version="$("${RUSTC:-rustc}" --version)"
case "$rustc_version" in
	'rustc 1.88.0 '*) ;;
	*)
		printf '%s\n' "Expected rustc 1.88.0; got: $rustc_version" >&2
		exit 1
		;;
esac

mkdir -p "$oracle_build_dir"

CARGO_TARGET_DIR="$target_dir" "${CARGO:-cargo}" build \
	--manifest-path "$script_dir/Cargo.toml" \
	--release \
	--locked

cp "$target_dir/release/html5ever-tree-oracle" "$oracle_bin"
chmod 0755 "$oracle_bin"

printf '%s\n' "$oracle_bin"
