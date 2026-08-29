#!/usr/bin/env php
<?php

namespace HtmlApiFuzz\OracleProcessSupervisor;

const SCHEMA_VERSION = 1;
const OWNER_FILE = 'owner-token';
const STATE_FILE = 'state.json';
const STATE_TEMP = '.state.tmp';
const CONTROL_MAX_BYTES = 16384;
const TERM_GRACE_MICROSECONDS = 250000;
const KILL_GRACE_MICROSECONDS = 2000000;

$ownership_identities = array();
$process_inspection_error = null;
$process_inspection_retry_pid = null;

function fail( string $message ): void {
	throw new \RuntimeException( $message );
}

function exact_keys( array $value, array $keys ): bool {
	$actual = array_keys( $value );
	sort( $actual );
	sort( $keys );
	return $actual === $keys;
}

function is_absolute_path( string $path ): bool {
	return '' !== $path && DIRECTORY_SEPARATOR === $path[0];
}

function read_exact_file( string $path, int $maximum ): string {
	$handle = @fopen( $path, 'rb' );
	if ( false === $handle ) {
		fail( 'Could not open required ownership file.' );
	}
	try {
		$contents = '';
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 65536, $maximum + 1 - strlen( $contents ) ) );
			if ( false === $chunk ) {
				fail( 'Could not read required ownership file.' );
			}
			$contents .= $chunk;
			if ( strlen( $contents ) > $maximum ) {
				fail( 'Required ownership file exceeded its byte limit.' );
			}
		}
		return $contents;
	} finally {
		fclose( $handle );
	}
}

function identity_from_stat( array $stat ): array {
	return array( 'dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'] );
}

function ownership_identity( string $root ): ?array {
	$root_stat = @lstat( $root );
	$owner_stat = @lstat( $root . DIRECTORY_SEPARATOR . OWNER_FILE );
	if ( false === $root_stat || false === $owner_stat ) {
		return null;
	}
	return array( 'root' => identity_from_stat( $root_stat ), 'owner' => identity_from_stat( $owner_stat ) );
}

function valid_inode_identity( $identity ): bool {
	return is_array( $identity ) && exact_keys( $identity, array( 'dev', 'ino' ) ) &&
		is_int( $identity['dev'] ) && $identity['dev'] > 0 && is_int( $identity['ino'] ) && $identity['ino'] > 0;
}

function register_ownership_identity( string $root, array $root_identity, array $owner_identity ): void {
	global $ownership_identities;
	if ( ! valid_inode_identity( $root_identity ) || ! valid_inode_identity( $owner_identity ) ) {
		fail( 'Oracle ownership inode identity is invalid.' );
	}
	$ownership_identities[ $root ] = array( 'root' => $root_identity, 'owner' => $owner_identity );
}

function stat_matches_identity( $stat, array $identity ): bool {
	return is_array( $stat ) && (int) $stat['dev'] === $identity['dev'] && (int) $stat['ino'] === $identity['ino'];
}

function owner_error( string $root, string $token ): ?string {
	global $ownership_identities;
	$expected = $ownership_identities[ $root ] ?? null;
	if ( ! is_array( $expected ) ) {
		return 'Oracle ownership inode identity was not registered.';
	}
	$stat = @lstat( $root );
	if (
		false === $stat ||
		! stat_matches_identity( $stat, $expected['root'] ) ||
		( $stat['mode'] & 0170000 ) !== 0040000 ||
		( $stat['mode'] & 0777 ) !== 0700 ||
		( function_exists( 'posix_geteuid' ) && $stat['uid'] !== posix_geteuid() )
	) {
		return 'Oracle ownership root identity is invalid.';
	}
	$marker_path = $root . DIRECTORY_SEPARATOR . OWNER_FILE;
	$marker_stat = @lstat( $marker_path );
	if (
		false === $marker_stat ||
		! stat_matches_identity( $marker_stat, $expected['owner'] ) ||
		( $marker_stat['mode'] & 0170000 ) !== 0100000 ||
		( $marker_stat['mode'] & 0777 ) !== 0600 ||
		( function_exists( 'posix_geteuid' ) && $marker_stat['uid'] !== posix_geteuid() )
	) {
		return 'Oracle ownership marker identity is invalid.';
	}
	try {
		$recorded = read_exact_file( $marker_path, 256 );
	} catch ( \Throwable $error ) {
		return $error->getMessage();
	}
	$root_after = @lstat( $root );
	$marker_after = @lstat( $marker_path );
	if ( ! stat_matches_identity( $root_after, $expected['root'] ) || ! stat_matches_identity( $marker_after, $expected['owner'] ) ) {
		return 'Oracle ownership inode identity changed during authentication.';
	}
	return hash_equals( $token . "\n", $recorded ) ? null : 'Oracle ownership token does not match.';
}

function run_ps( array $arguments, int $timeout_ms = 1000 ): array {
	$command = array_merge( array( '/bin/ps' ), $arguments );
	$spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$environment = array_merge( $_ENV, array( 'LC_ALL' => 'C', 'LANG' => 'C' ) );
	$process = @proc_open( $command, $spec, $pipes, null, $environment );
	if ( ! is_resource( $process ) ) {
		return array( 'code' => null, 'stdout' => '', 'stderr' => 'Could not start ps.' );
	}
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	$stdout = '';
	$stderr = '';
	$overflow = false;
	$deadline = microtime( true ) + ( $timeout_ms / 1000 );
	$status = proc_get_status( $process );
	$observer_pid = (int) ( $status['pid'] ?? 0 );
	while ( $status['running'] && microtime( true ) < $deadline ) {
		$stdout_chunk = (string) stream_get_contents( $pipes[1] );
		$stderr_chunk = (string) stream_get_contents( $pipes[2] );
		if ( strlen( $stdout ) + strlen( $stdout_chunk ) > 1048576 || strlen( $stderr ) + strlen( $stderr_chunk ) > 65536 ) {
			$overflow = true;
		} else {
			$stdout .= $stdout_chunk;
			$stderr .= $stderr_chunk;
		}
		usleep( 10000 );
		$status = proc_get_status( $process );
	}
	$timed_out = $status['running'];
	if ( $timed_out ) {
		proc_terminate( $process, 9 );
		$kill_deadline = microtime( true ) + 1;
		do {
			usleep( 10000 );
			$status = proc_get_status( $process );
		} while ( $status['running'] && microtime( true ) < $kill_deadline );
	}
	$stdout_chunk = (string) stream_get_contents( $pipes[1] );
	$stderr_chunk = (string) stream_get_contents( $pipes[2] );
	if ( strlen( $stdout ) + strlen( $stdout_chunk ) > 1048576 || strlen( $stderr ) + strlen( $stderr_chunk ) > 65536 ) {
		$overflow = true;
	} else {
		$stdout .= $stdout_chunk;
		$stderr .= $stderr_chunk;
	}
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$observed_code = ! $status['running'] && isset( $status['exitcode'] ) && $status['exitcode'] >= 0 ? (int) $status['exitcode'] : null;
	$closed_code   = proc_close( $process );
	$code          = null !== $observed_code ? $observed_code : ( $closed_code >= 0 ? $closed_code : null );
	return array( 'code' => $timed_out || $overflow ? null : $code, 'stdout' => $stdout, 'stderr' => $stderr, 'overflow' => $overflow, 'observerPid' => $observer_pid );
}

