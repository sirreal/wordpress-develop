#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

$options = \HtmlApiFuzz\parse_cli_options( $argv );

function html_api_fuzz_worker_fatal_result( array $options, Throwable $e, ?string $output_dir ): array {
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
	try {
		$fallback['oracle'] = \HtmlApiFuzz\OracleRenderer::from_options( $options )->metadata();
	} catch ( Throwable $oracle_error ) {
		$fallback['oracle'] = array(
			'kind'  => \HtmlApiFuzz\option_string( $options, 'dom-oracle', \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM ),
			'error' => $oracle_error->getMessage(),
		);
	}

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
		\HtmlApiFuzz\write_json_file_atomic( $output_dir . DIRECTORY_SEPARATOR . 'result.json', $fallback );
	}

	return $fallback;
}

$batch_count = \HtmlApiFuzz\option_int( $options, 'batch-count', 1 );

if ( $batch_count > 1 ) {
	/*
	 * Batch mode: --output-dir is the run directory; each seed writes its
	 * artifacts to seed-N/primary as the runner lays them out. A throwable
	 * for one seed is recorded and the batch continues; only a process-level
	 * fatal (which kills this loop) leaves seeds without results, and the
	 * runner re-runs those individually.
	 */
	$run_dir     = \HtmlApiFuzz\option_string( $options, 'output-dir', getcwd() . DIRECTORY_SEPARATOR . 'html-api-fuzz-batch' );
	$start_seed  = \HtmlApiFuzz\option_int( $options, 'seed', 1 );
	$seed_stride = max( 1, \HtmlApiFuzz\option_int( $options, 'seed-stride', 1 ) );
	$summaries   = array();

	for ( $i = 0; $i < $batch_count; $i++ ) {
		$seed       = $start_seed + ( $i * $seed_stride );
		$seed_dir   = $run_dir . DIRECTORY_SEPARATOR . 'seed-' . $seed . DIRECTORY_SEPARATOR . 'primary';
		$seed_opts  = $options;
		$seed_opts['seed']       = (string) $seed;
		$seed_opts['output-dir'] = $seed_dir;
		unset( $seed_opts['batch-count'] );

		try {
			$result = \HtmlApiFuzz\Worker::run( $seed_opts );
		} catch ( Throwable $e ) {
			$result = html_api_fuzz_worker_fatal_result( $seed_opts, $e, $seed_dir );
		}

		$summaries[] = array(
			'seed'         => $seed,
			'ok'           => $result['ok'] ?? false,
			'status'       => $result['status'] ?? 'unknown',
			'failureClass' => $result['failureClass'] ?? null,
		);
	}

	echo \HtmlApiFuzz\json_encode_safe(
		array(
			'kind'    => 'html-api-fuzz-worker-batch',
			'count'   => $batch_count,
			'results' => $summaries,
		)
	) . "\n";
	exit( 0 );
}

try {
	$result = \HtmlApiFuzz\Worker::run( $options );
	echo \HtmlApiFuzz\json_encode_safe( $result ) . "\n";
	exit( ( $result['ok'] ?? false ) ? 0 : 2 );
} catch ( Throwable $e ) {
	$fallback = html_api_fuzz_worker_fatal_result( $options, $e, \HtmlApiFuzz\option_string( $options, 'output-dir', null ) );
	fwrite( STDERR, \HtmlApiFuzz\json_encode_safe( $fallback ) . "\n" );
	exit( 1 );
}
