#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

const HTML_API_FUZZ_CODEX_CLASSIFICATIONS = array(
	'wordpress-bug',
	'oracle-bug',
	'oracle-limitation',
	'harness-bug',
	'expected-unsupported',
	'scalar-tolerance',
	'WordPress HTML API bug',
	'harness bug',
	'DOM/oracle behavior',
	'encoding-normalization mismatch',
	'expected unsupported',
	'resource-limit finding',
	'inconclusive',
);

function html_api_fuzz_codex_usage(): void {
	echo "Usage: php tools/html-api-fuzz/codex-triage-orchestrator.php --triage-dir DIR [--diagnostics-dir DIR] [--repo-root DIR] [--codex-bin BIN] [--model MODEL] [--interval-seconds N] [--max-concurrent N] [--max-launch-per-pass N] [--stale-after-seconds N] [--once] [--mode classify|fix] [--sandbox read-only|workspace-write|danger-full-access]\n";
	echo "A STOP file in the run directory (from the watcher state's runDir, falling back to the triage dir's parent) stops new launches; running jobs finish before exit.\n";
}

function html_api_fuzz_codex_validate_cli_options( array $options ): void {
	$value_options = array(
		'triage-dir',
		'diagnostics-dir',
		'repo-root',
		'codex-bin',
		'model',
		'interval-seconds',
		'max-concurrent',
		'max-launch-per-pass',
		'stale-after-seconds',
		'mode',
		'sandbox',
	);
	$bool_options = array(
		'help',
		'once',
	);
	$allowed = array_merge( $value_options, $bool_options );

	foreach ( $options as $name => $value ) {
		if ( '_' === $name ) {
			if ( ! empty( $value ) ) {
				throw new InvalidArgumentException( 'Unexpected positional argument: ' . (string) reset( $value ) );
			}
			continue;
		}

		if ( ! in_array( $name, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Unknown option: --' . $name );
		}
		if ( in_array( $name, $value_options, true ) && true === $value ) {
			throw new InvalidArgumentException( 'Expected --' . $name . ' to have a value.' );
		}
		if ( in_array( $name, $bool_options, true ) && true !== $value ) {
			throw new InvalidArgumentException( 'Option --' . $name . ' does not accept a value.' );
		}
	}
}

function html_api_fuzz_codex_is_absolute_path( string $path ): bool {
	return 1 === preg_match( '#^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $path );
}

function html_api_fuzz_codex_now_iso_z(): string {
	return gmdate( 'Y-m-d\TH:i:s\Z' );
}

function html_api_fuzz_codex_normalize_path( string $path ): string {
	if ( '' === $path ) {
		throw new InvalidArgumentException( 'Expected a non-empty path.' );
	}

	if ( ! html_api_fuzz_codex_is_absolute_path( $path ) ) {
		$cwd = getcwd();
		if ( false === $cwd ) {
			throw new RuntimeException( 'Could not determine current working directory.' );
		}
		$path = $cwd . DIRECTORY_SEPARATOR . $path;
	}

	$real = realpath( $path );
	return false === $real ? rtrim( $path, DIRECTORY_SEPARATOR ) : $real;
}

function html_api_fuzz_codex_require_dir( string $path, string $label ): string {
	$normalized = html_api_fuzz_codex_normalize_path( $path );
	if ( ! is_dir( $normalized ) ) {
		throw new InvalidArgumentException( "{$label} is not a directory: {$normalized}" );
	}

	return $normalized;
}

function html_api_fuzz_codex_path_join( string $base, string ...$parts ): string {
	$path = rtrim( $base, DIRECTORY_SEPARATOR );
	foreach ( $parts as $part ) {
		$path .= DIRECTORY_SEPARATOR . ltrim( $part, DIRECTORY_SEPARATOR );
	}

	return $path;
}

function html_api_fuzz_codex_signature_dir_name( string $signature_hash ): string {
	$dir = preg_replace( '/[^a-zA-Z0-9._-]+/', '_', $signature_hash );
	return null === $dir || '' === $dir ? '_' : $dir;
}

function html_api_fuzz_codex_valid_signature_hash( string $signature_hash ): bool {
	return 1 === preg_match( '/^[A-Za-z0-9._-]+$/', $signature_hash );
}

function html_api_fuzz_codex_safe_prompt_line( $value, string $field ): string {
	if ( null === $value ) {
		return '';
	}
	if ( ! is_scalar( $value ) ) {
		throw new InvalidArgumentException( "Expected {$field} to be scalar." );
	}

	$text = (string) $value;
	if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $text ) ) {
		throw new InvalidArgumentException( "Unsafe control character in {$field}." );
	}

	return $text;
}

