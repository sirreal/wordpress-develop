#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

$options = \HtmlApiFuzz\parse_cli_options( $argv );
$replay_path = \HtmlApiFuzz\option_string( $options, 'replay', $options['_'][0] ?? null );
if ( null === $replay_path || \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
	echo "Usage: php tools/html-api-fuzz/replay.php --replay path/to/replay.json [--output-dir DIR] [--payload-policy POLICY]\n";
	exit( null === $replay_path ? 1 : 0 );
}

$replay = \HtmlApiFuzz\read_json_file( $replay_path );
if ( ! $replay || ! array_key_exists( 'inputBase64', $replay ) ) {
	fwrite( STDERR, "Invalid replay file: {$replay_path}\n" );
	exit( 1 );
}

$output_dir = \HtmlApiFuzz\option_string( $options, 'output-dir', dirname( $replay_path ) . '/replay-' . \HtmlApiFuzz\timestamp() );
$input      = base64_decode( $replay['inputBase64'], true );
if ( false === $input ) {
	fwrite( STDERR, "Invalid base64 input in replay file: {$replay_path}\n" );
	exit( 1 );
}
\HtmlApiFuzz\ensure_dir( $output_dir );
$input_path = $output_dir . '/input.bin';
file_put_contents( $input_path, $input );
$payload_policy = \HtmlApiFuzz\option_string( $options, 'payload-policy', $replay['payloadPolicy'] ?? $replay['generator']['payloadPolicy'] ?? null );
$original_generator = is_array( $replay['generator'] ?? null ) ? $replay['generator'] : ( $replay['originalGenerator'] ?? null );

$args = array(
	__DIR__ . '/worker.php',
	'--input-file',
	$input_path,
	'--mode',
	$replay['mode'] ?? \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	'--profile',
	$replay['profile'] ?? 'replay',
	'--seed',
	(string) ( $replay['seed'] ?? 1 ),
	'--output-dir',
	$output_dir,
	'--max-tokens',
	(string) \HtmlApiFuzz\option_int( $options, 'max-tokens', (int) ( $replay['limits']['maxTokens'] ?? 2000 ) ),
	'--max-nodes',
	(string) \HtmlApiFuzz\option_int( $options, 'max-nodes', (int) ( $replay['limits']['maxNodes'] ?? 3000 ) ),
);
if ( null !== $payload_policy ) {
	$args[] = '--payload-policy';
	$args[] = $payload_policy;
}
if ( \HtmlApiFuzz\option_bool( $options, 'fail-unsupported', (bool) ( $replay['options']['failUnsupported'] ?? false ) ) ) {
	$args[] = '--fail-unsupported';
}

$proc = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 ), $output_dir . '/worker.log' );
$result = \HtmlApiFuzz\read_json_file( $output_dir . '/result.json' );
$output_replay = \HtmlApiFuzz\read_json_file( $output_dir . '/replay.json' );
if ( is_array( $output_replay ) && is_array( $original_generator ) ) {
	$output_replay['originalGenerator'] = $original_generator;
	\HtmlApiFuzz\write_json_file( $output_dir . '/replay.json', $output_replay );
}
echo \HtmlApiFuzz\json_encode_safe(
	array(
		'ok'       => $result['ok'] ?? false,
		'status'   => $result['status'] ?? 'missing-result',
		'result'   => $output_dir . '/result.json',
		'replay'   => $output_dir . '/replay.json',
		'worker'   => array(
			'code'       => $proc['code'],
			'timedOut'   => $proc['timedOut'],
			'durationMs' => $proc['durationMs'],
			'logPath'    => $proc['logPath'],
		),
		'signature' => $result['signature'] ?? null,
	)
) . "\n";
exit( ( $result['ok'] ?? false ) ? 0 : 2 );
