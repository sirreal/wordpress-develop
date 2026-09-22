#!/usr/bin/env sh
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/../../../.." && pwd)"
target_dir="${HTML5EVER_TARGET_DIR:-$repo_root/.cache/html5ever/target}"
oracle_build_dir="$script_dir/build"
oracle_bin="$oracle_build_dir/html5ever-tree-oracle"
manifest_path="$oracle_build_dir/build-manifest.json"
rust_root="${HTML5EVER_RUST_ROOT:-$repo_root/.cache/html5ever/rust}"

if [ -z "${CARGO_HOME:-}" ] && [ -z "${RUSTUP_HOME:-}" ] && [ -x "$rust_root/cargo/bin/cargo" ]; then
	CARGO_HOME="$rust_root/cargo"
	RUSTUP_HOME="$rust_root/rustup"
	export CARGO_HOME RUSTUP_HOME
	PATH="$CARGO_HOME/bin:$PATH"
	export PATH
fi

cargo_bin="${CARGO:-cargo}"
rustc_bin="${RUSTC:-rustc}"
if ! command -v "$cargo_bin" >/dev/null 2>&1; then
	printf '%s\n' 'cargo is required. Run ./install-rust.sh, then add the printed bin directory to PATH.' >&2
	exit 1
fi
if ! command -v "$rustc_bin" >/dev/null 2>&1; then
	printf '%s\n' 'rustc is required. Run ./install-rust.sh, then add the printed bin directory to PATH.' >&2
	exit 1
fi

rustc_version="$("$rustc_bin" --version)"
case "$rustc_version" in
	'rustc 1.88.0 ('*')') ;;
	*)
		printf '%s\n' "Expected rustc 1.88.0; got: $rustc_version" >&2
		exit 1
		;;
esac
cargo_version="$("$cargo_bin" --version)"
case "$cargo_version" in
	'cargo 1.88.0 ('*')') ;;
	*)
		printf '%s\n' "Expected cargo 1.88.0; got: $cargo_version" >&2
		exit 1
		;;
esac

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{ print $1 }'
	else
		printf '%s\n' 'sha256sum or shasum is required.' >&2
		return 1
	fi
}

sha256_stdin() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 | awk '{ print $1 }'
	else
		printf '%s\n' 'sha256sum or shasum is required.' >&2
		return 1
	fi
}

cargo_toml_sha256="$(sha256_file "$script_dir/Cargo.toml")"
cargo_lock_sha256="$(sha256_file "$script_dir/Cargo.lock")"
rust_toolchain_sha256="$(sha256_file "$script_dir/rust-toolchain.toml")"
source_sha256="$(sha256_file "$script_dir/src/main.rs")"
build_identity="$(
	printf '%s\n' \
		"Cargo.toml $cargo_toml_sha256" \
		"Cargo.lock $cargo_lock_sha256" \
		"rust-toolchain.toml $rust_toolchain_sha256" \
		"src/main.rs $source_sha256" |
		sha256_stdin
)"

mkdir -p "$oracle_build_dir"
tmp_bin="$oracle_build_dir/.html5ever-tree-oracle.$$"
tmp_manifest="$oracle_build_dir/.build-manifest.json.$$"
trap 'rm -f "$tmp_bin" "$tmp_manifest"' EXIT HUP INT TERM

cd "$script_dir"
HTML_API_FUZZ_HTML5EVER_BUILD_IDENTITY="$build_identity" \
HTML_API_FUZZ_HTML5EVER_CARGO_LOCK_SHA256="$cargo_lock_sha256" \
CARGO_TARGET_DIR="$target_dir" "$cargo_bin" build \
	--manifest-path "$script_dir/Cargo.toml" \
	--release \
	--locked

cp "$target_dir/release/html5ever-tree-oracle" "$tmp_bin"
chmod 0755 "$tmp_bin"
binary_sha256="$(sha256_file "$tmp_bin")"
version_json="$("$tmp_bin" --version)"