function html_api_fuzz_codex_path_has_control_chars( string $path ): bool {
	return 1 === preg_match( '/[\x00-\x1F\x7F]/', $path );
}

function html_api_fuzz_codex_path_is_under( string $path, string $root ): bool {
	$real_path = realpath( $path );
	$real_root = realpath( $root );
	if ( false === $real_path || false === $real_root ) {
		return false;
	}

	$real_root = rtrim( $real_root, DIRECTORY_SEPARATOR );
	return $real_path === $real_root || 0 === strpos( $real_path, $real_root . DIRECTORY_SEPARATOR );
}

function html_api_fuzz_codex_allowed_artifact_roots( array $args ): array {
	$roots = array(
		$args['triageDir'],
		$args['runDir'] ?? dirname( $args['triageDir'] ),
	);
	$allowed = array();
	foreach ( $roots as $root ) {
		if ( is_string( $root ) && '' !== $root && is_dir( $root ) && ! html_api_fuzz_codex_path_has_control_chars( $root ) ) {
			$real = realpath( $root );
			if ( false !== $real ) {
				$allowed[] = $real;
			}
		}
	}

	return array_values( array_unique( $allowed ) );
}

function html_api_fuzz_codex_sort_json_value( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	if ( array_is_list( $value ) ) {
		return array_map( 'html_api_fuzz_codex_sort_json_value', $value );
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = html_api_fuzz_codex_sort_json_value( $item );
	}
	ksort( $value, SORT_STRING );

	return $value;
}

