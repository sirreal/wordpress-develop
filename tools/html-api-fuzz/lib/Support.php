<?php
namespace HtmlApiFuzz;

function repo_root(): string {
	return dirname( __DIR__, 3 );
}

function timestamp(): string {
	$now      = microtime( true );
	$seconds  = (int) $now;
	$fraction = max( 0, (int) round( ( $now - $seconds ) * 1000000 ) );
	if ( 1000000 === $fraction ) {
		++$seconds;
		$fraction = 0;
	}

	return gmdate( 'Ymd\THis', $seconds ) . sprintf( '%06dZ', $fraction );
}

function parse_cli_options( array $argv ): array {
	$options = array( '_' => array() );
	$count   = count( $argv );

	for ( $i = 1; $i < $count; ++$i ) {
		$arg = $argv[ $i ];
		if ( 0 !== strpos( $arg, '--' ) ) {
			$options['_'][] = $arg;
			continue;
		}

		$arg = substr( $arg, 2 );
		if ( false !== strpos( $arg, '=' ) ) {
			list( $name, $value ) = explode( '=', $arg, 2 );
			$options[ $name ]     = $value;
			continue;
		}

		if ( $i + 1 < $count && 0 !== strpos( $argv[ $i + 1 ], '--' ) ) {
			$options[ $arg ] = $argv[ ++$i ];
		} else {
			$options[ $arg ] = true;
		}
	}

	return $options;
}

function option_string( array $options, string $name, ?string $fallback = null ): ?string {
	return array_key_exists( $name, $options ) && true !== $options[ $name ] ? (string) $options[ $name ] : $fallback;
}

function option_bool( array $options, string $name, bool $fallback = false ): bool {
	if ( ! array_key_exists( $name, $options ) ) {
		return $fallback;
	}

	$value = $options[ $name ];
	if ( true === $value ) {
		return true;
	}

	return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
}

function option_int( array $options, string $name, int $fallback ): int {
	if ( ! array_key_exists( $name, $options ) || true === $options[ $name ] ) {
		return $fallback;
	}

	$value = filter_var( $options[ $name ], FILTER_VALIDATE_INT );
	if ( false === $value ) {
		throw new \InvalidArgumentException( "Expected --{$name} to be an integer." );
	}

	return (int) $value;
}

function option_float( array $options, string $name, float $fallback ): float {
	if ( ! array_key_exists( $name, $options ) || true === $options[ $name ] ) {
		return $fallback;
	}

	if ( ! is_numeric( $options[ $name ] ) ) {
		throw new \InvalidArgumentException( "Expected --{$name} to be numeric." );
	}

	return (float) $options[ $name ];
}

function ensure_dir( string $path ): void {
	if ( ! is_dir( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
		throw new \RuntimeException( "Could not create directory: {$path}" );
	}
}

function json_encode_safe( $value, int $flags = 0 ): string {
	$json = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | $flags );
	if ( false === $json ) {
		throw new \RuntimeException( 'JSON encode failed: ' . json_last_error_msg() );
	}

	return $json;
}

function write_json_file( string $path, $value ): void {
	ensure_dir( dirname( $path ) );
	file_put_contents( $path, json_encode_safe( $value ) . "\n" );
}

function read_json_file( string $path ) {
	$text = @file_get_contents( $path );
	if ( false === $text ) {
		return null;
	}

	$value = json_decode( $text, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		throw new \RuntimeException( "Could not parse JSON {$path}: " . json_last_error_msg() );
	}

	return $value;
}

function append_ndjson( string $path, $value ): void {
	ensure_dir( dirname( $path ) );
	file_put_contents( $path, json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n", FILE_APPEND );
}

function preview_bytes( string $bytes, int $limit = 240 ): string {
	$slice = substr( $bytes, 0, $limit );
	$shown = json_encode( $slice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $shown ) {
		$shown = base64_encode( $slice );
	}

	return strlen( $bytes ) > $limit ? $shown . '...' : $shown;
}

function command_string( array $command ): string {
	return implode( ' ', array_map( 'escapeshellarg', $command ) );
}

function run_php_process( array $script_args, string $cwd, int $timeout_ms, ?string $log_path = null ): array {
	$command = array_merge( array( PHP_BINARY ), $script_args );
	$spec    = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( $command, $spec, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		throw new \RuntimeException( 'Could not start PHP subprocess.' );
	}

	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stdout    = '';
	$stderr    = '';
	$start     = microtime( true );
	$timed_out = false;

	while ( true ) {
		$stdout .= stream_get_contents( $pipes[1] );
		$stderr .= stream_get_contents( $pipes[2] );

		$status = proc_get_status( $process );
		if ( ! $status['running'] ) {
			break;
		}

		if ( ( microtime( true ) - $start ) * 1000 > $timeout_ms ) {
			$timed_out = true;
			proc_terminate( $process );
			usleep( 200000 );
			$status = proc_get_status( $process );
			if ( $status['running'] ) {
				proc_terminate( $process, 9 );
			}
			break;
		}

		usleep( 10000 );
	}

	$stdout .= stream_get_contents( $pipes[1] );
	$stderr .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$exit_code = proc_close( $process );
	if ( $timed_out ) {
		$exit_code = null;
	}

	$output = $stdout . $stderr;
	if ( null !== $log_path ) {
		ensure_dir( dirname( $log_path ) );
		file_put_contents( $log_path, $output );
	}

	return array(
		'command'    => command_string( $command ),
		'code'       => $exit_code,
		'ok'         => 0 === $exit_code && ! $timed_out,
		'timedOut'   => $timed_out,
		'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		'stdout'     => $stdout,
		'stderr'     => $stderr,
		'output'     => $output,
		'logPath'    => $log_path,
	);
}

function read_ndjson_records( string $path ): array {
	$text = @file_get_contents( $path );
	if ( false === $text ) {
		return array();
	}

	$records = array();
	foreach ( explode( "\n", $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		$record = json_decode( $line, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			$records[] = $record;
		}
	}

	return $records;
}

function find_files_named( string $dir, string $filename, array $skip_dirs = array() ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$files = array();
	$items = scandir( $dir );
	if ( false === $items ) {
		return array();
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) ) {
			if ( in_array( $item, $skip_dirs, true ) ) {
				continue;
			}
			$files = array_merge( $files, find_files_named( $path, $filename, $skip_dirs ) );
		} elseif ( $item === $filename ) {
			$files[] = $path;
		}
	}

	return $files;
}
