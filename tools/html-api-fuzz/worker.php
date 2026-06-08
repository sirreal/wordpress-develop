#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

$options = \HtmlApiFuzz\parse_cli_options( $argv );

try {
	$result = \HtmlApiFuzz\Worker::run( $options );
	echo \HtmlApiFuzz\json_encode_safe( $result ) . "\n";
	exit( ( $result['ok'] ?? false ) ? 0 : 2 );
} catch ( Throwable $e ) {
	$output_dir = \HtmlApiFuzz\option_string( $options, 'output-dir', null );
	$fallback = array(
		'schemaVersion'  => 1,
		'kind'           => 'html-api-fuzz-worker-result',
		'createdAt'      => gmdate( 'c' ),
		'ok'             => false,
		'status'         => 'worker-fatal',
		'failureClass'   => 'fatal-error',
		'failureSnippet' => $e->getMessage(),
		'throwable'      => get_class( $e ),
		'seed'           => \HtmlApiFuzz\option_int( $options, 'seed', 1 ),
		'profile'        => \HtmlApiFuzz\option_string( $options, 'profile', 'auto' ),
		'mode'           => \HtmlApiFuzz\option_string( $options, 'mode', 'auto' ),
		'payloadPolicy'  => \HtmlApiFuzz\option_string( $options, 'payload-policy', null ),
		'inputSource'    => \HtmlApiFuzz\option_string( $options, 'input-file', null ) ? 'input-file' : ( \HtmlApiFuzz\option_string( $options, 'input-base64', null ) ? 'input-base64' : 'generated' ),
	);

	if ( null !== $output_dir ) {
		$fallback['paths'] = array(
			'outputDir'  => $output_dir,
			'resultPath' => $output_dir . DIRECTORY_SEPARATOR . 'result.json',
			'replayPath' => $output_dir . DIRECTORY_SEPARATOR . 'replay.json',
		);
		$signature = \HtmlApiFuzz\Signature::from_result( $fallback );
		if ( null !== $signature ) {
			$fallback['signature'] = $signature;
		}
		\HtmlApiFuzz\write_json_file( $output_dir . DIRECTORY_SEPARATOR . 'result.json', $fallback );
	}

	fwrite( STDERR, \HtmlApiFuzz\json_encode_safe( $fallback ) . "\n" );
	exit( 1 );
}