function html_api_fuzz_codex_json_encode( $value ): string {
	$json = json_encode(
		html_api_fuzz_codex_sort_json_value( $value ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
	);
	if ( false === $json ) {
		throw new RuntimeException( 'JSON encode failed: ' . json_last_error_msg() );
	}

	return $json;
}

function html_api_fuzz_codex_read_json( string $path, int $retries = 5 ) {
	$last_error = null;
	for ( $i = 0; $i < $retries; ++$i ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$text = @file_get_contents( $path );
		if ( false === $text ) {
			throw new RuntimeException( "Could not read JSON file: {$path}" );
		}

		$value = json_decode( $text, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $value;
		}

		$last_error = json_last_error_msg();
		usleep( 200000 );
	}

	throw new RuntimeException( "Could not parse JSON {$path}: {$last_error}" );
}

function html_api_fuzz_codex_exit_code_from_done( $done ): int {
	if ( ! is_array( $done ) || ! array_key_exists( 'exitCode', $done ) || ! is_numeric( $done['exitCode'] ) ) {
		return 1;
	}

	return (int) $done['exitCode'];
}

function html_api_fuzz_codex_write_json( string $path, $value ): void {
	$dir = dirname( $path );
	\HtmlApiFuzz\ensure_dir( $dir );
	$tmp = tempnam( $dir, basename( $path ) . '.tmp.' );
	if ( false === $tmp ) {
		throw new RuntimeException( "Could not create temporary JSON file for: {$path}" );
	}
	$json = html_api_fuzz_codex_json_encode( $value ) . "\n";
	if ( false === file_put_contents( $tmp, $json ) ) {
		@unlink( $tmp );
		throw new RuntimeException( "Could not write temporary JSON file: {$tmp}" );
	}
	if ( ! rename( $tmp, $path ) ) {
		@unlink( $tmp );
		throw new RuntimeException( "Could not replace JSON file: {$path}" );
	}
}

function html_api_fuzz_codex_archive_path( string $dir, string $prefix ): string {
	$suffix = (string) time();
	$path   = html_api_fuzz_codex_path_join( $dir, "{$prefix}.{$suffix}.json" );
	for ( $i = 1; file_exists( $path ); ++$i ) {
		$path = html_api_fuzz_codex_path_join( $dir, "{$prefix}.{$suffix}.{$i}.json" );
	}

	return $path;
}

function html_api_fuzz_codex_process_alive( int $pid ): bool {
	if ( $pid < 1 || ! function_exists( 'posix_kill' ) ) {
		return false;
	}

	if ( @posix_kill( $pid, 0 ) ) {
		return true;
	}

	if ( function_exists( 'posix_get_last_error' ) && defined( 'POSIX_EPERM' ) ) {
		return POSIX_EPERM === posix_get_last_error();
	}

	return false;
}

function html_api_fuzz_codex_newest_minimize_dir( array $signature ): ?string {
	$output_dir = $signature['minimizeOutputDir'] ?? null;
	if ( is_string( $output_dir ) && '' !== $output_dir && is_dir( $output_dir ) ) {
		return $output_dir;
	}

	$result_path = $signature['minimizeResult'] ?? null;
	if ( is_string( $result_path ) && '' !== $result_path && is_file( $result_path ) ) {
		return dirname( $result_path );
	}

	return null;
}

function html_api_fuzz_codex_claim_signature( string $diagnostics_dir, string $signature_hash, array $signature, int $stale_after_seconds ): ?string {
	$signature_dir = html_api_fuzz_codex_path_join( $diagnostics_dir, html_api_fuzz_codex_signature_dir_name( $signature_hash ) );
	$done_path     = html_api_fuzz_codex_path_join( $signature_dir, 'done.json' );
	$claim_path    = html_api_fuzz_codex_path_join( $signature_dir, 'claim.json' );

	if ( is_file( $done_path ) ) {
		$done      = html_api_fuzz_codex_read_json( $done_path );
		$exit_code = html_api_fuzz_codex_exit_code_from_done( $done );
		if ( 0 === $exit_code ) {
			return null;
		}

		if ( ! rename( $done_path, html_api_fuzz_codex_archive_path( $signature_dir, 'done.failed' ) ) && is_file( $done_path ) ) {
			throw new RuntimeException( "Could not archive failed done file: {$done_path}" );
		}
		if ( is_file( $claim_path ) && ! rename( $claim_path, html_api_fuzz_codex_archive_path( $signature_dir, 'claim.failed' ) ) && is_file( $claim_path ) ) {
			throw new RuntimeException( "Could not archive failed claim file: {$claim_path}" );
		}
	}

	if ( is_file( $claim_path ) ) {
		$claim      = html_api_fuzz_codex_read_json( $claim_path ) ?: array();
		$pid        = (int) ( $claim['pid'] ?? 0 );
		$claimed_at = (float) ( $claim['claimedAtUnix'] ?? 0 );
		$stale      = microtime( true ) - $claimed_at > $stale_after_seconds;
		if ( ! $stale ) {
			if ( $pid > 0 && html_api_fuzz_codex_process_alive( $pid ) ) {
				return null;
			}
			return null;
		}
		if ( ! rename( $claim_path, html_api_fuzz_codex_archive_path( $signature_dir, 'claim.stale' ) ) && is_file( $claim_path ) ) {
			throw new RuntimeException( "Could not archive stale claim file: {$claim_path}" );
		}
	}

	\HtmlApiFuzz\ensure_dir( $signature_dir );
	$claim = array(
		'schemaVersion'    => 1,
		'kind'             => 'html-api-fuzz-codex-claim',
		'hash'             => $signature_hash,
		'pid'              => getmypid(),
		'claimedAt'        => html_api_fuzz_codex_now_iso_z(),
		'claimedAtUnix'    => microtime( true ),
		'failureClass'     => $signature['failureClass'] ?? null,
		'sourceReplayPath' => $signature['replayPath'] ?? null,
		'sourceResultPath' => $signature['resultPath'] ?? null,
	);

	$handle = @fopen( $claim_path, 'xb' );
	if ( false === $handle ) {
		if ( is_file( $claim_path ) ) {
			return null;
		}
		throw new RuntimeException( "Could not create claim file: {$claim_path}" );
	}

	$bytes = html_api_fuzz_codex_json_encode( $claim ) . "\n";
	$write = fwrite( $handle, $bytes );
	fclose( $handle );
	if ( false === $write || $write !== strlen( $bytes ) ) {
		throw new RuntimeException( "Could not write claim file: {$claim_path}" );
	}

	return $signature_dir;
}

function html_api_fuzz_codex_existing_paths( array $paths, array $allowed_roots ): array {
	$existing = array();
	foreach ( $paths as $path ) {
		if ( ! is_string( $path ) || '' === $path || html_api_fuzz_codex_path_has_control_chars( $path ) || ! file_exists( $path ) ) {
			continue;
		}

		foreach ( $allowed_roots as $root ) {
			if ( html_api_fuzz_codex_path_is_under( $path, $root ) ) {
				$real = realpath( $path );
				if ( false !== $real ) {
					$existing[] = $real;
				}
				continue 2;
			}
		}
	}

	return array_values( array_unique( $existing ) );
}

function html_api_fuzz_codex_prompt_for_signature( array $args, string $signature_hash, array $signature ): string {
	if ( ! html_api_fuzz_codex_valid_signature_hash( $signature_hash ) ) {
		throw new InvalidArgumentException( 'Unsafe signature hash: ' . $signature_hash );
	}

	$signature_dir_name   = html_api_fuzz_codex_signature_dir_name( $signature_hash );
	$signature_triage_dir = html_api_fuzz_codex_path_join( $args['triageDir'], 'signatures', $signature_dir_name );
	$minimize_dir         = html_api_fuzz_codex_newest_minimize_dir( $signature );
	$minimized_dir        = null === $minimize_dir ? null : html_api_fuzz_codex_path_join( $minimize_dir, 'minimized' );
	$report_path          = html_api_fuzz_codex_path_join( $args['diagnosticsDir'], $signature_dir_name, 'report.md' );

	$paths = array(
		html_api_fuzz_codex_path_join( $signature_triage_dir, 'failure.json' ),
		is_string( $signature['minimizeResult'] ?? null ) ? $signature['minimizeResult'] : null,
		null === $minimized_dir ? null : html_api_fuzz_codex_path_join( $minimized_dir, 'result.json' ),
		null === $minimized_dir ? null : html_api_fuzz_codex_path_join( $minimized_dir, 'replay.json' ),
		null === $minimized_dir ? null : html_api_fuzz_codex_path_join( $minimized_dir, 'wordpress-tree.txt' ),
		null === $minimized_dir ? null : html_api_fuzz_codex_path_join( $minimized_dir, 'dom-tree.txt' ),
		is_string( $signature['replayPath'] ?? null ) ? $signature['replayPath'] : null,
		is_string( $signature['resultPath'] ?? null ) ? $signature['resultPath'] : null,
	);
	$existing_paths = html_api_fuzz_codex_existing_paths( $paths, html_api_fuzz_codex_allowed_artifact_roots( $args ) );

	if ( 'classify' === $args['mode'] ) {
		$fix_policy = 'This is classify-only mode. Do not edit files. Do not run write commands. Return the Markdown report as your final response; the Codex CLI will save it. If you believe a fix is needed, describe the smallest fix and the focused verification.';
	} else {
		$fix_policy = 'You may implement the smallest justified fix. Preserve the minimized replay and run focused verification.';
	}

	$artifact_lines       = implode( "\n", array_map( static fn( string $path ): string => '- ' . $path, $existing_paths ) );
	$classification_lines = implode( "\n", array_map( static fn( string $classification ): string => '- ' . $classification, HTML_API_FUZZ_CODEX_CLASSIFICATIONS ) );
	$failure_class        = html_api_fuzz_codex_safe_prompt_line( $signature['failureClass'] ?? '', 'failureClass' );
	$triage_kind          = html_api_fuzz_codex_safe_prompt_line( $signature['triageKind'] ?? 'failure', 'triageKind' );
	$oracle_type          = html_api_fuzz_codex_safe_prompt_line( $signature['oracleFindingType'] ?? '', 'oracleFindingType' );
	$suspected_owner      = html_api_fuzz_codex_safe_prompt_line( $signature['suspectedOwner'] ?? '', 'suspectedOwner' );
	$upstream_issue       = html_api_fuzz_codex_safe_prompt_line( $signature['upstreamIssueUrl'] ?? '', 'upstreamIssueUrl' );

	return <<<PROMPT
Diagnose HTML API fuzz signature {$signature_hash}.

Repository root:
{$args['repoRoot']}

Diagnostics report path:
{$report_path}

Failure class from watcher:
{$failure_class}

Triage kind:
{$triage_kind}

Oracle finding type:
{$oracle_type}

Suspected owner:
{$suspected_owner}

Upstream issue:
{$upstream_issue}

Mode:
{$args['mode']}

Policy:
{$fix_policy}

Read these artifacts first:
{$artifact_lines}

Then inspect relevant source under:
- {$args['repoRoot']}/tools/html-api-fuzz
- {$args['repoRoot']}/src/wp-includes/html-api
- {$args['repoRoot']}/tests/phpunit/tests/html-api/wpHtmlProcessorHtml5lib.php

Classify the signature as exactly one of:
{$classification_lines}

Return a concise Markdown report with:
- signature hash
- classification
- minimized input summary
- evidence from the rendered trees/result JSON
- suspected owner and whether this is actionable
- recommended next step

If this is a likely WordPress HTML API bug or harness bug, include a proposed focused regression test.
Do not disturb the running fuzzer.

PROMPT;
}

function html_api_fuzz_codex_write_stream( $stream, string $bytes ): void {
	$offset = 0;
	$length = strlen( $bytes );
	while ( $offset < $length ) {
		$written = fwrite( $stream, substr( $bytes, $offset ) );
		if ( false === $written || 0 === $written ) {
			throw new RuntimeException( 'Could not write prompt to Codex process.' );
		}
		$offset += $written;
	}
}

function html_api_fuzz_codex_command( array $args, string $report_path ): array {
	$command = array(
		$args['codexBin'],
		'-a',
		'never',
	);
	if ( null !== $args['model'] ) {
		$command[] = '--model';
		$command[] = $args['model'];
	}

	return array_merge(
		$command,
		array(
			'exec',
			'-C',
			$args['repoRoot'],
			'-s',
			$args['sandbox'],
			'--color',
			'never',
			'--output-last-message',
			$report_path,
			'--json',
			'-',
		)
	);
}

function html_api_fuzz_codex_setsid_bin(): ?string {
	foreach ( array( '/usr/bin/setsid', '/bin/setsid', '/usr/local/bin/setsid', '/opt/homebrew/bin/setsid' ) as $path ) {
		if ( is_executable( $path ) ) {
			return $path;
		}
	}

	return null;
}

function html_api_fuzz_codex_launch( array $args, string $signature_hash, array $signature, string $signature_dir ): array {
	$report_path = html_api_fuzz_codex_path_join( $signature_dir, 'report.md' );
	$prompt_path = html_api_fuzz_codex_path_join( $signature_dir, 'prompt.md' );
	$stdout_path = html_api_fuzz_codex_path_join( $signature_dir, 'codex.jsonl' );
	$stderr_path = html_api_fuzz_codex_path_join( $signature_dir, 'codex.stderr.log' );
	$done_path   = html_api_fuzz_codex_path_join( $signature_dir, 'done.json' );

	$prompt = html_api_fuzz_codex_prompt_for_signature( $args, $signature_hash, $signature );
	if ( false === file_put_contents( $prompt_path, $prompt ) ) {
		throw new RuntimeException( "Could not write prompt file: {$prompt_path}" );
	}

	$command             = html_api_fuzz_codex_command( $args, $report_path );
	$setsid_bin          = html_api_fuzz_codex_setsid_bin();
	$process_command     = null === $setsid_bin ? $command : array_merge( array( $setsid_bin ), $command );
	$uses_process_group  = null !== $setsid_bin;
	$metadata = array(
		'schemaVersion'  => 1,
		'kind'           => 'html-api-fuzz-codex-job',
		'hash'           => $signature_hash,
		'startedAt'      => html_api_fuzz_codex_now_iso_z(),
		'startedAtUnix'  => microtime( true ),
		'mode'           => $args['mode'],
		'sandbox'        => $args['sandbox'],
		'command'        => $command,
		'processCommand' => $process_command,
		'promptPath'     => $prompt_path,
		'stdoutPath'     => $stdout_path,
		'stderrPath'     => $stderr_path,
		'reportPath'     => $report_path,
		'donePath'       => $done_path,
	);
	html_api_fuzz_codex_write_json( html_api_fuzz_codex_path_join( $signature_dir, 'job.json' ), $metadata );

	$descriptor_spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'file', $stdout_path, 'w' ),
		2 => array( 'file', $stderr_path, 'w' ),
	);
	$process = proc_open( $process_command, $descriptor_spec, $pipes, $args['repoRoot'] );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start Codex subprocess.' );
	}

	try {
		html_api_fuzz_codex_write_stream( $pipes[0], $prompt );
		fclose( $pipes[0] );
	} catch ( Throwable $error ) {
		if ( is_resource( $pipes[0] ) ) {
			fclose( $pipes[0] );
		}
		proc_terminate( $process );
		proc_close( $process );
		throw $error;
	}
	$status = proc_get_status( $process );

	return array(
		'process'         => $process,
		'pid'             => (int) ( $status['pid'] ?? 0 ),
		'processGroupPid' => $uses_process_group ? (int) ( $status['pid'] ?? 0 ) : null,
		'knownExitCode'   => ( ! ( $status['running'] ?? false ) && array_key_exists( 'exitcode', $status ) && -1 !== $status['exitcode'] ) ? (int) $status['exitcode'] : null,
		'signatureDir'    => $signature_dir,
		'hash'            => $signature_hash,
	);
}

