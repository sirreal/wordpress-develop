<?php
/**
 * Replays a retained block fuzz failure.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

require_once __DIR__ . '/lib/autoload.php';

$options = BlockFuzz\parse_cli_options( $argv );

try {
	$replay_path = BlockFuzz\option_string( $options, 'replay' );
	if ( null === $replay_path ) {
		throw new InvalidArgumentException( 'Usage: php tools/block-fuzz/replay.php --replay PATH' );
	}

	$replay = BlockFuzz\read_json_file( $replay_path );
	$input  = base64_decode( $replay['inputBase64'], true );
	if ( false === $input ) {
		throw new RuntimeException( 'Replay inputBase64 could not be decoded.' );
	}

	$result = BlockFuzz\Fuzzer::run(
		$input,
		$replay['metadata'] ?? array(),
		array(
			'maxTokens'          => BlockFuzz\option_int( $options, 'max-tokens', 2048 ),
			'maxSerializedBytes' => BlockFuzz\option_int( $options, 'max-serialized-bytes', max( 65536, strlen( $input ) * 16 + 1024 ) ),
		)
	);

	echo BlockFuzz\json_encode_safe( $result ) . "\n";
	exit( $result['ok'] ? 0 : 1 );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, get_class( $throwable ) . ': ' . $throwable->getMessage() . "\n" );
	exit( 2 );
}
