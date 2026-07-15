#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_preflight_fail( string $message ): void {
	fwrite( STDERR, "Preflight verification failed: {$message}\n" );
	exit( 1 );
}

$options     = \HtmlApiFuzz\parse_cli_options( $argv );
$run_dir     = \HtmlApiFuzz\option_string( $options, 'run-dir', null );
$max_seeds   = \HtmlApiFuzz\option_int( $options, 'max-seeds', 100 );
$start_seed  = \HtmlApiFuzz\option_int( $options, 'start-seed', 1 );
$seed_stride = \HtmlApiFuzz\option_int( $options, 'seed-stride', 1 );
if ( null === $run_dir || $max_seeds < 1 || $seed_stride < 1 ) {
	html_api_fuzz_preflight_fail( 'Expected --run-dir, positive --max-seeds, and positive --seed-stride.' );
}

$definitions = array(
	'lexbor' => array(
		'kind'   => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'pinKey' => 'lexborCommit',
		'pin'    => trim( file_get_contents( __DIR__ . '/oracles/lexbor/COMMIT' ) ),
	),
	'html5ever' => array(
		'kind'   => \HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
		'pinKey' => 'html5everVersion',
		'pin'    => '0.39.0',
		'additionalPins' => array(
			'html5everChecksum'         => '46a1761807faccc9a19e86944bbf40610014066306f96edcdedc2fb714bcb7b8',
			'markup5everRcdomVersion'   => '0.39.0+unofficial',
			'markup5everRcdomChecksum'  => '3ac010f19d6c4af81eeb4018a39d7a115de9d285af45c126a4ac02e6fc5716b7',
			'rustToolchain'              => '1.88.0',
		),
	),
	'chrome' => array(
		'kind'   => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
		'pinKey' => 'pinnedChromeVersion',
		'pin'    => trim( file_get_contents( __DIR__ . '/oracles/chrome/VERSION' ) ),
	),
);

$expected_seeds = array();
for ( $i = 0; $i < $max_seeds; ++$i ) {
	$expected_seeds[] = $start_seed + ( $i * $seed_stride );
}

$baseline_inputs = null;
$summary = array();
$infrastructure_failures = array(
	'oracle-unavailable',
	'oracle-renderer-error',
	'fatal-error',
	'worker-timeout',
);

foreach ( $definitions as $label => $definition ) {
	$directory = $run_dir . '/' . $label;
	$state = \HtmlApiFuzz\read_json_file( $directory . '/state.json' );
	if ( ! is_array( $state ) ) {
		html_api_fuzz_preflight_fail( "Missing state for {$label}." );
	}
	if ( 'max-seeds' !== ( $state['stopReason'] ?? null ) ) {
		html_api_fuzz_preflight_fail( "{$label} stopped for " . ( $state['stopReason'] ?? 'unknown' ) . '.' );
	}
	$oracle = is_array( $state['oracle'] ?? null ) ? $state['oracle'] : array();
	if ( $definition['kind'] !== ( $oracle['kind'] ?? null ) ) {
		html_api_fuzz_preflight_fail( "{$label} recorded the wrong oracle kind." );
	}
	if ( $definition['pin'] !== ( $oracle[ $definition['pinKey'] ] ?? null ) ) {
		html_api_fuzz_preflight_fail( "{$label} did not record the tracked pin." );
	}
	foreach ( $definition['additionalPins'] ?? array() as $pin_key => $pin ) {
		if ( $pin !== ( $oracle[ $pin_key ] ?? null ) ) {
			html_api_fuzz_preflight_fail( "{$label} did not record the tracked {$pin_key} pin." );
		}
	}
	if ( \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP === $definition['kind'] ) {
		if ( $definition['pin'] !== ( $oracle['browserVersion'] ?? null ) || ! is_int( $oracle['browserPid'] ?? null ) ) {
			html_api_fuzz_preflight_fail( 'Chrome did not record the pinned live browser instance.' );
		}
	}

	$db = new SQLite3( $directory . '/' . \HtmlApiFuzz\ResultStore::FILENAME, SQLITE3_OPEN_READONLY );
	$inputs = array();
	$statuses = array();
	$result = $db->query( 'SELECT seed, input_sha1, status, failure_class, worker_timed_out, oracle_kind FROM attempts ORDER BY id' );
	while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
		$seed = (int) $row['seed'];
		$inputs[ $seed ] = $row['input_sha1'];
		$status = (string) $row['status'];
		$statuses[ $status ] = ( $statuses[ $status ] ?? 0 ) + 1;
		if ( $definition['kind'] !== $row['oracle_kind'] ) {
			html_api_fuzz_preflight_fail( "{$label} attempt {$seed} recorded the wrong oracle." );
		}
		if ( 0 !== (int) $row['worker_timed_out'] || in_array( $row['failure_class'], $infrastructure_failures, true ) ) {
			html_api_fuzz_preflight_fail( "{$label} seed {$seed} had infrastructure failure " . ( $row['failure_class'] ?? 'worker-timeout' ) . '.' );
		}
	}
	$db->close();
	if ( array_keys( $inputs ) !== $expected_seeds ) {
		html_api_fuzz_preflight_fail( "{$label} did not attempt the exact requested seed sequence." );
	}
	if ( null === $baseline_inputs ) {
		$baseline_inputs = $inputs;
	} elseif ( $baseline_inputs !== $inputs ) {
		html_api_fuzz_preflight_fail( "{$label} inputs differ from the shared-seed baseline." );
	}
	ksort( $statuses );
	$summary[ $label ] = array(
		'oracle'   => $definition['kind'],
		'pin'      => $definition['pin'],
		'attempts' => count( $inputs ),
		'statuses' => $statuses,
	);
}

echo \HtmlApiFuzz\json_encode_safe(
	array(
		'ok'        => true,
		'kind'      => 'html-api-fuzz-three-oracle-preflight',
		'runDir'    => $run_dir,
		'sharedSeedCount' => $max_seeds,
		'oracles'   => $summary,
	)
) . "\n";