function html_api_fuzz_codex_poll_exit_code( array &$job ): ?int {
	if ( null !== ( $job['knownExitCode'] ?? null ) ) {
		$exit_code = (int) $job['knownExitCode'];
		proc_close( $job['process'] );
		return $exit_code;
	}

	$status = proc_get_status( $job['process'] );
	if ( $status['running'] ?? false ) {
		return null;
	}

	$exit_code = null;
	if ( array_key_exists( 'exitcode', $status ) && -1 !== $status['exitcode'] ) {
		$exit_code = (int) $status['exitcode'];
	} elseif ( array_key_exists( 'cached_exitcode', $status ) && false !== $status['cached_exitcode'] ) {
		$exit_code = (int) $status['cached_exitcode'];
	}

	$closed_code = proc_close( $job['process'] );
	if ( null === $exit_code ) {
		$exit_code = (int) $closed_code;
	}

	return $exit_code;
}

function html_api_fuzz_codex_mark_done( array $job, int $exit_code, array $extra = array() ): void {
	$signature_dir  = $job['signatureDir'];
	$signature_hash = $job['hash'];
	$done = array_merge(
		array(
		'schemaVersion'   => 1,
		'kind'            => 'html-api-fuzz-codex-done',
		'hash'            => $signature_hash,
		'finishedAt'      => html_api_fuzz_codex_now_iso_z(),
		'finishedAtUnix' => microtime( true ),
		'exitCode'        => $exit_code,
		'reportPath'      => html_api_fuzz_codex_path_join( $signature_dir, 'report.md' ),
		'stdoutPath'      => html_api_fuzz_codex_path_join( $signature_dir, 'codex.jsonl' ),
		'stderrPath'      => html_api_fuzz_codex_path_join( $signature_dir, 'codex.stderr.log' ),
		),
		$extra
	);
	html_api_fuzz_codex_write_json( html_api_fuzz_codex_path_join( $signature_dir, 'done.json' ), $done );
}

