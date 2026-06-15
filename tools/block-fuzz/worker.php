<?php
/**
 * Runs one block fuzz seed or input file.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

require_once __DIR__ . '/lib/autoload.php';

$options = BlockFuzz\parse_cli_options( $argv );

try {
	$max_input_bytes = BlockFuzz\option_int( $options, 'max-input-bytes', 4096 );
	$oracle_options  = array(
		'maxTokens'          => BlockFuzz\option_int( $options, 'max-tokens', 2048 ),
		'maxSerializedBytes' => BlockFuzz\option_int( $options, 'max-serialized-bytes', max( 65536, $max_input_bytes * 16 + 1024 ) ),
	);

	if ( BlockFuzz\option_bool( $options, 'fixtures', false ) ) {
		$fixtures_dir = BlockFuzz\option_string(
			$options,
			'fixtures-dir',
			BlockFuzz\repo_root() . '/tests/phpunit/data/blocks/fixtures'
		);
		$result       = BlockFuzz\Fuzzer::run_fixture_corpus( $fixtures_dir );
		echo BlockFuzz\json_encode_safe( $result ) . "\n";
		exit( $result['ok'] ? 0 : 1 );
	}

	$replay_path = BlockFuzz\option_string( $options, 'replay' );
	if ( null !== $replay_path ) {
		$replay   = BlockFuzz\read_json_file( $replay_path );
		$input    = base64_decode( $replay['inputBase64'], true );
		$metadata = $replay['metadata'] ?? array();
		if ( false === $input ) {
			throw new RuntimeException( 'Replay inputBase64 could not be decoded.' );
		}
	} else {
		$input_file = BlockFuzz\option_string( $options, 'input-file' );
		if ( null !== $input_file ) {
			$input = file_get_contents( $input_file );
			if ( false === $input ) {
				throw new RuntimeException( "Could not read {$input_file}." );
			}
			$metadata = array(
				'source' => 'input-file',
				'path'   => $input_file,
			);
		} else {
			$seed      = BlockFuzz\option_int( $options, 'seed', 1 );
			$generated = BlockFuzz\Generator::generate(
				$seed,
				array(
					'profile'  => BlockFuzz\option_string( $options, 'profile' ),
					'maxBytes' => $max_input_bytes,
				)
			);
			$input     = $generated['input'];
			$metadata  = $generated;
			unset( $metadata['input'] );
			$metadata['source'] = 'generated';
		}
	}

	if ( array_key_exists( 'expect-processor-agreement', $options ) ) {
		$metadata['expectProcessorAgreement'] = BlockFuzz\option_bool( $options, 'expect-processor-agreement' );
	}

	$output_dir = BlockFuzz\option_string( $options, 'output-dir' );
	if ( null !== $output_dir ) {
		BlockFuzz\ensure_dir( $output_dir );
		file_put_contents( $output_dir . '/input.bin', $input );
		BlockFuzz\write_json_file(
			$output_dir . '/replay.json',
			array(
				'createdAt'   => BlockFuzz\timestamp(),
				'metadata'    => $metadata,
				'inputBase64' => base64_encode( $input ),
			)
		);
	}

	$result = BlockFuzz\Fuzzer::run( $input, $metadata, $oracle_options );

	if ( null !== $output_dir ) {
		BlockFuzz\write_json_file( $output_dir . '/result.json', $result );
		BlockFuzz\write_json_file(
			$output_dir . '/replay.json',
			array(
				'createdAt'   => BlockFuzz\timestamp(),
				'metadata'    => $metadata,
				'inputBase64' => base64_encode( $input ),
				'result'      => $result,
			)
		);
	}

	echo BlockFuzz\json_encode_safe( $result ) . "\n";
	exit( $result['ok'] ? 0 : 1 );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, get_class( $throwable ) . ': ' . $throwable->getMessage() . "\n" );
	exit( 2 );
}
