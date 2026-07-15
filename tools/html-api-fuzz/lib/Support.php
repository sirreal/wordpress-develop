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

function remove_dir_recursive( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}

	$items = scandir( $path );
	if ( false !== $items ) {
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			remove_dir_recursive( $path . DIRECTORY_SEPARATOR . $item );
		}
	}
	@rmdir( $path );
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
	$contents = json_encode_safe( $value ) . "\n";
	$written  = file_put_contents( $path, $contents );
	if ( strlen( $contents ) !== $written ) {
		throw new \RuntimeException( "Could not write complete JSON file: {$path}" );
	}
}

/** Publish a JSON snapshot without ever exposing a truncated destination. */
function write_json_file_atomic( string $path, $value ): void {
	ensure_dir( dirname( $path ) );
	$tmp = dirname( $path ) . DIRECTORY_SEPARATOR . '.' . basename( $path ) . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
	try {
		write_json_file( $tmp, $value );
		if ( ! rename( $tmp, $path ) ) {
			throw new \RuntimeException( "Could not atomically publish JSON file: {$path}" );
		}
	} finally {
		if ( is_file( $tmp ) ) {
			@unlink( $tmp );
		}
	}
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
	$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $json ) {
		throw new \RuntimeException( 'JSON encode failed: ' . json_last_error_msg() );
	}
	$contents = $json . "\n";
	$written  = file_put_contents( $path, $contents, FILE_APPEND | LOCK_EX );
	if ( strlen( $contents ) !== $written ) {
		throw new \RuntimeException( "Could not append complete NDJSON record: {$path}" );
	}
}

function preview_bytes( string $bytes, int $limit = 240 ): string {
	$slice = substr( $bytes, 0, $limit );
	$shown = json_encode( $slice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $shown ) {
		$shown = base64_encode( $slice );
	}

	return strlen( $bytes ) > $limit ? $shown . '...' : $shown;
}

function normalize_payload_policy_label( ?string $payload_policy ): ?string {
	if ( null === $payload_policy ) {
		return null;
	}

	return in_array( $payload_policy, Generator::payload_policy_labels(), true ) ? $payload_policy : null;
}

function command_string( array $command ): string {
	return implode( ' ', array_map( 'escapeshellarg', $command ) );
}