function html_api_fuzz_codex_mark_launch_failed( string $signature_dir, string $signature_hash, Throwable $error ): void {
	html_api_fuzz_codex_mark_done(
		array(
			'signatureDir' => $signature_dir,
			'hash'         => $signature_hash,
		),
		1,
		array(
			'launchFailed' => true,
			'errorClass'   => get_class( $error ),
			'errorMessage' => $error->getMessage(),
		)
	);
}

function html_api_fuzz_codex_load_candidate_signatures( array $state ): array {
	$signatures = $state['signatures'] ?? array();
	if ( ! is_array( $signatures ) ) {
		return array();
	}

	ksort( $signatures, SORT_STRING );
	$candidates = array();
	foreach ( $signatures as $signature_hash => $signature ) {
		if ( ! is_array( $signature ) ) {
			continue;
		}
		$signature_hash = (string) $signature_hash;
		if ( ! html_api_fuzz_codex_valid_signature_hash( $signature_hash ) ) {
			continue;
		}
		if ( 'minimized' !== ( $signature['status'] ?? null ) ) {
			continue;
		}
		if ( null === html_api_fuzz_codex_newest_minimize_dir( $signature ) ) {
			continue;
		}
		$candidates[] = array( $signature_hash, $signature );
	}

	return $candidates;
}

