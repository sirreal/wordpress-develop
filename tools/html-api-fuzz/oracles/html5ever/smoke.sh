#!/usr/bin/env sh
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/../../../.." && pwd)"
binary="${HTML5EVER_ORACLE_BIN:-$script_dir/build/html5ever-tree-oracle}"
manifest="$(dirname "$binary")/build-manifest.json"
rust_root="${HTML5EVER_RUST_ROOT:-$repo_root/.cache/html5ever/rust}"

if [ -z "${CARGO_HOME:-}" ] && [ -z "${RUSTUP_HOME:-}" ] && [ -x "$rust_root/cargo/bin/cargo" ]; then
	CARGO_HOME="$rust_root/cargo"
	RUSTUP_HOME="$rust_root/rustup"
	export CARGO_HOME RUSTUP_HOME
	PATH="$CARGO_HOME/bin:$PATH"
	export PATH
fi
cargo_bin="${CARGO:-cargo}"

if [ ! -x "$binary" ]; then
	printf '%s\n' "Missing oracle binary: $binary. Run ./build.sh first." >&2
	exit 1
fi
if [ ! -f "$manifest" ]; then
	printf '%s\n' "Missing build manifest: $manifest. Run ./build.sh first." >&2
	exit 1
fi

tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/html5ever-oracle-smoke.XXXXXX")"
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

expect_cli_error() {
	name="$1"
	shift
	set +e
	"$binary" "$@" >"$tmp_dir/cli-$name.json" 2>"$tmp_dir/cli-$name.err"
	status=$?
	set -e
	if [ "$status" -eq 0 ]; then
		printf '%s\n' "Expected CLI case $name to fail." >&2
		exit 1
	fi
	php -r '
	$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
	if ( "error" !== ( $result["status"] ?? null ) || "oracle-cli-error" !== ( $result["failureClass"] ?? null ) ) {
		throw new Exception( "malformed CLI result" );
	}
	' "$tmp_dir/cli-$name.json"
}

verify_build_pair() {
	php -r '
	$before = file_get_contents( $argv[2] );
	$manifest = json_decode( $before, true, 512, JSON_THROW_ON_ERROR );
	if ( "manifest-last-v1" !== ( $manifest["publicationProtocol"] ?? null ) ) {
		throw new Exception( "publication protocol" );
	}
	$binary_hash = hash_file( "sha256", $argv[1] );
	if ( ! is_string( $binary_hash ) || $binary_hash !== ( $manifest["binarySha256"] ?? null ) ) {
		throw new Exception( "binary hash mismatch" );
	}
	$output = array();
	$status = 0;
	exec( escapeshellarg( $argv[1] ) . " --version", $output, $status );
	if ( 0 !== $status ) {
		throw new Exception( "version probe failed" );
	}
	$after = file_get_contents( $argv[2] );
	if ( ! hash_equals( $before, $after ) ) {
		throw new Exception( "manifest changed during verification" );
	}
	$version = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	$oracle = $version["oracle"] ?? null;
	if (
		"ok" !== ( $version["status"] ?? null ) ||
		! is_array( $oracle ) ||
		( $manifest["buildIdentity"] ?? null ) !== ( $oracle["buildIdentity"] ?? null ) ||
		( $manifest["cargoLockSha256"] ?? null ) !== ( $oracle["cargoLockSha256"] ?? null ) ||
		( $manifest["html5ever"]["version"] ?? null ) !== ( $oracle["html5everVersion"] ?? null ) ||
		( $manifest["html5ever"]["checksum"] ?? null ) !== ( $oracle["html5everChecksum"] ?? null ) ||
		( $manifest["markup5everRcdom"]["version"] ?? null ) !== ( $oracle["markup5everRcdomVersion"] ?? null ) ||
		( $manifest["markup5everRcdom"]["checksum"] ?? null ) !== ( $oracle["markup5everRcdomChecksum"] ?? null )
	) {
		throw new Exception( "manifest/version mismatch" );
	}
	' "$1" "$2"
}

