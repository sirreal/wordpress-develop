#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

\CssDeclarationFuzz\throw_on_php_error();
$options = \CssDeclarationFuzz\parse_cli_options( $argv );
if ( \CssDeclarationFuzz\option_bool( $options, 'help', false ) ) {
	echo "Usage: php tools/css-declaration-fuzz/replay.php --seed N [--style-base64 BASE64]\n";
	exit( 0 );
}

$seed  = \CssDeclarationFuzz\option_int( $options, 'seed', 1 );
$input = \CssDeclarationFuzz\option_string( $options, 'style-base64', null );
$style = null;
if ( null !== $input ) {
	$style = base64_decode( $input, true );
	if ( false === $style ) {
		fwrite( STDERR, "--style-base64 is not valid base64.\n" );
		exit( 1 );
	}
}

$result                   = \CssDeclarationFuzz\Worker::run_case( $seed, $style );
$result['styleBase64']     = base64_encode( $result['style'] );
$result['stylePrintable']  = \CssDeclarationFuzz\printable_bytes( $result['style'] );
unset( $result['style'] );
echo \CssDeclarationFuzz\json_encode_safe( $result ) . "\n";
exit( empty( $result['failures'] ) ? 0 : 2 );