function html_api_fuzz_codex_parse_args( array $argv ): array {
	if ( in_array( '-h', $argv, true ) ) {
		html_api_fuzz_codex_usage();
		exit( 0 );
	}

	$options = \HtmlApiFuzz\parse_cli_options( $argv );
	html_api_fuzz_codex_validate_cli_options( $options );
	if ( \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
		html_api_fuzz_codex_usage();
		exit( 0 );
	}

	$triage_dir = \HtmlApiFuzz\option_string( $options, 'triage-dir' );
	if ( null === $triage_dir ) {
		html_api_fuzz_codex_usage();
		exit( 1 );
	}

	$mode = \HtmlApiFuzz\option_string( $options, 'mode', 'classify' );
	if ( ! in_array( $mode, array( 'classify', 'fix' ), true ) ) {
		throw new InvalidArgumentException( 'Expected --mode to be classify or fix.' );
	}

	$sandbox = \HtmlApiFuzz\option_string( $options, 'sandbox', 'fix' === $mode ? 'workspace-write' : 'read-only' );
	if ( ! in_array( $sandbox, array( 'read-only', 'workspace-write', 'danger-full-access' ), true ) ) {
		throw new InvalidArgumentException( 'Expected --sandbox to be read-only, workspace-write, or danger-full-access.' );
	}
	if ( 'fix' === $mode && 'read-only' === $sandbox ) {
		throw new InvalidArgumentException( 'Expected --mode fix to use a writable sandbox.' );
	}

	$repo_root       = html_api_fuzz_codex_require_dir( \HtmlApiFuzz\option_string( $options, 'repo-root', getcwd() ?: '.' ), 'Repo root' );
	$triage_dir     = html_api_fuzz_codex_require_dir( $triage_dir, 'Triage directory' );
	$diagnostics_dir = \HtmlApiFuzz\option_string( $options, 'diagnostics-dir' );
	if ( null === $diagnostics_dir ) {
		$diagnostics_dir = html_api_fuzz_codex_path_join( dirname( $triage_dir ), 'diagnostics' );
	}

	$interval_seconds      = \HtmlApiFuzz\option_float( $options, 'interval-seconds', 120.0 );
	$max_concurrent        = \HtmlApiFuzz\option_int( $options, 'max-concurrent', 1 );
	$max_launch_per_pass   = \HtmlApiFuzz\option_int( $options, 'max-launch-per-pass', 1 );
	$stale_after_seconds   = \HtmlApiFuzz\option_int( $options, 'stale-after-seconds', 6 * 60 * 60 );
	if ( $interval_seconds < 0 ) {
		throw new InvalidArgumentException( 'Expected --interval-seconds to be at least 0.' );
	}
	if ( $max_concurrent < 0 ) {
		throw new InvalidArgumentException( 'Expected --max-concurrent to be at least 0.' );
	}
	if ( $max_launch_per_pass < 0 ) {
		throw new InvalidArgumentException( 'Expected --max-launch-per-pass to be at least 0.' );
	}
	if ( $stale_after_seconds < 0 ) {
		throw new InvalidArgumentException( 'Expected --stale-after-seconds to be at least 0.' );
	}

	return array(
		'triageDir'           => $triage_dir,
		'diagnosticsDir'      => html_api_fuzz_codex_normalize_path( $diagnostics_dir ),
		'repoRoot'            => $repo_root,
		'codexBin'            => \HtmlApiFuzz\option_string( $options, 'codex-bin', 'codex' ),
		'model'               => \HtmlApiFuzz\option_string( $options, 'model' ),
		'intervalSeconds'     => $interval_seconds,
		'maxConcurrent'       => $max_concurrent,
		'maxLaunchPerPass'    => $max_launch_per_pass,
		'staleAfterSeconds'   => $stale_after_seconds,
		'once'                => \HtmlApiFuzz\option_bool( $options, 'once', false ),
		'mode'                => $mode,
		'sandbox'             => $sandbox,
	);
}