php -r '
function package_from_lock( string $lock, string $name ): array {
	if ( ! preg_match_all( "/\\[\\[package\\]\\]\\s*(.*?)(?=\\n\\[\\[package\\]\\]|\\z)/s", $lock, $packages ) ) {
		throw new RuntimeException( "Malformed Cargo.lock." );
	}
	foreach ( $packages[1] as $package ) {
		if ( ! preg_match( "/^name\\s*=\\s*\"([^\"]+)\"/m", $package, $package_name ) || $name !== $package_name[1] ) {
			continue;
		}
		preg_match( "/^version\\s*=\\s*\"([^\"]+)\"/m", $package, $version );
		preg_match( "/^checksum\\s*=\\s*\"([^\"]+)\"/m", $package, $checksum );
		if ( ! isset( $version[1], $checksum[1] ) || 1 !== preg_match( "/^[0-9a-f]{64}$/", $checksum[1] ) ) {
			throw new RuntimeException( "Incomplete locked identity for {$name}." );
		}
		return array( "version" => $version[1], "checksum" => $checksum[1] );
	}
	throw new RuntimeException( "Missing {$name} in Cargo.lock." );
}

$lock = file_get_contents( $argv[1] );
$version = json_decode( $argv[2], true, 512, JSON_THROW_ON_ERROR );
$html5ever = package_from_lock( $lock, "html5ever" );
$rcdom = package_from_lock( $lock, "markup5ever_rcdom" );
$oracle = $version["oracle"] ?? null;
if (
	"ok" !== ( $version["status"] ?? null ) ||
	! is_array( $oracle ) ||
	"html5ever-source" !== ( $oracle["kind"] ?? null ) ||
	true !== ( $oracle["available"] ?? null ) ||
	$argv[3] !== ( $oracle["buildIdentity"] ?? null ) ||
	$argv[4] !== ( $oracle["cargoLockSha256"] ?? null ) ||
	$html5ever["version"] !== ( $oracle["html5everVersion"] ?? null ) ||
	$html5ever["checksum"] !== ( $oracle["html5everChecksum"] ?? null ) ||
	$rcdom["version"] !== ( $oracle["markup5everRcdomVersion"] ?? null ) ||
	$rcdom["checksum"] !== ( $oracle["markup5everRcdomChecksum"] ?? null ) ||
	"1.88.0" !== ( $oracle["rustToolchain"] ?? null )
) {
	throw new RuntimeException( "Built html5ever binary identity does not match its checked-in inputs." );
}
$manifest = array(
	"schemaVersion"       => 1,
	"kind"                => "html-api-fuzz-html5ever-build",
	"publicationProtocol" => "manifest-last-v1",
	"builtAt"             => gmdate( "c" ),
	"buildIdentity"       => $argv[3],
	"cargoTomlSha256"     => $argv[5],
	"cargoLockSha256"     => $argv[4],
	"rustToolchainSha256" => $argv[6],
	"sourceSha256"        => $argv[7],
	"rustc"               => $argv[8],
	"cargo"               => $argv[9],
	"html5ever"           => $html5ever,
	"markup5everRcdom"    => $rcdom,
	"binarySha256"        => $argv[10],
);
$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
if ( strlen( $json ) !== file_put_contents( $argv[11], $json ) ) {
	throw new RuntimeException( "Could not write html5ever build manifest." );
}
' "$script_dir/Cargo.lock" "$version_json" "$build_identity" "$cargo_lock_sha256" "$cargo_toml_sha256" "$rust_toolchain_sha256" "$source_sha256" "$rustc_version" "$cargo_version" "$binary_sha256" "$tmp_manifest"

# The manifest is the commit marker. Readers accept a build only when the
# manifest is unchanged across executable hashing and identity probing. A
# crash before this second rename therefore leaves a fail-closed pair.
mv "$tmp_bin" "$oracle_bin"
mv "$tmp_manifest" "$manifest_path"
trap - EXIT HUP INT TERM

printf '%s\n' "$oracle_bin"