function run_git_command( array $args, int $timeout_ms = 1000, ?string $root = null ): array {
	$root    = $root ?? repo_root();
	$command = array_merge( array( 'git', '-C', $root ), $args );
	$spec    = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = @proc_open( $command, $spec, $pipes, $root );
	if ( ! is_resource( $process ) ) {
		return array(
			'code'     => null,
			'timedOut' => false,
			'stdout'   => '',
			'stderr'   => '',
		);
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

	return array(
		'code'     => $exit_code,
		'timedOut' => $timed_out,
		'stdout'   => $stdout,
		'stderr'   => $stderr,
	);
}

function git_command_output( array $args, int $timeout_ms = 1000, ?string $root = null ): ?string {
	$result = run_git_command( $args, $timeout_ms, $root );
	if ( 0 !== $result['code'] ) {
		return null;
	}

	return trim( $result['stdout'] );
}

function unavailable_git_metadata(): array {
	return array(
		'available'  => false,
		'commit'     => null,
		'short'      => null,
		'branch'     => null,
		'commitDate' => null,
		'dirty'      => null,
	);
}

function normalize_git_metadata( $metadata ): array {
	if ( ! is_array( $metadata ) || ! ( $metadata['available'] ?? false ) || ! is_string( $metadata['commit'] ?? null ) ) {
		return unavailable_git_metadata();
	}

	return array(
		'available'  => true,
		'commit'     => is_string( $metadata['commit'] ?? null ) ? $metadata['commit'] : null,
		'short'      => is_string( $metadata['short'] ?? null ) ? $metadata['short'] : null,
		'branch'     => is_string( $metadata['branch'] ?? null ) ? $metadata['branch'] : null,
		'commitDate' => is_string( $metadata['commitDate'] ?? null ) ? $metadata['commitDate'] : null,
		'dirty'      => is_bool( $metadata['dirty'] ?? null ) ? $metadata['dirty'] : null,
	);
}

function git_metadata_base64( array $metadata ): string {
	$json = json_encode( normalize_git_metadata( $metadata ), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $json ) {
		throw new \RuntimeException( 'JSON encode failed: ' . json_last_error_msg() );
	}

	return base64_encode( $json );
}

function git_metadata_from_base64( string $encoded ): array {
	$json = base64_decode( $encoded, true );
	if ( false === $json ) {
		throw new \InvalidArgumentException( 'Invalid --git-metadata-base64.' );
	}

	$metadata = json_decode( $json, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		throw new \InvalidArgumentException( 'Invalid --git-metadata-base64 JSON: ' . json_last_error_msg() );
	}

	return normalize_git_metadata( $metadata );
}

function replay_source_metadata( string $replay_path, array $replay ): array {
	$source = array(
		'path'          => $replay_path,
		'createdAt'     => $replay['createdAt'] ?? null,
		'repoRoot'      => $replay['repoRoot'] ?? null,
		'repoCommit'    => $replay['repoCommit'] ?? null,
		'repoDirty'     => $replay['repoDirty'] ?? null,
		'signatureHash' => $replay['signature']['hash'] ?? $replay['result']['signature']['hash'] ?? null,
	);

	if ( is_array( $replay['sourceReplay'] ?? null ) ) {
		$source['sourceReplay'] = $replay['sourceReplay'];
	}

	return $source;
}

function git_metadata( int $timeout_ms = 1000, ?string $root = null, bool $use_cache = true ): array {
	static $cache = array();

	$root      = $root ?? repo_root();
	$real_root = realpath( $root );
	$cache_key = ( false === $real_root ? $root : $real_root ) . ':' . $timeout_ms;

	if ( $use_cache && array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}

	$top_level = git_command_output( array( 'rev-parse', '--show-toplevel' ), $timeout_ms, $root );
	if ( null === $top_level || '' === $top_level || false === $real_root || realpath( $top_level ) !== $real_root ) {
		$metadata = unavailable_git_metadata();
		if ( $use_cache ) {
			$cache[ $cache_key ] = $metadata;
		}
		return $metadata;
	}

	$commit = git_command_output( array( 'rev-parse', 'HEAD' ), $timeout_ms, $root );
	if ( null === $commit || '' === $commit ) {
		$metadata = unavailable_git_metadata();
		if ( $use_cache ) {
			$cache[ $cache_key ] = $metadata;
		}
		return $metadata;
	}

	$branch = git_command_output( array( 'branch', '--show-current' ), $timeout_ms, $root );
	if ( '' === $branch ) {
		$branch = null;
	}

	$dirty_result = run_git_command( array( 'status', '--porcelain=v1', '--untracked-files=normal' ), $timeout_ms, $root );
	$dirty        = 0 === $dirty_result['code'] ? '' !== trim( $dirty_result['stdout'] ) : null;

	$metadata = array(
		'available'  => true,
		'commit'     => $commit,
		'short'      => git_command_output( array( 'rev-parse', '--short=12', 'HEAD' ), $timeout_ms, $root ),
		'branch'     => $branch,
		'commitDate' => git_command_output( array( 'show', '-s', '--format=%cI', 'HEAD' ), $timeout_ms, $root ),
		'dirty'      => $dirty,
	);

	if ( $use_cache ) {
		$cache[ $cache_key ] = $metadata;
	}

	return $metadata;
}

function run_php_process( array $script_args, string $cwd, int $timeout_ms, ?string $log_path = null, int $max_capture_bytes = 1048576, bool $isolate_process_group = false ): array {
	if ( $max_capture_bytes < 1 ) {
		throw new \InvalidArgumentException( 'Process capture limit must be positive.' );
	}
	$target_command = array_merge( array( PHP_BINARY ), $script_args );
	$command        = $target_command;
	if ( $isolate_process_group ) {
		if ( ! function_exists( 'posix_kill' ) ) {
			throw new \RuntimeException( 'Process-group isolation requires the POSIX PHP extension.' );
		}
		$command = array_merge(
			array( PHP_BINARY, dirname( __DIR__ ) . '/process-group.php', PHP_BINARY ),
			$script_args
		);
	}
	$spec    = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$log = null;
	if ( null !== $log_path ) {
		ensure_dir( dirname( $log_path ) );
		$log = fopen( $log_path, 'wb' );
		if ( false === $log ) {
			throw new \RuntimeException( "Could not open process log: {$log_path}" );
		}
	}

	$process = proc_open( $command, $spec, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		if ( is_resource( $log ) ) {
			fclose( $log );
		}
		throw new \RuntimeException( 'Could not start PHP subprocess.' );
	}

	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stdout    = '';
	$stderr    = '';
	$stdout_truncated = false;
	$stderr_truncated = false;
	$start     = microtime( true );
	$timed_out = false;
	$initial_status = proc_get_status( $process );
	$process_pid    = (int) ( $initial_status['pid'] ?? 0 );
	$process_group  = false;
	$log_write_failed = false;

	$drain = static function ( $pipe, string &$capture, bool &$truncated ) use ( $max_capture_bytes, $log, &$log_write_failed ): void {
		$chunk = stream_get_contents( $pipe );
		if ( false === $chunk || '' === $chunk ) {
			return;
		}
		if ( is_resource( $log ) && strlen( $chunk ) !== fwrite( $log, $chunk ) ) {
			$log_write_failed = true;
		}
		$capture .= $chunk;
		if ( strlen( $capture ) > $max_capture_bytes ) {
			$capture   = substr( $capture, -$max_capture_bytes );
			$truncated = true;
		}
	};

	while ( true ) {
		$drain( $pipes[1], $stdout, $stdout_truncated );
		$drain( $pipes[2], $stderr, $stderr_truncated );

		$status = proc_get_status( $process );
		if ( $isolate_process_group && $process_pid > 0 && function_exists( 'posix_getpgid' ) ) {
			$process_group = $process_pid === @posix_getpgid( $process_pid );
		}
		if ( ! $status['running'] ) {
			break;
		}

		if ( ( microtime( true ) - $start ) * 1000 > $timeout_ms ) {
			$timed_out = true;
			if ( $process_group ) {
				@posix_kill( -$process_pid, 15 );
			} else {
				proc_terminate( $process );
			}
			usleep( 200000 );
			$status = proc_get_status( $process );
			if ( $process_group ) {
				// Kill the group even if its leader exited after SIGTERM; a child
				// could otherwise outlive the supervised worker.
				@posix_kill( -$process_pid, 9 );
			} elseif ( $status['running'] ) {
					proc_terminate( $process, 9 );
			}
			break;
		}

		usleep( 10000 );
	}

	$drain( $pipes[1], $stdout, $stdout_truncated );
	$drain( $pipes[2], $stderr, $stderr_truncated );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	if ( is_resource( $log ) ) {
		fflush( $log );
		fclose( $log );
	}

	$exit_code = proc_close( $process );
	if ( $timed_out ) {
		$exit_code = null;
	}

	$output = $stdout . $stderr;
	if ( $log_write_failed ) {
		throw new \RuntimeException( 'Could not write complete process log.' );
	}
	return array(
		'command'    => command_string( $target_command ),
		'code'       => $exit_code,
		'ok'         => 0 === $exit_code && ! $timed_out,
		'timedOut'   => $timed_out,
		'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		'stdout'     => $stdout,
		'stderr'     => $stderr,
		'stdoutTruncated' => $stdout_truncated,
		'stderrTruncated' => $stderr_truncated,
		'output'     => $output,
		'logPath'    => $log_path,
		'processGroupIsolated' => $process_group,
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