function html_api_fuzz_codex_install_signal_handlers( bool &$stopping ): void {
	if ( ! function_exists( 'pcntl_signal' ) ) {
		throw new RuntimeException( 'The pcntl extension is required for signal-safe Codex job orchestration.' );
	}

	if ( function_exists( 'pcntl_async_signals' ) ) {
		pcntl_async_signals( true );
	}
	if ( defined( 'SIGTERM' ) ) {
		pcntl_signal(
			SIGTERM,
			static function () use ( &$stopping ): void {
				$stopping = true;
			}
		);
	}
	if ( defined( 'SIGINT' ) ) {
		pcntl_signal(
			SIGINT,
			static function () use ( &$stopping ): void {
				$stopping = true;
			}
		);
	}
}

function html_api_fuzz_codex_dispatch_signals(): void {
	if ( function_exists( 'pcntl_signal_dispatch' ) ) {
		pcntl_signal_dispatch();
	}
}

function html_api_fuzz_codex_sleep( float $seconds, bool &$stopping ): void {
	$deadline = microtime( true ) + max( 0.0, $seconds );
	do {
		html_api_fuzz_codex_dispatch_signals();
		if ( $stopping ) {
			return;
		}

		$remaining = $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			return;
		}

		usleep( (int) min( 250000, max( 1000, round( $remaining * 1000000 ) ) ) );
	} while ( true );
}

function html_api_fuzz_codex_stop_job( array &$job ): void {
	$status = proc_get_status( $job['process'] );
	if ( $status['running'] ?? false ) {
		if ( null !== ( $job['processGroupPid'] ?? null ) && function_exists( 'posix_kill' ) && defined( 'SIGTERM' ) ) {
			@posix_kill( -1 * (int) $job['processGroupPid'], SIGTERM );
		} else {
			proc_terminate( $job['process'] );
		}
		usleep( 200000 );
		$status = proc_get_status( $job['process'] );
		if ( $status['running'] ?? false ) {
			if ( null !== ( $job['processGroupPid'] ?? null ) && function_exists( 'posix_kill' ) ) {
				@posix_kill( -1 * (int) $job['processGroupPid'], 9 );
			} else {
				proc_terminate( $job['process'], 9 );
			}
		}
	}
	proc_close( $job['process'] );
	html_api_fuzz_codex_mark_done(
		$job,
		143,
		array(
			'interrupted' => true,
		)
	);
}

