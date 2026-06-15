<?php
/**
 * Runs block fuzz seeds in a bounded batch.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

require_once __DIR__ . '/lib/autoload.php';

$options = BlockFuzz\parse_cli_options( $argv );

try {
	$start_seed      = BlockFuzz\option_int( $options, 'start-seed', 1 );
	$max_seeds       = BlockFuzz\option_int( $options, 'max-seeds', 100 );
	$duration        = BlockFuzz\option_int( $options, 'duration-seconds', 0 );
	$max_input_bytes = BlockFuzz\option_int( $options, 'max-input-bytes', 4096 );
	$output_dir      = BlockFuzz\option_string(
		$options,
		'output-dir',
		BlockFuzz\repo_root() . '/artifacts/block-fuzz/run-' . BlockFuzz\timestamp()
	);
	$profile         = BlockFuzz\option_string( $options, 'profile' );
	$fail_fast       = BlockFuzz\option_bool( $options, 'fail-fast', false );

	$oracle_options = array(
		'maxTokens'          => BlockFuzz\option_int( $options, 'max-tokens', 2048 ),
		'maxSerializedBytes' => BlockFuzz\option_int( $options, 'max-serialized-bytes', max( 65536, $max_input_bytes * 16 + 1024 ) ),
	);

	BlockFuzz\ensure_dir( $output_dir );

	$started  = microtime( true );
	$attempts = 0;
	$failures = 0;
	$seed     = $start_seed;

	while ( true ) {
		if ( $max_seeds > 0 && $attempts >= $max_seeds ) {
			break;
		}

		if ( $duration > 0 && microtime( true ) - $started >= $duration ) {
			break;
		}

		$generated = BlockFuzz\Generator::generate(
			$seed,
			array(
				'profile'  => $profile,
				'maxBytes' => $max_input_bytes,
			)
		);
		$input     = $generated['input'];
		$metadata  = $generated;
		unset( $metadata['input'] );
		$metadata['source'] = 'generated';

		if ( array_key_exists( 'expect-processor-agreement', $options ) ) {
			$metadata['expectProcessorAgreement'] = BlockFuzz\option_bool( $options, 'expect-processor-agreement' );
		}

		$seed_dir = $output_dir . '/seed-' . $seed;
		BlockFuzz\ensure_dir( $seed_dir );
		file_put_contents( $seed_dir . '/input.bin', $input );
		BlockFuzz\write_json_file(
			$seed_dir . '/replay.json',
			array(
				'createdAt'   => BlockFuzz\timestamp(),
				'metadata'    => $metadata,
				'inputBase64' => base64_encode( $input ),
			)
		);

		$result = BlockFuzz\Fuzzer::run( $input, $metadata, $oracle_options );
		++$attempts;

		BlockFuzz\append_ndjson(
			$output_dir . '/summary.ndjson',
			array(
				'seed'      => $seed,
				'profile'   => $metadata['profile'],
				'ok'        => $result['ok'],
				'status'    => $result['status'],
				'signature' => $result['signature'] ?? null,
				'summary'   => $result['summary'],
			)
		);

		if ( ! $result['ok'] ) {
			++$failures;
			BlockFuzz\write_json_file( $seed_dir . '/result.json', $result );
			BlockFuzz\write_json_file(
				$seed_dir . '/replay.json',
				array(
					'createdAt'   => BlockFuzz\timestamp(),
					'metadata'    => $metadata,
					'inputBase64' => base64_encode( $input ),
					'result'      => $result,
				)
			);

			if ( $fail_fast ) {
				break;
			}
		} else {
			BlockFuzz\remove_dir_recursive( $seed_dir );
		}

		++$seed;
	}

	$summary = array(
		'ok'         => 0 === $failures,
		'status'     => 0 === $failures ? 'pass' : 'fail',
		'outputDir'  => $output_dir,
		'attempts'   => $attempts,
		'failures'   => $failures,
		'durationMs' => (int) round( ( microtime( true ) - $started ) * 1000 ),
	);

	BlockFuzz\write_json_file( $output_dir . '/run.json', $summary );
	echo BlockFuzz\json_encode_safe( $summary ) . "\n";
	exit( $summary['ok'] ? 0 : 1 );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, get_class( $throwable ) . ': ' . $throwable->getMessage() . "\n" );
	exit( 2 );
}