printf '%s' 'x' >"$tmp_dir/cli-input.html"
expect_cli_error unknown-option --unknown
expect_cli_error missing-value --mode
expect_cli_error missing-mode --input "$tmp_dir/cli-input.html"
expect_cli_error missing-input --mode full-document
expect_cli_error zero-nodes --mode full-document --input "$tmp_dir/cli-input.html" --max-nodes 0
expect_cli_error noninteger-nodes --mode full-document --input "$tmp_dir/cli-input.html" --max-nodes nope
expect_cli_error zero-depth --mode full-document --input "$tmp_dir/cli-input.html" --max-depth 0
expect_cli_error noninteger-depth --mode full-document --input "$tmp_dir/cli-input.html" --max-depth nope
expect_cli_error zero-tree-bytes --mode full-document --input "$tmp_dir/cli-input.html" --max-tree-bytes 0
expect_cli_error noninteger-tree-bytes --mode full-document --input "$tmp_dir/cli-input.html" --max-tree-bytes nope
expect_cli_error version-trailing --version --unknown
expect_cli_error help-operational --help --mode full-document
expect_cli_error help-and-version --help --version
expect_cli_error duplicate-version --version --version
expect_cli_error duplicate-mode --mode full-document --mode full-document --input "$tmp_dir/cli-input.html"

"$binary" --mode fragment-body --context unsupported-context --input "$tmp_dir/cli-input.html" >"$tmp_dir/unsupported-context.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "unsupported" !== ( $result["status"] ?? null ) || "oracle-unsupported" !== ( $result["failureClass"] ?? null ) ) {
	throw new Exception( "unsupported context protocol" );
}
' "$tmp_dir/unsupported-context.json"

fake_cargo="$tmp_dir/fake-cargo"
cat >"$fake_cargo" <<'EOF'
#!/bin/sh
if [ "${1:-}" = '--version' ]; then
	printf '%s\n' 'cargo 9.9.9 (wrong)'
	exit 0
fi
printf '%s\n' invoked >"$HTML_API_FUZZ_WRONG_CARGO_LOG"
exit 97
EOF
chmod 0755 "$fake_cargo"
if CARGO="$fake_cargo" HTML_API_FUZZ_WRONG_CARGO_LOG="$tmp_dir/wrong-cargo-invoked" \
	"$script_dir/build.sh" >"$tmp_dir/wrong-cargo.out" 2>"$tmp_dir/wrong-cargo.err"; then
	printf '%s\n' 'Build accepted the wrong Cargo version.' >&2
	exit 1
fi
if [ -e "$tmp_dir/wrong-cargo-invoked" ]; then
	printf '%s\n' 'Build reached the wrong Cargo executable after its version check.' >&2
	exit 1
fi
grep -q 'Expected cargo 1.88.0' "$tmp_dir/wrong-cargo.err"

printf '%s' '<!doctype html><title>x</title><p b=2 a=1>hi&amp;</p>' >"$tmp_dir/document.html"
"$binary" --mode full-document --max-nodes 100 --input "$tmp_dir/document.html" >"$tmp_dir/document.json"

php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "ok" !== ( $result["status"] ?? null ) ) { throw new Exception( "full document status" ); }
if ( "html5ever-source" !== ( $result["oracle"]["kind"] ?? null ) ) { throw new Exception( "oracle kind" ); }
if ( true !== ( $result["oracle"]["available"] ?? null ) ) { throw new Exception( "oracle availability" ); }
if ( "0.39.0" !== ( $result["oracle"]["html5everVersion"] ?? null ) ) { throw new Exception( "html5ever pin" ); }
if ( "1.88.0" !== ( $result["oracle"]["rustToolchain"] ?? null ) ) { throw new Exception( "Rust pin" ); }
if ( base64_decode( $result["treeBase64"], true ) !== $result["tree"] ) { throw new Exception( "tree base64" ); }
if ( ! str_starts_with( $result["tree"], "<!DOCTYPE html>\n<html>\n" ) ) { throw new Exception( "doctype/document tree" ); }
if ( false === strpos( $result["tree"], "      a=\"1\"\n      b=\"2\"\n      \"hi&\"" ) ) { throw new Exception( "canonical attributes/text" ); }
' "$tmp_dir/document.json"