function html_api_fuzz_codex_main( array $argv ): int {
	$args       = html_api_fuzz_codex_parse_args( $argv );
	$state_path = html_api_fuzz_codex_path_join( $args['triageDir'], 'state.json' );
	$running    = array();
	$stopping   = false;
	$scanned    = false;

	html_api_fuzz_codex_install_signal_handlers( $stopping );
	\HtmlApiFuzz\ensure_dir( $args['diagnosticsDir'] );
	html_api_fuzz_codex_write_json(
		html_api_fuzz_codex_path_join( $args['diagnosticsDir'], 'orchestrator-state.json' ),
		array(
			'schemaVersion' => 1,
			'kind'          => 'html-api-fuzz-codex-orchestrator-state',
			'startedAt'     => html_api_fuzz_codex_now_iso_z(),
			'triageDir'     => $args['triageDir'],
			'repoRoot'      => $args['repoRoot'],
			'mode'          => $args['mode'],
			'sandbox'       => $args['sandbox'],
		)
	);

	try {
		while ( ! $stopping ) {
			html_api_fuzz_codex_dispatch_signals();
			foreach ( array_keys( $running ) as $key ) {
				$exit_code = html_api_fuzz_codex_poll_exit_code( $running[ $key ] );
				if ( null === $exit_code ) {
					continue;
				}

				html_api_fuzz_codex_mark_done( $running[ $key ], $exit_code );
				echo 'completed ' . $running[ $key ]['hash'] . ' exit=' . $exit_code . "\n";
				unset( $running[ $key ] );
			}

			/*
			 * Graceful stop: a STOP file in the run directory stops new
			 * launches; running jobs finish before the orchestrator exits.
			 * The run directory comes from the watcher state when available
			 * (the triage dir may live outside the run dir), matching how
			 * launches resolve it below.
			 */
			$stop_run_dir = dirname( $args['triageDir'] );
			$stop_state   = html_api_fuzz_codex_read_json( $state_path );
			if ( is_array( $stop_state ) && is_string( $stop_state['runDir'] ?? null ) && ! html_api_fuzz_codex_path_has_control_chars( $stop_state['runDir'] ) ) {
				$stop_run_dir = html_api_fuzz_codex_normalize_path( $stop_state['runDir'] );
			}
			$stop_file_present = is_file( html_api_fuzz_codex_path_join( $stop_run_dir, 'STOP' ) );
			if ( $stop_file_present && empty( $running ) ) {
				echo "stop requested; exiting\n";
				break;
			}

			$can_scan = ( ! $args['once'] || ! $scanned ) && ! $stop_file_present;
			if ( $can_scan ) {
				$available = max( 0, $args['maxConcurrent'] - count( $running ) );
				$launched  = 0;
				if ( $available > 0 ) {
					$state = html_api_fuzz_codex_read_json( $state_path );
					if ( is_array( $state ) ) {
						$launch_args = $args;
						if ( is_string( $state['runDir'] ?? null ) && ! html_api_fuzz_codex_path_has_control_chars( $state['runDir'] ) ) {
							$launch_args['runDir'] = html_api_fuzz_codex_normalize_path( $state['runDir'] );
						} else {
							$launch_args['runDir'] = dirname( $args['triageDir'] );
						}

						foreach ( html_api_fuzz_codex_load_candidate_signatures( $state ) as $candidate ) {
							if ( $launched >= $available || $launched >= $args['maxLaunchPerPass'] ) {
								break;
							}
							list( $signature_hash, $signature ) = $candidate;
							$signature_dir = html_api_fuzz_codex_claim_signature( $args['diagnosticsDir'], $signature_hash, $signature, $args['staleAfterSeconds'] );
							if ( null === $signature_dir ) {
								continue;
							}

							try {
								$job       = html_api_fuzz_codex_launch( $launch_args, $signature_hash, $signature, $signature_dir );
								$running[] = $job;
								++$launched;
								echo 'launched ' . $signature_hash . ' pid=' . $job['pid'] . "\n";
							} catch ( Throwable $error ) {
								html_api_fuzz_codex_mark_launch_failed( $signature_dir, $signature_hash, $error );
								echo 'launch failed ' . $signature_hash . ' error=' . $error->getMessage() . "\n";
							}
						}
					}
				}
				$scanned = true;
			}

			if ( $args['once'] && empty( $running ) ) {
				break;
			}

			$sleep_seconds = $args['once'] ? min( 1.0, $args['intervalSeconds'] ) : $args['intervalSeconds'];
			html_api_fuzz_codex_sleep( $sleep_seconds, $stopping );
		}
	} finally {
		foreach ( array_keys( $running ) as $key ) {
			html_api_fuzz_codex_stop_job( $running[ $key ] );
			unset( $running[ $key ] );
		}
	}

	return 0;
}

if ( ! defined( 'HTML_API_FUZZ_CODEX_SELF_TESTING' ) || ! HTML_API_FUZZ_CODEX_SELF_TESTING ) {
	exit( html_api_fuzz_codex_main( $argv ) );
}
