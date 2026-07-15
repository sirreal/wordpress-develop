#!/usr/bin/env sh
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
binary="${HTML5EVER_ORACLE_BIN:-$script_dir/build/html5ever-tree-oracle}"

if [ ! -x "$binary" ]; then
	printf '%s\n' "Missing oracle binary: $binary. Run ./build.sh first." >&2
	exit 1
fi

tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/html5ever-oracle-smoke.XXXXXX")"
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

printf '%s' '<!doctype html><title>x</title><p b=2 a=1>hi&amp;</p>' >"$tmp_dir/document.html"
"$binary" --mode full-document --max-nodes 100 --input "$tmp_dir/document.html" >"$tmp_dir/document.json"

php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "ok" !== ( $result["status"] ?? null ) ) { throw new Exception( "full document status" ); }
if ( "html5ever-source" !== ( $result["oracle"]["kind"] ?? null ) ) { throw new Exception( "oracle kind" ); }
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
if "$binary" --mode fragment-body --context body --max-nodes 1 --input "$tmp_dir/limit.html" >"$tmp_dir/limit.json"; then
	printf '%s\n' 'Expected the node-limited parse to exit nonzero.' >&2
	exit 1
fi
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "node-limit-exceeded" !== ( $result["failureClass"] ?? null ) ) { throw new Exception( "node limit failure class" ); }
' "$tmp_dir/limit.json"

"$binary" --version >"$tmp_dir/version.json"
php -r '
$result = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
if ( "0.39.0+unofficial" !== ( $result["oracle"]["markup5everRcdomVersion"] ?? null ) ) { throw new Exception( "rcdom pin" ); }
if ( "46a1761807faccc9a19e86944bbf40610014066306f96edcdedc2fb714bcb7b8" !== ( $result["oracle"]["html5everChecksum"] ?? null ) ) { throw new Exception( "html5ever checksum" ); }
if ( "3ac010f19d6c4af81eeb4018a39d7a115de9d285af45c126a4ac02e6fc5716b7" !== ( $result["oracle"]["markup5everRcdomChecksum"] ?? null ) ) { throw new Exception( "rcdom checksum" ); }
' "$tmp_dir/version.json"

printf '%s\n' 'OK html5ever-oracle-smoke'