printf '%s' '<!doctype html><html><head><noscript><meta name=x></noscript></head><body><noscript><b>y</b></noscript></body></html>' >"$tmp_dir/noscript-document.html"
"$binary" --mode full-document --max-nodes 100 --input "$tmp_dir/noscript-document.html" >"$tmp_dir/noscript-document.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$expected = "    <noscript>\n      <meta>\n        name=\"x\"\n  <body>\n    <noscript>\n      <b>\n        \"y\"\n";
if ( false === strpos( $result["tree"] ?? "", $expected ) ) { throw new Exception( "document scripting mode" ); }
' "$tmp_dir/noscript-document.json"

printf '%s' '<noscript><b>x</b></noscript>' >"$tmp_dir/noscript-fragment.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --input "$tmp_dir/noscript-fragment.html" >"$tmp_dir/noscript-fragment.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$expected = "<noscript>\n  <b>\n    \"x\"\n\n";
if ( $expected !== ( $result["tree"] ?? null ) ) { throw new Exception( "fragment scripting mode" ); }
' "$tmp_dir/noscript-fragment.json"

printf '%s' 'x' >"$tmp_dir/context.html"
for context in body div p td tr table caption colgroup select option template title textarea script style svg math; do
	"$binary" --mode fragment-body --context "$context" --max-nodes 100 --input "$tmp_dir/context.html" >"$tmp_dir/context-$context.json"
	php -r '
	$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
	$expected = "colgroup" === $argv[2] ? "\n" : "\"x\"\n\n";
	if ( "ok" !== ( $result["status"] ?? null ) || $expected !== ( $result["tree"] ?? null ) ) {
		throw new Exception( "context coverage: " . $argv[2] );
	}
	' "$tmp_dir/context-$context.json" "$context"
done

: >"$tmp_dir/empty.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --input "$tmp_dir/empty.html" >"$tmp_dir/empty.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "\n" !== ( $result["tree"] ?? null ) || 0 !== ( $result["nodeCount"] ?? null ) ) { throw new Exception( "empty fragment contract" ); }
' "$tmp_dir/empty.json"

printf '%s' '<td>x<td>y' >"$tmp_dir/fragment.html"
"$binary" --mode fragment-body --context table --max-nodes 100 --input "$tmp_dir/fragment.html" >"$tmp_dir/fragment.json"

php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "ok" !== ( $result["status"] ?? null ) ) { throw new Exception( "fragment status" ); }
$expected = "<tbody>\n  <tr>\n    <td>\n      \"x\"\n    <td>\n      \"y\"\n\n";
if ( $expected !== $result["tree"] ) { throw new Exception( "contextual table fragment: " . $result["tree"] ); }
' "$tmp_dir/fragment.json"

printf '%s' '<option>a<option>b' >"$tmp_dir/select.html"
"$binary" --mode fragment-select --context select --max-nodes 100 --input "$tmp_dir/select.html" >"$tmp_dir/select.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$expected = "<option>\n  \"a\"\n<option>\n  \"b\"\n\n";
if ( $expected !== ( $result["tree"] ?? null ) ) { throw new Exception( "contextual select fragment" ); }
' "$tmp_dir/select.json"

printf '%s' '<b>&amp;' >"$tmp_dir/title.html"
"$binary" --mode fragment-title --context title --max-nodes 100 --input "$tmp_dir/title.html" >"$tmp_dir/title.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "\"<b>&\"\n\n" !== ( $result["tree"] ?? null ) ) { throw new Exception( "RCDATA title context" ); }
' "$tmp_dir/title.json"