function parse_linux_stat_identity( string $stat_text, int $pid ): ?array {
	$close = strrpos( $stat_text, ')' );
	if ( false === $close ) {
		return null;
	}
	$fields = preg_split( '/\s+/', trim( substr( $stat_text, $close + 1 ) ) );
	// Fields after comm begin at kernel stat field 3. pgrp=5, session=6,
	// starttime=22, so their zero-based offsets here are 2, 3, and 19.
	if ( ! is_array( $fields ) || count( $fields ) <= 19 ) {
		return null;
	}
	return array(
		'pid'       => $pid,
		'pgid'      => (int) $fields[2],
		'sid'       => (int) $fields[3],
		'birth'     => (string) $fields[19],
	);
}

function linux_process_identity( int $pid ): ?array {
	$stat_before = @file_get_contents( '/proc/' . $pid . '/stat' );
	$cmdline = @file_get_contents( '/proc/' . $pid . '/cmdline' );
	$stat_after = @file_get_contents( '/proc/' . $pid . '/stat' );
	if ( ! is_string( $stat_before ) || ! is_string( $cmdline ) || ! is_string( $stat_after ) ) {
		return null;
	}
	$before = parse_linux_stat_identity( $stat_before, $pid );
	$after = parse_linux_stat_identity( $stat_after, $pid );
	if ( null === $before || $before !== $after ) {
		return null;
	}
	$before['arguments'] = array_values( array_filter( explode( "\0", $cmdline ), 'strlen' ) );
	return $before;
}

function darwin_process_identity( int $pid ): ?array {
	$pgid_before = @posix_getpgid( $pid );
	$sid_before  = @posix_getsid( $pid );
	if ( false === $pgid_before || false === $sid_before ) {
		return null;
	}
	$checked_before = run_ps( array( '-ww', '-p', (string) $pid, '-o', 'lstart=', '-o', 'command=' ) );
	$checked_after = run_ps( array( '-ww', '-p', (string) $pid, '-o', 'lstart=', '-o', 'command=' ) );
	$pgid_after = @posix_getpgid( $pid );
	$sid_after  = @posix_getsid( $pid );
	if (
		0 !== $checked_before['code'] ||
		0 !== $checked_after['code'] ||
		! hash_equals( $checked_before['stdout'], $checked_after['stdout'] ) ||
		$pgid_before !== $pgid_after ||
		$sid_before !== $sid_after
	) {
		return null;
	}
	$line = rtrim( $checked_before['stdout'], "\r\n" );
	if ( strlen( $line ) < 25 ) {
		return null;
	}
	$birth = trim( substr( $line, 0, 24 ) );
	$command = trim( substr( $line, 24 ) );
	if ( '' === $birth || '' === $command ) {
		return null;
	}
	return array(
		'pid'       => $pid,
		'pgid'      => (int) $pgid_before,
		'sid'       => (int) $sid_before,
		'birth'     => $birth,
		'arguments' => array( $command ),
	);
}

function process_identity( int $pid ): ?array {
	if ( $pid < 2 ) {
		return null;
	}
	if ( 'Linux' === PHP_OS_FAMILY ) {
		return linux_process_identity( $pid );
	}
	if ( 'Darwin' === PHP_OS_FAMILY ) {
		return darwin_process_identity( $pid );
	}
	return null;
}

function identity_command_contains( array $identity, array $needles ): bool {
	$haystack = implode( "\0", array_map( 'strval', $identity['arguments'] ?? array() ) );
	foreach ( $needles as $needle ) {
		if ( '' === $needle || false === strpos( $haystack, $needle ) ) {
			return false;
		}
	}
	return true;
}

function identity_document( array $identity ): array {
	return array(
		'pid'   => (int) $identity['pid'],
		'pgid'  => (int) $identity['pgid'],
		'sid'   => (int) $identity['sid'],
		'birth' => (string) $identity['birth'],
	);
}