printf '%s' '&amp;<b>' >"$tmp_dir/script.html"
"$binary" --mode fragment-script --context script --max-nodes 100 --input "$tmp_dir/script.html" >"$tmp_dir/script.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "\"&amp;<b>\"\n\n" !== ( $result["tree"] ?? null ) ) { throw new Exception( "raw-text script context" ); }
' "$tmp_dir/script.json"

printf '%s' '<b>x</b>' >"$tmp_dir/template-fragment.html"
"$binary" --mode fragment-template --context template --max-nodes 100 --input "$tmp_dir/template-fragment.html" >"$tmp_dir/template-fragment.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "<b>\n  \"x\"\n\n" !== ( $result["tree"] ?? null ) ) { throw new Exception( "template fragment context" ); }
' "$tmp_dir/template-fragment.json"

printf '%s' '<template><p>x</template>' >"$tmp_dir/template-document.html"
"$binary" --mode full-document --max-nodes 100 --input "$tmp_dir/template-document.html" >"$tmp_dir/template-document.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( false === strpos( $result["tree"] ?? "", "    <template>\n      content\n        <p>\n          \"x\"\n" ) ) { throw new Exception( "template content marker/tree" ); }
if ( ! str_ends_with( $result["tree"] ?? "", "\n\n" ) ) { throw new Exception( "canonical trailing newline" ); }
' "$tmp_dir/template-document.json"

printf '%s' '<circle xlink:href=q></circle>' >"$tmp_dir/svg.html"
"$binary" --mode fragment-svg --context svg --max-nodes 100 --input "$tmp_dir/svg.html" >"$tmp_dir/svg.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$expected = "<svg circle>\n  xlink href=\"q\"\n\n";
if ( $expected !== ( $result["tree"] ?? null ) ) { throw new Exception( "SVG fragment namespace" ); }
' "$tmp_dir/svg.json"

printf '%s' '<mi>x</mi>' >"$tmp_dir/math.html"
"$binary" --mode fragment-math --context math --max-nodes 100 --input "$tmp_dir/math.html" >"$tmp_dir/math.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$expected = "<math mi>\n  \"x\"\n\n";
if ( $expected !== ( $result["tree"] ?? null ) ) { throw new Exception( "MathML fragment namespace" ); }
' "$tmp_dir/math.json"

printf '%s' '<svg xml:lang=x xlink:href=y xmlns:xlink=z a=0></svg>' >"$tmp_dir/namespaces.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --input "$tmp_dir/namespaces.html" >"$tmp_dir/namespaces.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$expected = "<svg svg>\n  a=\"0\"\n  xlink href=\"y\"\n  xml lang=\"x\"\n  xmlns xlink=\"z\"\n\n";
if ( $expected !== ( $result["tree"] ?? null ) ) { throw new Exception( "attribute namespace/order contract" ); }
' "$tmp_dir/namespaces.json"

printf '%s' '<svg><foreignobject><div></div></foreignobject><altglyph attributename=x></altglyph><lineargradient gradientunits=userSpaceOnUse></lineargradient></svg>' >"$tmp_dir/adjusted-svg.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --input "$tmp_dir/adjusted-svg.html" >"$tmp_dir/adjusted-svg.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$tree = $result["tree"] ?? "";
foreach ( array( "<svg foreignObject>\n", "<svg altGlyph>\n", "  attributeName=\"x\"\n", "<svg linearGradient>\n", "  gradientUnits=\"userSpaceOnUse\"\n" ) as $expected ) {
    if ( false === strpos( $tree, $expected ) ) { throw new Exception( "adjusted SVG name: " . $expected ); }
}
' "$tmp_dir/adjusted-svg.json"

printf '%s' '<b>x</b>' >"$tmp_dir/limit.html"
"$binary" --mode fragment-body --context body --max-nodes 1 --input "$tmp_dir/limit.html" >"$tmp_dir/limit.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "error" !== ( $result["status"] ?? null ) ) { throw new Exception( "node limit status" ); }
if ( "node-limit-exceeded" !== ( $result["failureClass"] ?? null ) ) { throw new Exception( "node limit failure class" ); }
if ( ! is_int( $result["nodeCount"] ?? null ) || $result["nodeCount"] < 0 ) { throw new Exception( "node limit count" ); }
if ( true !== ( $result["oracle"]["available"] ?? null ) ) { throw new Exception( "node limit oracle availability" ); }
' "$tmp_dir/limit.json"

php -r 'file_put_contents( $argv[1], str_repeat( "<div>", 12 ) );' "$tmp_dir/deep.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --max-depth 3 --input "$tmp_dir/deep.html" >"$tmp_dir/deep.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "error" !== ( $result["status"] ?? null ) || "depth-limit-exceeded" !== ( $result["failureClass"] ?? null ) ) {
	throw new Exception( "depth limit" );
}
' "$tmp_dir/deep.json"

printf '%s' '<p>tree output must exceed twenty bytes</p>' >"$tmp_dir/tree-bytes.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --max-tree-bytes 20 --input "$tmp_dir/tree-bytes.html" >"$tmp_dir/tree-bytes.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "error" !== ( $result["status"] ?? null ) || "tree-byte-limit-exceeded" !== ( $result["failureClass"] ?? null ) ) {
	throw new Exception( "tree byte limit" );
}
' "$tmp_dir/tree-bytes.json"

php -r 'file_put_contents( $argv[1], "<p>" . str_repeat( "x", 1048576 ) . "</p>" );' "$tmp_dir/large-text.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --max-tree-bytes 64 --input "$tmp_dir/large-text.html" >"$tmp_dir/large-text.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "error" !== ( $result["status"] ?? null ) || "tree-byte-limit-exceeded" !== ( $result["failureClass"] ?? null ) ) {
	throw new Exception( "large text scalar limit" );
}
' "$tmp_dir/large-text.json"

php -r 'file_put_contents( $argv[1], "<p data-x=\"" . str_repeat( "y", 1048576 ) . "\"></p>" );' "$tmp_dir/large-attribute.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --max-tree-bytes 64 --input "$tmp_dir/large-attribute.html" >"$tmp_dir/large-attribute.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "error" !== ( $result["status"] ?? null ) || "tree-byte-limit-exceeded" !== ( $result["failureClass"] ?? null ) ) {
	throw new Exception( "large attribute scalar limit" );
}
' "$tmp_dir/large-attribute.json"

php -r 'file_put_contents( $argv[1], "<p>\xFF</p>" );' "$tmp_dir/invalid-utf8.html"
"$binary" --mode fragment-body --context body --max-nodes 100 --input "$tmp_dir/invalid-utf8.html" >"$tmp_dir/invalid-utf8.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "unsupported" !== ( $result["status"] ?? null ) || "oracle-unsupported" !== ( $result["failureClass"] ?? null ) ) {
	throw new Exception( "invalid UTF-8 was not explicitly unsupported" );
}
if ( false === strpos( $result["unsupported"]["message"] ?? "", "refusing lossy transcoding" ) ) {
	throw new Exception( "invalid UTF-8 diagnostic" );
}
' "$tmp_dir/invalid-utf8.json"