function validate_child_identity_line( $line, int $pid, int $sid, int $pgid, string $supervisor_path, string $root, string $token ): ?array {
	if ( ! is_string( $line ) || ! str_ends_with( $line, "\n" ) ) {
		return null;
	}
	$document = json_decode( substr( $line, 0, -1 ), true );
	if (
		! is_array( $document ) ||
		! exact_keys( $document, array( 'pid', 'pgid', 'sid', 'birth' ) ) ||
		! is_int( $document['pid'] ) ||
		! is_int( $document['pgid'] ) ||
		! is_int( $document['sid'] ) ||
		! is_string( $document['birth'] ) ||
		'' === $document['birth'] ||
		$pid !== $document['pid'] ||
		$pgid !== $document['pgid'] ||
		$sid !== $document['sid'] ||
		! hash_equals( json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n", $line )
	) {
		return null;
	}
	$current = process_identity( $pid );
	return same_identity( $document, $current ) && identity_command_contains( $current, array( $supervisor_path, $root, $token ) ) ? $document : null;
}

function same_identity( array $recorded, ?array $current ): bool {
	return null !== $current &&
		(int) ( $recorded['pid'] ?? 0 ) === $current['pid'] &&
		(int) ( $recorded['pgid'] ?? 0 ) === $current['pgid'] &&
		(int) ( $recorded['sid'] ?? 0 ) === $current['sid'] &&
		(string) ( $recorded['birth'] ?? '' ) === $current['birth'];
}

/**
 * Take one complete, bounded process-table snapshot.
 *
 * A null result is inspection uncertainty, never an empty process table.
 */
function process_table_snapshot(): ?array {
	$checked = run_ps( array( '-A', '-o', 'pid=', '-o', 'pgid=' ) );
	if ( 0 !== $checked['code'] || '' !== trim( $checked['stderr'] ) ) {
		return null;
	}
	$rows = array();
	$observer_seen = false;
	foreach ( preg_split( '/\r?\n/', trim( $checked['stdout'] ) ) ?: array() as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		if ( 1 !== preg_match( '/^\s*([0-9]+)\s+([0-9]+)\s*$/D', $line, $matches ) ) {
			return null;
		}
		$pid  = (int) $matches[1];
		$pgid = (int) $matches[2];
		if ( $pid < 1 || isset( $rows[ $pid ] ) ) {
			return null;
		}
		if ( 0 === $pgid ) {
			continue;
		}
		if ( $pid === $checked['observerPid'] ) {
			$observer_seen = true;
		}
		$rows[ $pid ] = array( 'pid' => $pid, 'pgid' => $pgid );
	}
	return $observer_seen ? $rows : null;
}

function process_is_proven_absent( int $pid ): bool {
	if ( @posix_kill( $pid, 0 ) ) {
		return false;
	}
	return 3 === posix_get_last_error();
}

function process_inspection_failure( string $message ): ?array {
	global $process_inspection_error;
	$process_inspection_error = $message;
	return null;
}

function last_process_inspection_error(): ?string {
	global $process_inspection_error;
	return $process_inspection_error;
}

function mark_process_inspection_retry( int $pid, string $message ): ?array {
	global $process_inspection_retry_pid;
	$process_inspection_retry_pid = $pid;
	return process_inspection_failure( $message );
}

function process_inspection_retry_pid(): ?int {
	global $process_inspection_retry_pid;
	return $process_inspection_retry_pid;
}

function session_group_members( int $sid, int $pgid ): ?array {
	global $process_inspection_retry_pid;
	$process_inspection_retry_pid = null;
	$snapshot = process_table_snapshot();
	if ( null === $snapshot ) {
		return process_inspection_failure( 'bounded process-table snapshot failed' );
	}
	$members = array();
	foreach ( $snapshot as $row ) {
		if ( $pgid !== $row['pgid'] ) {
			continue;
		}
		$current_pgid = @posix_getpgid( $row['pid'] );
		if ( false === $current_pgid ) {
			if ( 3 === posix_get_last_error() ) {
				if ( process_is_proven_absent( $row['pid'] ) ) {
					continue;
				}
				return mark_process_inspection_retry( $row['pid'], 'getpgid transiently failed for PID ' . $row['pid'] . ' with errno 3' );
			}
			return process_inspection_failure( 'getpgid failed for PID ' . $row['pid'] . ' with errno ' . posix_get_last_error() );
		}
		$current_sid = @posix_getsid( $row['pid'] );
		if ( false === $current_sid ) {
			if ( 3 === posix_get_last_error() ) {
				if ( process_is_proven_absent( $row['pid'] ) ) {
					continue;
				}
				return mark_process_inspection_retry( $row['pid'], 'getsid transiently failed for PID ' . $row['pid'] . ' with errno 3' );
			}
			return process_inspection_failure( 'getsid failed for PID ' . $row['pid'] . ' with errno ' . posix_get_last_error() );
		}
		if ( $current_pgid !== $row['pgid'] ) {
			return process_inspection_failure( 'PID ' . $row['pid'] . ' changed process group during inspection' );
		}
		if ( $sid === $current_sid ) {
			$members[] = $row['pid'];
		}
	}
	sort( $members );
	return $members;
}

function session_members( int $sid ): ?array {
	$snapshot = process_table_snapshot();
	if ( null === $snapshot ) {
		return process_inspection_failure( 'bounded process-table snapshot failed' );
	}
	$members = array();
	foreach ( $snapshot as $row ) {
		$current_pgid = @posix_getpgid( $row['pid'] );
		if ( false === $current_pgid ) {
			if ( 3 === posix_get_last_error() && process_is_proven_absent( $row['pid'] ) ) {
				continue;
			}
			return process_inspection_failure( 'getpgid failed for PID ' . $row['pid'] . ' with errno ' . posix_get_last_error() );
		}
		$current_sid = @posix_getsid( $row['pid'] );
		if ( false === $current_sid ) {
			if ( 3 === posix_get_last_error() && process_is_proven_absent( $row['pid'] ) ) {
				continue;
			}
			return process_inspection_failure( 'getsid failed for PID ' . $row['pid'] . ' with errno ' . posix_get_last_error() );
		}
		if ( $current_pgid !== $row['pgid'] ) {
			return process_inspection_failure( 'PID ' . $row['pid'] . ' changed process group during inspection' );
		}
		if ( $sid === $current_sid ) {
			$members[] = $row['pid'];
		}
	}
	sort( $members );
	return $members;
}

function atomic_state( string $root, array $state ): void {
	if ( ! valid_state_schema( $state ) ) {
		fail( 'Refusing to publish invalid oracle supervisor state.' );
	}
	$error = owner_error( $root, (string) ( $state['token'] ?? '' ) );
	if ( null !== $error ) {
		fail( $error );
	}
	$json = json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	$temp = $root . DIRECTORY_SEPARATOR . STATE_TEMP . '-' . getmypid();
	$handle = @fopen( $temp, 'xb' );
	if ( false === $handle ) {
		fail( 'Could not create oracle supervisor state temporary file.' );
	}
	try {
		if ( strlen( $json ) !== fwrite( $handle, $json ) || ! fflush( $handle ) ) {
			fail( 'Could not write complete oracle supervisor state.' );
		}
		if ( function_exists( 'fsync' ) && ! fsync( $handle ) ) {
			fail( 'Could not sync oracle supervisor state.' );
		}
	} finally {
		fclose( $handle );
	}
	chmod( $temp, 0600 );
	if ( null !== owner_error( $root, (string) $state['token'] ) ) {
		@unlink( $temp );
		fail( 'Oracle ownership identity changed before state publication.' );
	}
	if ( ! rename( $temp, $root . DIRECTORY_SEPARATOR . STATE_FILE ) ) {
		@unlink( $temp );
		fail( 'Could not publish oracle supervisor state.' );
	}
	if ( null !== owner_error( $root, (string) $state['token'] ) ) {
		fail( 'Oracle ownership identity changed during state publication.' );
	}
}

function valid_process_document( $document ): bool {
	return is_array( $document ) && exact_keys( $document, array( 'pid', 'pgid', 'sid', 'birth' ) ) &&
		is_int( $document['pid'] ) && $document['pid'] > 1 &&
		is_int( $document['pgid'] ) && $document['pgid'] > 1 &&
		is_int( $document['sid'] ) && $document['sid'] > 1 &&
		is_string( $document['birth'] ) && '' !== $document['birth'];
}

function valid_state_schema( $state ): bool {
	if (
		! is_array( $state ) ||
		! exact_keys( $state, array( 'schemaVersion', 'token', 'root', 'rootIdentity', 'ownerIdentity', 'phase', 'supervisorPath', 'supervisorSha256', 'targetPath', 'targetSha256', 'supervisor', 'anchor', 'target', 'cleanupError' ) ) ||
		1 !== $state['schemaVersion'] ||
		! is_string( $state['token'] ) ||
		! is_string( $state['root'] ) ||
		! valid_inode_identity( $state['rootIdentity'] ) ||
		! valid_inode_identity( $state['ownerIdentity'] ) ||
		! in_array( $state['phase'], array( 'supervisor-ready', 'anchor-ready', 'gated', 'running', 'target-exit', 'cleaning', 'cleanup-failed', 'cleaned' ), true ) ||
		! is_string( $state['supervisorPath'] ) ||
		! is_string( $state['targetPath'] ) ||
		1 !== preg_match( '/^[0-9a-f]{64}$/', $state['supervisorSha256'] ) ||
		1 !== preg_match( '/^[0-9a-f]{64}$/', $state['targetSha256'] ) ||
		! valid_process_document( $state['supervisor'] ) ||
		( null !== $state['anchor'] && ! valid_process_document( $state['anchor'] ) ) ||
		( null !== $state['target'] && ! valid_process_document( $state['target'] ) ) ||
		( null !== $state['cleanupError'] && ! is_string( $state['cleanupError'] ) )
	) {
		return false;
	}
	$supervisor = $state['supervisor'];
	$anchor = $state['anchor'];
	$target = $state['target'];
	if ( $supervisor['pid'] !== $supervisor['sid'] ) {
		return false;
	}
	if ( null !== $anchor && ( $anchor['pid'] !== $anchor['pgid'] || $anchor['sid'] !== $supervisor['sid'] || $anchor['pid'] === $supervisor['pid'] ) ) {
		return false;
	}
	if ( null !== $target && ( null === $anchor || $target['sid'] !== $anchor['sid'] || $target['pgid'] !== $anchor['pgid'] || in_array( $target['pid'], array( $supervisor['pid'], $anchor['pid'] ), true ) ) ) {
		return false;
	}
	$phase = $state['phase'];
	if ( ( 'cleanup-failed' === $phase ) !== ( is_string( $state['cleanupError'] ) && '' !== $state['cleanupError'] ) ) {
		return false;
	}
	if ( 'supervisor-ready' === $phase && ( null !== $anchor || null !== $target ) ) {
		return false;
	}
	if ( 'anchor-ready' === $phase && ( null === $anchor || null !== $target ) ) {
		return false;
	}
	if ( in_array( $phase, array( 'gated', 'running', 'target-exit', 'cleaning', 'cleanup-failed' ), true ) && ( null === $anchor || null === $target ) ) {
		return false;
	}
	return 'cleaned' !== $phase || ( null === $anchor ? null === $target : true );
}

function read_state( string $root, string $token ): ?array {
	global $ownership_identities;
	if ( null !== owner_error( $root, $token ) ) {
		return null;
	}
	$state_path = $root . DIRECTORY_SEPARATOR . STATE_FILE;
	$state_before = @lstat( $state_path );
	if (
		false === $state_before ||
		( $state_before['mode'] & 0170000 ) !== 0100000 ||
		( $state_before['mode'] & 0777 ) !== 0600 ||
		( function_exists( 'posix_geteuid' ) && $state_before['uid'] !== posix_geteuid() )
	) {
		return null;
	}
	try {
		$text = read_exact_file( $state_path, 65536 );
		$state = json_decode( $text, true, 64, JSON_THROW_ON_ERROR );
	} catch ( \Throwable $error ) {
		return null;
	}
	$state_after = @lstat( $state_path );
	if (
		null !== owner_error( $root, $token ) ||
		! stat_matches_identity( $state_after, identity_from_stat( $state_before ) )
	) {
		return null;
	}
	return valid_state_schema( $state ) &&
		$token === ( $state['token'] ?? null ) &&
		( $state['rootIdentity'] ?? null ) === $ownership_identities[ $root ]['root'] &&
		( $state['ownerIdentity'] ?? null ) === $ownership_identities[ $root ]['owner']
		? $state
		: null;
}

function emit_control( $control, array $event ): void {
	$json = json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	if ( strlen( $json ) > CONTROL_MAX_BYTES || strlen( $json ) !== @fwrite( $control, $json ) || ! @fflush( $control ) ) {
		fail( 'Could not emit complete oracle supervisor control event.' );
	}
}

function parse_arguments( array $argv ): array {
	$options = array();
	$target_args = array();
	$separator = array_search( '--', $argv, true );
	if ( false === $separator ) {
		fail( 'Oracle supervisor command is missing -- separator.' );
	}
	$prefix = array_slice( $argv, 1, $separator - 1 );
	$target_args = array_slice( $argv, $separator + 1 );
	if ( 0 !== count( $prefix ) % 2 ) {
		fail( 'Oracle supervisor options must be name/value pairs.' );
	}
	for ( $index = 0; $index < count( $prefix ); $index += 2 ) {
		$name = $prefix[ $index ];
		if ( ! in_array( $name, array( '--root', '--token', '--root-dev', '--root-ino', '--owner-dev', '--owner-ino', '--target', '--target-sha256', '--supervisor-sha256' ), true ) || isset( $options[ $name ] ) ) {
			fail( 'Unknown or duplicate oracle supervisor option.' );
		}
		$options[ $name ] = $prefix[ $index + 1 ];
	}
	foreach ( array( '--root', '--token', '--root-dev', '--root-ino', '--owner-dev', '--owner-ino', '--target', '--target-sha256', '--supervisor-sha256' ) as $required ) {
		if ( ! is_string( $options[ $required ] ?? null ) || '' === $options[ $required ] ) {
			fail( 'Missing oracle supervisor option: ' . $required );
		}
	}
	if (
		! is_absolute_path( $options['--root'] ) ||
		! is_absolute_path( $options['--target'] ) ||
		1 !== preg_match( '/^[0-9a-f]{32}$/', $options['--token'] ) ||
		1 !== preg_match( '/^[0-9a-f]{64}$/', $options['--target-sha256'] ) ||
		1 !== preg_match( '/^[0-9a-f]{64}$/', $options['--supervisor-sha256'] )
	) {
		fail( 'Malformed oracle supervisor identity option.' );
	}
	$inode_values = array();
	foreach ( array( '--root-dev', '--root-ino', '--owner-dev', '--owner-ino' ) as $name ) {
		$value = filter_var( $options[ $name ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $value ) {
			fail( 'Malformed oracle supervisor inode identity option.' );
		}
		$inode_values[ $name ] = (int) $value;
	}
	return array(
		'root'             => $options['--root'],
		'token'            => $options['--token'],
		'target'           => $options['--target'],
		'targetSha256'     => $options['--target-sha256'],
		'supervisorSha256' => $options['--supervisor-sha256'],
		'rootIdentity'     => array( 'dev' => $inode_values['--root-dev'], 'ino' => $inode_values['--root-ino'] ),
		'ownerIdentity'    => array( 'dev' => $inode_values['--owner-dev'], 'ino' => $inode_values['--owner-ino'] ),
		'targetArgs'       => $target_args,
	);
}

function authenticate_supervisor( array $state ): ?array {
	$recorded = $state['supervisor'] ?? null;
	if ( ! is_array( $recorded ) ) {
		return null;
	}
	$current = process_identity( (int) ( $recorded['pid'] ?? 0 ) );
	if ( ! same_identity( $recorded, $current ) ) {
		return null;
	}
	return identity_command_contains(
		$current,
		array( (string) ( $state['supervisorPath'] ?? '' ), (string) ( $state['root'] ?? '' ), (string) ( $state['token'] ?? '' ) )
	) ? $current : null;
}

function authenticate_anchor( array $state ): ?array {
	$recorded = $state['anchor'] ?? null;
	if ( ! is_array( $recorded ) ) {
		return null;
	}
	$current = process_identity( (int) ( $recorded['pid'] ?? 0 ) );
	if ( ! same_identity( $recorded, $current ) ) {
		return null;
	}
	if (
		(int) ( $state['supervisor']['sid'] ?? 0 ) !== $current['sid'] ||
		(int) ( $recorded['pid'] ?? 0 ) !== $current['pgid'] ||
		! identity_command_contains(
			$current,
			array( (string) ( $state['supervisorPath'] ?? '' ), (string) ( $state['root'] ?? '' ), (string) ( $state['token'] ?? '' ) )
		)
	) {
		return null;
	}
	return $current;
}

function reap_direct_children( array $pids ): void {
	foreach ( array_unique( array_map( 'intval', $pids ) ) as $pid ) {
		if ( $pid > 1 ) {
			@pcntl_waitpid( $pid, $ignored, WNOHANG );
		}
	}
}

function reap_all_direct_children(): void {
	while ( pcntl_waitpid( -1, $ignored, WNOHANG ) > 0 ) {
		// Drain every exited direct child owned by this supervisor.
	}
}

function wait_for_group_absence( int $sid, int $pgid, int $microseconds, array $direct_children = array(), bool $reap_all = false ): bool {
	$deadline = hrtime( true ) + ( $microseconds * 1000 );
	do {
		reap_direct_children( $direct_children );
		if ( $reap_all ) {
			reap_all_direct_children();
		}
		$members = session_group_members( $sid, $pgid );
		if ( null === $members ) {
			$retry_pid = process_inspection_retry_pid();
			if ( null !== $retry_pid ) {
				usleep( 10000 );
				continue;
			}
			return false;
		}
		if ( array() === $members ) {
			return true;
		}
		usleep( 10000 );
	} while ( hrtime( true ) < $deadline );
	reap_direct_children( $direct_children );
	if ( $reap_all ) {
		reap_all_direct_children();
	}
	$members = session_group_members( $sid, $pgid );
	return is_array( $members ) && array() === $members;
}

/** Reauthenticate the anchor and wait for one complete snapshot containing it. */
function wait_for_authenticated_group( array $state, ?int $expected_sid = null, ?int $expected_pgid = null, int $microseconds = KILL_GRACE_MICROSECONDS, bool $reap_all = false ): ?array {
	$deadline = hrtime( true ) + ( $microseconds * 1000 );
	do {
		if ( $reap_all ) {
			reap_all_direct_children();
		}
		$anchor = authenticate_anchor( $state );
		if ( null === $anchor ) {
			usleep( 10000 );
			continue;
		}
		if (
			( null !== $expected_sid && $expected_sid !== $anchor['sid'] ) ||
			( null !== $expected_pgid && $expected_pgid !== $anchor['pgid'] )
		) {
			return null;
		}
		$members = session_group_members( $anchor['sid'], $anchor['pgid'] );
		if ( is_array( $members ) && in_array( $anchor['pid'], $members, true ) ) {
			return array( 'anchor' => $anchor, 'members' => $members );
		}
		usleep( 10000 );
	} while ( hrtime( true ) < $deadline );
	return null;
}

function cleanup_anchored_group( string $root, string $token, bool $reap_all = false ): ?string {
	$state = read_state( $root, $token );
	if ( ! is_array( $state ) || ! in_array( $state['phase'] ?? null, array( 'anchor-ready', 'gated', 'running', 'target-exit', 'cleaning' ), true ) ) {
		return 'Oracle supervisor state cannot authenticate an anchored target group.';
	}
	if ( $reap_all ) {
		reap_all_direct_children();
	}
	$recorded = $state['anchor'] ?? null;
	if ( ! is_array( $recorded ) ) {
		return 'Oracle target group anchor identity does not match.';
	}
	$authenticated_group = wait_for_authenticated_group(
		$state,
		(int) ( $recorded['sid'] ?? 0 ),
		(int) ( $recorded['pgid'] ?? 0 ),
		KILL_GRACE_MICROSECONDS,
		$reap_all
	);
	if ( null === $authenticated_group ) {
		$current = process_identity( (int) ( $recorded['pid'] ?? 0 ) );
		if (
			null === $current &&
			wait_for_group_absence(
				(int) ( $recorded['sid'] ?? 0 ),
				(int) ( $recorded['pgid'] ?? 0 ),
				KILL_GRACE_MICROSECONDS,
				array( (int) ( $recorded['pid'] ?? 0 ), (int) ( $state['target']['pid'] ?? 0 ) ),
				$reap_all
			)
		) {
			return null;
		}
		$members = session_group_members( (int) ( $recorded['sid'] ?? 0 ), (int) ( $recorded['pgid'] ?? 0 ) );
		if ( null === $members ) {
			return 'Oracle target group absence could not be inspected.';
		}
		return 'Oracle target group anchor identity does not match.';
	}
	$anchor = $authenticated_group['anchor'];
	$sid = $anchor['sid'];
	$pgid = $anchor['pgid'];
	if ( ! @posix_kill( -$pgid, SIGTERM ) ) {
		if (
			3 === posix_get_last_error() &&
			wait_for_group_absence( $sid, $pgid, KILL_GRACE_MICROSECONDS, array( $anchor['pid'], (int) ( $state['target']['pid'] ?? 0 ) ), $reap_all )
		) {
			return null;
		}
		return 'Could not signal authenticated oracle target group with SIGTERM.';
	}
	usleep( TERM_GRACE_MICROSECONDS );
	$state = read_state( $root, $token );
	if ( ! is_array( $state ) || ! in_array( $state['phase'] ?? null, array( 'anchor-ready', 'gated', 'running', 'target-exit', 'cleaning' ), true ) ) {
		return 'Oracle target group anchor changed before SIGKILL.';
	}
	$authenticated_group = wait_for_authenticated_group( $state, $sid, $pgid, KILL_GRACE_MICROSECONDS, $reap_all );
	if ( null === $authenticated_group ) {
		if ( wait_for_group_absence( $sid, $pgid, KILL_GRACE_MICROSECONDS, array( $anchor['pid'], (int) ( $state['target']['pid'] ?? 0 ) ), $reap_all ) ) {
			return null;
		}
		return 'Oracle target group lost its anchor before SIGKILL.';
	}
	$anchor = $authenticated_group['anchor'];
	if ( ! @posix_kill( -$pgid, SIGKILL ) ) {
		if (
			3 === posix_get_last_error() &&
			wait_for_group_absence( $sid, $pgid, KILL_GRACE_MICROSECONDS, array( $anchor['pid'], (int) ( $state['target']['pid'] ?? 0 ) ), $reap_all )
		) {
			return null;
		}
		return 'Could not signal authenticated oracle target group with SIGKILL.';
	}
	$direct_children = array( $anchor['pid'], (int) ( $state['target']['pid'] ?? 0 ) );
	if ( ! wait_for_group_absence( $sid, $pgid, KILL_GRACE_MICROSECONDS, $direct_children, $reap_all ) ) {
		$survivors = session_group_members( $sid, $pgid );
		return null === $survivors
			? 'Authenticated oracle target group absence could not be inspected after SIGKILL: ' . ( last_process_inspection_error() ?? 'unknown inspection failure' )
			: 'Authenticated oracle target group survived SIGKILL: ' . implode( ',', $survivors );
	}
	$current_anchor = process_identity( $anchor['pid'] );
	if ( null !== $current_anchor && same_identity( $anchor, $current_anchor ) ) {
		return 'Authenticated oracle target anchor survived SIGKILL.';
	}
	return null;
}

function wait_for_session_to_contain_only( int $sid, int $pid, int $microseconds ): bool {
	$deadline = hrtime( true ) + ( $microseconds * 1000 );
	do {
		reap_all_direct_children();
		$members = session_members( $sid );
		if ( null === $members ) {
			return false;
		}
		if ( array( $pid ) === $members ) {
			return true;
		}
		usleep( 10000 );
	} while ( hrtime( true ) < $deadline );
	reap_all_direct_children();
	$members = session_members( $sid );
	return is_array( $members ) && array( $pid ) === $members;
}

function remove_owned_root( string $root, string $token ): bool {
	global $ownership_identities;
	if ( ! is_dir( $root ) ) {
		return true;
	}
	if ( null !== owner_error( $root, $token ) ) {
		return false;
	}
	$entries = scandir( $root );
	if ( ! is_array( $entries ) ) {
		return false;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $root . DIRECTORY_SEPARATOR . $entry;
		$stat = @lstat( $path );
		if ( false === $stat || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
			return false;
		}
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry || OWNER_FILE === $entry ) {
			continue;
		}
		if ( null !== owner_error( $root, $token ) || ! @unlink( $root . DIRECTORY_SEPARATOR . $entry ) ) {
			return false;
		}
	}
	if ( null !== owner_error( $root, $token ) || ! @unlink( $root . DIRECTORY_SEPARATOR . OWNER_FILE ) ) {
		return false;
	}
	$root_stat = @lstat( $root );
	if ( ! stat_matches_identity( $root_stat, $ownership_identities[ $root ]['root'] ) ) {
		return false;
	}
	return @rmdir( $root );
}

function wait_for_gate_byte( $gate, string $expected, int $timeout_seconds = 60 ): bool {
	stream_set_blocking( $gate, false );
	$deadline = hrtime( true ) + ( $timeout_seconds * 1000000000 );
	while ( hrtime( true ) < $deadline ) {
		$read = array( $gate );
		$write = null;
		$except = null;
		$selected = @stream_select( $read, $write, $except, 0, 100000 );
		if ( false === $selected ) {
			return false;
		}
		if ( 0 === $selected ) {
			continue;
		}
		$byte = fread( $gate, 1 );
		return $expected === $byte;
	}
	return false;
}

/** Return alive, shutdown, dead, or invalid for the authenticated owner pipe. */
function owner_control_status( $owner, string &$buffer, string $token, int $wait_microseconds = 0 ): string {
	$expected = json_encode(
		array( 'schemaVersion' => 1, 'command' => 'shutdown', 'token' => $token ),
		JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	) . "\n";
	if ( false !== strpos( $buffer, "\n" ) ) {
		return hash_equals( $expected, $buffer ) ? 'shutdown' : 'invalid';
	}
	$read = array( $owner );
	$write = null;
	$except = null;
	$seconds = intdiv( $wait_microseconds, 1000000 );
	$microseconds = $wait_microseconds % 1000000;
	$selected = @stream_select( $read, $write, $except, $seconds, $microseconds );
	if ( false === $selected ) {
		return 'invalid';
	}
	if ( 0 === $selected ) {
		return feof( $owner ) ? 'dead' : 'alive';
	}
	$chunk = fread( $owner, 4096 );
	if ( false === $chunk ) {
		return 'invalid';
	}
	if ( '' === $chunk ) {
		return feof( $owner ) ? 'dead' : 'alive';
	}
	$buffer .= $chunk;
	if ( strlen( $buffer ) > CONTROL_MAX_BYTES ) {
		return 'invalid';
	}
	$newline = strpos( $buffer, "\n" );
	if ( false === $newline ) {
		return 'alive';
	}
	return hash_equals( $expected, $buffer ) ? 'shutdown' : 'invalid';
}

function child_exit_code( int $status ): int {
	if ( pcntl_wifexited( $status ) ) {
		return pcntl_wexitstatus( $status );
	}
	if ( pcntl_wifsignaled( $status ) ) {
		return 128 + pcntl_wtermsig( $status );
	}
	return 125;
}

function pause_at_marker( string $environment_name, $owner = null, ?string &$owner_buffer = null, ?string $token = null ): void {
	$marker = getenv( $environment_name );
	if ( ! is_string( $marker ) || '' === $marker ) {
		return;
	}
	@file_put_contents( $marker, getmypid() . "\n", LOCK_EX );
	while ( file_exists( $marker ) ) {
		if ( is_resource( $owner ) && is_string( $owner_buffer ) && is_string( $token ) ) {
			$status = owner_control_status( $owner, $owner_buffer, $token, 50000 );
			if ( 'alive' !== $status ) {
				return;
			}
		} else {
			usleep( 50000 );
		}
	}
}

/**
 * Best-effort fail-closed cleanup after main() unwinds and closes every gate.
 *
 * This routine removes the ownership root only after the last authenticated
 * published phase proves that no target process can remain.
 */
function cleanup_after_supervisor_exception( array $argv, string $message ): ?string {
	try {
		$options = parse_arguments( $argv );
	} catch ( \Throwable $error ) {
		return 'Could not recover authenticated supervisor arguments: ' . $error->getMessage();
	}
	$root  = $options['root'];
	$token = $options['token'];
	register_ownership_identity( $root, $options['rootIdentity'], $options['ownerIdentity'] );
	$state = read_state( $root, $token );
	if ( ! is_array( $state ) ) {
		return 'Could not recover authenticated supervisor state.';
	}
	$supervisor = authenticate_supervisor( $state );
	if ( null === $supervisor || getmypid() !== $supervisor['pid'] ) {
		return 'Could not authenticate the failing supervisor process.';
	}
	$phase = $state['phase'] ?? null;
	if ( 'supervisor-ready' === $phase ) {
		if ( ! wait_for_session_to_contain_only( $supervisor['sid'], $supervisor['pid'], KILL_GRACE_MICROSECONDS ) ) {
			return 'Unpublished oracle session members remained after gate closure.';
		}
	} elseif ( in_array( $phase, array( 'anchor-ready', 'gated', 'running', 'target-exit', 'cleaning' ), true ) ) {
		$cleanup_error = cleanup_anchored_group( $root, $token, true );
		if ( null !== $cleanup_error ) {
			return $cleanup_error;
		}
	} elseif ( 'cleaned' === $phase ) {
		$anchor = $state['anchor'] ?? null;
		if ( is_array( $anchor ) ) {
			$current = process_identity( (int) ( $anchor['pid'] ?? 0 ) );
			$members = session_group_members( (int) ( $anchor['sid'] ?? 0 ), (int) ( $anchor['pgid'] ?? 0 ) );
			if ( null !== $current || null === $members || array() !== $members ) {
				return 'Cleaned oracle state could not prove target group absence.';
			}
		}
	} else {
		return 'Failing supervisor phase is not safe for automatic cleanup.';
	}
	$state['phase'] = 'cleaned';
	$state['cleanupError'] = null;
	try {
		atomic_state( $root, $state );
	} catch ( \Throwable $error ) {
		return 'Could not publish cleaned state after supervisor failure: ' . $error->getMessage();
	}
	if ( ! remove_owned_root( $root, $token ) ) {
		return 'Could not remove authenticated ownership root after supervisor failure.';
	}
	return null;
}

function main( array $argv ): int {
	if (
		! function_exists( 'pcntl_fork' ) ||
		! function_exists( 'pcntl_waitpid' ) ||
		! function_exists( 'posix_setsid' ) ||
		! function_exists( 'posix_setpgid' ) ||
		! function_exists( 'posix_getsid' ) ||
		! function_exists( 'posix_getpgid' ) ||
		! function_exists( 'posix_kill' ) ||
		! defined( 'SIGKILL' )
	) {
		fail( 'Oracle supervision requires POSIX and PCNTL.' );
	}
	$options = parse_arguments( $argv );
	pcntl_async_signals( true );
	if ( defined( 'SIGPIPE' ) ) {
		pcntl_signal( SIGPIPE, SIG_IGN );
	}
	$root = $options['root'];
	$token = $options['token'];
	$target = $options['target'];
	register_ownership_identity( $root, $options['rootIdentity'], $options['ownerIdentity'] );
	$supervisor_path = realpath( __FILE__ );
	if (
		null !== owner_error( $root, $token ) ||
		false === $supervisor_path ||
		$supervisor_path !== __FILE__ ||
		! hash_equals( $options['supervisorSha256'], hash_file( 'sha256', __FILE__ ) ?: '' ) ||
		! hash_equals( $options['targetSha256'], hash_file( 'sha256', $target ) ?: '' )
	) {
		fail( 'Oracle supervisor private execution identity is invalid.' );
	}
	$control = @fopen( 'php://fd/3', 'wb' );
	if ( false === $control ) {
		fail( 'Oracle supervisor control descriptor is unavailable.' );
	}
	stream_set_write_buffer( $control, 0 );
	stream_set_blocking( STDIN, false );
	$owner_buffer = '';
	if ( -1 === posix_setsid() ) {
		fail( 'Oracle supervisor could not create its private session.' );
	}
	$supervisor_identity = process_identity( getmypid() );
	if ( null === $supervisor_identity || getmypid() !== $supervisor_identity['sid'] ) {
		fail( 'Oracle supervisor session identity is unavailable.' );
	}
	$base_state = array(
		'schemaVersion'    => SCHEMA_VERSION,
		'token'            => $token,
		'root'             => $root,
		'rootIdentity'     => $options['rootIdentity'],
		'ownerIdentity'    => $options['ownerIdentity'],
		'phase'            => 'supervisor-ready',
		'supervisorPath'   => $supervisor_path,
		'supervisorSha256' => $options['supervisorSha256'],
		'targetPath'       => $target,
		'targetSha256'     => $options['targetSha256'],
		'supervisor'       => identity_document( $supervisor_identity ),
		'anchor'           => null,
		'target'           => null,
		'cleanupError'     => null,
	);
	atomic_state( $root, $base_state );
	emit_control( $control, array( 'schemaVersion' => 1, 'event' => 'supervisor-ready', 'token' => $token, 'supervisor' => $base_state['supervisor'] ) );

	$anchor_gate = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0 );
	$anchor_ready = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0 );
	if ( false === $anchor_gate || false === $anchor_ready ) {
		fail( 'Could not create oracle anchor gates.' );
	}
	$anchor_pid = pcntl_fork();
	if ( -1 === $anchor_pid ) {
		fail( 'Could not fork oracle target group anchor.' );
	}
	if ( 0 === $anchor_pid ) {
		fclose( $anchor_gate[0] );
		fclose( $anchor_ready[0] );
		fclose( $control );
		fclose( STDIN );
		if ( ! posix_setpgid( 0, 0 ) ) {
			exit( 125 );
		}
		pcntl_async_signals( true );
		pcntl_signal( SIGTERM, static function (): void {} );
		pcntl_signal( SIGHUP, static function (): void {} );
		pcntl_signal( SIGINT, static function (): void {} );
		$identity = process_identity( getmypid() );
		if ( null === $identity ) {
			exit( 125 );
		}
		$ready = json_encode( identity_document( $identity ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		if ( strlen( $ready ) !== fwrite( $anchor_ready[1], $ready ) || ! fflush( $anchor_ready[1] ) ) {
			exit( 125 );
		}
		fclose( $anchor_ready[1] );
		if ( ! wait_for_gate_byte( $anchor_gate[1], 'A' ) ) {
			exit( 126 );
		}
		fclose( $anchor_gate[1] );
		while ( true ) {
			usleep( 100000 );
		}
	}
	fclose( $anchor_gate[1] );
	fclose( $anchor_ready[1] );
	stream_set_timeout( $anchor_ready[0], 5 );
	$anchor_line = fgets( $anchor_ready[0], CONTROL_MAX_BYTES + 1 );
	fclose( $anchor_ready[0] );
	$anchor_document = validate_child_identity_line( $anchor_line, $anchor_pid, $supervisor_identity['sid'], $anchor_pid, $supervisor_path, $root, $token );
	if ( null === $anchor_document ) {
		fclose( $anchor_gate[0] );
		fail( 'Oracle target group anchor did not report valid identity.' );
	}
	pause_at_marker( 'HTML_API_FUZZ_TEST_PAUSE_AFTER_ANCHOR_READY', STDIN, $owner_buffer, $token );
	$state = $base_state;
	$state['phase'] = 'anchor-ready';
	$state['anchor'] = $anchor_document;
	atomic_state( $root, $state );
	$owner_status = owner_control_status( STDIN, $owner_buffer, $token );
	if ( 'alive' !== $owner_status ) {
		fail( 'Oracle owner disappeared or sent invalid control before anchor release.' );
	}
	if ( 1 !== fwrite( $anchor_gate[0], 'A' ) || ! fflush( $anchor_gate[0] ) ) {
		fclose( $anchor_gate[0] );
		fail( 'Could not release authenticated oracle anchor.' );
	}
	fclose( $anchor_gate[0] );
	emit_control( $control, array( 'schemaVersion' => 1, 'event' => 'anchor-ready', 'token' => $token, 'anchor' => $anchor_document ) );

	$target_gate = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0 );
	$target_ready = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0 );
	if ( false === $target_gate || false === $target_ready ) {
		fail( 'Could not create oracle target gates.' );
	}
	$target_pid = pcntl_fork();
	if ( -1 === $target_pid ) {
		fail( 'Could not fork oracle target.' );
	}
	if ( 0 === $target_pid ) {
		fclose( $target_gate[0] );
		fclose( $target_ready[0] );
		fclose( $control );
		fclose( STDIN );
		if ( ! posix_setpgid( 0, $anchor_pid ) ) {
			exit( 125 );
		}
		$identity = process_identity( getmypid() );
		if ( null === $identity ) {
			exit( 125 );
		}
		$ready = json_encode( identity_document( $identity ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		if ( strlen( $ready ) !== fwrite( $target_ready[1], $ready ) || ! fflush( $target_ready[1] ) ) {
			exit( 125 );
		}
		fclose( $target_ready[1] );
		if ( ! wait_for_gate_byte( $target_gate[1], 'T' ) ) {
			exit( 126 );
		}
		fclose( $target_gate[1] );
		pcntl_exec( $target, $options['targetArgs'] );
		exit( 125 );
	}
	fclose( $target_gate[1] );
	fclose( $target_ready[1] );
	stream_set_timeout( $target_ready[0], 5 );
	$target_line = fgets( $target_ready[0], CONTROL_MAX_BYTES + 1 );
	fclose( $target_ready[0] );
	$target_document = validate_child_identity_line( $target_line, $target_pid, $supervisor_identity['sid'], $anchor_pid, $supervisor_path, $root, $token );
	if ( null === $target_document ) {
		fclose( $target_gate[0] );
		fail( 'Oracle target did not report valid gated identity.' );
	}
	$state['phase'] = 'gated';
	$state['target'] = $target_document;
	atomic_state( $root, $state );
	pause_at_marker( 'HTML_API_FUZZ_TEST_PAUSE_AFTER_GATED', STDIN, $owner_buffer, $token );
	$owner_status = owner_control_status( STDIN, $owner_buffer, $token );
	if ( 'alive' !== $owner_status ) {
		fail( 'Oracle owner disappeared or sent invalid control before target release.' );
	}
	if ( 1 !== fwrite( $target_gate[0], 'T' ) || ! fflush( $target_gate[0] ) ) {
		fclose( $target_gate[0] );
		fail( 'Could not release authenticated oracle target.' );
	}
	fclose( $target_gate[0] );
	$state['phase'] = 'running';
	atomic_state( $root, $state );
	emit_control( $control, array( 'schemaVersion' => 1, 'event' => 'running', 'token' => $token, 'target' => $target_document ) );

	pcntl_async_signals( true );
	$signal_shutdown = false;
	pcntl_signal( SIGTERM, static function () use ( &$signal_shutdown ): void { $signal_shutdown = true; } );
	pcntl_signal( SIGINT, static function () use ( &$signal_shutdown ): void { $signal_shutdown = true; } );
	pcntl_signal( SIGHUP, static function () use ( &$signal_shutdown ): void { $signal_shutdown = true; } );
	$owner_dead = false;
	$intentional_shutdown = false;
	$owner_invalid = false;
	$target_status = null;
	while ( true ) {
		$wait = pcntl_waitpid( $target_pid, $wait_status, WNOHANG );
		if ( $target_pid === $wait ) {
			$target_status = $wait_status;
			break;
		}
		if ( $signal_shutdown ) {
			$intentional_shutdown = true;
			break;
		}
		$owner_status = owner_control_status( STDIN, $owner_buffer, $token, 50000 );
		if ( 'shutdown' === $owner_status ) {
			$intentional_shutdown = true;
			break;
		}
		if ( 'dead' === $owner_status ) {
			$owner_dead = true;
			break;
		}
		if ( 'invalid' === $owner_status ) {
			$owner_invalid = true;
			break;
		}
	}
	$state['phase'] = null === $target_status ? 'cleaning' : 'target-exit';
	atomic_state( $root, $state );
	$cleanup_error = cleanup_anchored_group( $root, $token, true );
	pcntl_waitpid( $target_pid, $ignored_target, WNOHANG );
	pcntl_waitpid( $anchor_pid, $ignored_anchor, WNOHANG );
	if ( null !== $cleanup_error ) {
		$state['phase'] = 'cleanup-failed';
		$state['cleanupError'] = $cleanup_error;
		atomic_state( $root, $state );
		emit_control( $control, array( 'schemaVersion' => 1, 'event' => 'cleanup-failed', 'token' => $token, 'error' => $cleanup_error ) );
		return 125;
	}
	$state['phase'] = 'cleaned';
	$state['cleanupError'] = null;
	atomic_state( $root, $state );
	emit_control( $control, array( 'schemaVersion' => 1, 'event' => 'cleaned', 'token' => $token, 'ownerDead' => $owner_dead ) );
	pause_at_marker( 'HTML_API_FUZZ_TEST_PAUSE_AFTER_CLEANED', STDIN, $owner_buffer, $token );
	if ( ! remove_owned_root( $root, $token ) ) {
		return 125;
	}
	if ( null !== $target_status && ! $intentional_shutdown && ! $owner_dead ) {
		return child_exit_code( $target_status );
	}
	return $owner_invalid ? 125 : ( $owner_dead ? 124 : 143 );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		exit( main( $argv ) );
	} catch ( \Throwable $error ) {
		$cleanup_error = cleanup_after_supervisor_exception( $argv, $error->getMessage() );
		$message = 'Oracle process supervisor failed: ' . $error->getMessage();
		if ( null !== $cleanup_error ) {
			$message .= '; fail-closed cleanup retained evidence: ' . $cleanup_error;
		}
		fwrite( STDERR, $message . "\n" );
		exit( 125 );
	}
}