"$binary" --version >"$tmp_dir/version.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "ok" !== ( $result["status"] ?? null ) ) { throw new Exception( "version status" ); }
if ( true !== ( $result["oracle"]["available"] ?? null ) ) { throw new Exception( "version availability" ); }
if ( "0.39.0+unofficial" !== ( $result["oracle"]["markup5everRcdomVersion"] ?? null ) ) { throw new Exception( "rcdom pin" ); }
if ( "46a1761807faccc9a19e86944bbf40610014066306f96edcdedc2fb714bcb7b8" !== ( $result["oracle"]["html5everChecksum"] ?? null ) ) { throw new Exception( "html5ever checksum" ); }
if ( "3ac010f19d6c4af81eeb4018a39d7a115de9d285af45c126a4ac02e6fc5716b7" !== ( $result["oracle"]["markup5everRcdomChecksum"] ?? null ) ) { throw new Exception( "rcdom checksum" ); }
$manifest = json_decode( file_get_contents( $argv[2] ), true, 512, JSON_THROW_ON_ERROR );
$binary_hash = hash_file( "sha256", $argv[3] );
$lock_hash = hash_file( "sha256", $argv[4] );
if ( ! is_string( $binary_hash ) || $binary_hash !== ( $manifest["binarySha256"] ?? null ) ) { throw new Exception( "binary manifest hash" ); }
if ( ! is_string( $lock_hash ) || $lock_hash !== ( $manifest["cargoLockSha256"] ?? null ) ) { throw new Exception( "lock manifest hash" ); }
if ( $lock_hash !== ( $result["oracle"]["cargoLockSha256"] ?? null ) ) { throw new Exception( "embedded lock hash" ); }
if ( ( $manifest["buildIdentity"] ?? null ) !== ( $result["oracle"]["buildIdentity"] ?? null ) ) { throw new Exception( "embedded build identity" ); }
if ( ! is_string( $result["oracle"]["buildIdentity"] ?? null ) || 1 !== preg_match( "/^[0-9a-f]{64}$/", $result["oracle"]["buildIdentity"] ) ) { throw new Exception( "build identity format" ); }
' "$tmp_dir/version.json" "$manifest" "$binary" "$script_dir/Cargo.lock"

build_identity="$(php -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $v["oracle"]["buildIdentity"];' "$tmp_dir/version.json")"
cargo_lock_sha256="$(php -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $v["oracle"]["cargoLockSha256"];' "$tmp_dir/version.json")"
set +e
(
	unset HTML_API_FUZZ_HTML5EVER_BUILD_IDENTITY HTML_API_FUZZ_HTML5EVER_CARGO_LOCK_SHA256
	CARGO_TARGET_DIR="$repo_root/.cache/html5ever/target" "$cargo_bin" check \
		--manifest-path "$script_dir/Cargo.toml" --locked
) >"$tmp_dir/missing-identity.out" 2>"$tmp_dir/missing-identity.err"
missing_identity_status=$?
set -e
if [ "$missing_identity_status" -eq 0 ]; then
	printf '%s\n' 'A build without injected identity unexpectedly compiled.' >&2
	exit 1
fi
grep -q 'HTML_API_FUZZ_HTML5EVER_BUILD_IDENTITY' "$tmp_dir/missing-identity.err"
grep -q 'not defined at compile time' "$tmp_dir/missing-identity.err"

HTML_API_FUZZ_HTML5EVER_BUILD_IDENTITY="$build_identity" \
HTML_API_FUZZ_HTML5EVER_CARGO_LOCK_SHA256="$cargo_lock_sha256" \
CARGO_TARGET_DIR="$repo_root/.cache/html5ever/target" "$cargo_bin" test \
	--manifest-path "$script_dir/Cargo.toml" --locked

verify_build_pair "$binary" "$manifest"
cp "$binary" "$tmp_dir/tampered-binary"
cp "$manifest" "$tmp_dir/tampered-binary-manifest.json"
printf '%s' 'tamper' >>"$tmp_dir/tampered-binary"
if verify_build_pair "$tmp_dir/tampered-binary" "$tmp_dir/tampered-binary-manifest.json" >/dev/null 2>&1; then
	printf '%s\n' 'Tampered binary/manifest pair was accepted.' >&2
	exit 1
fi

cp "$binary" "$tmp_dir/tampered-manifest-binary"
php -r '
$manifest = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$manifest["binarySha256"] = str_repeat( "0", 64 );
file_put_contents( $argv[2], json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
' "$manifest" "$tmp_dir/tampered-manifest.json"
if verify_build_pair "$tmp_dir/tampered-manifest-binary" "$tmp_dir/tampered-manifest.json" >/dev/null 2>&1; then
	printf '%s\n' 'Tampered manifest/binary pair was accepted.' >&2
	exit 1
fi

printf '%s\n' 'OK html5ever-oracle-smoke'
