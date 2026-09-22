#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_batch_environment(): array {
	$environment = getenv();
	$environment = is_array( $environment ) ? $environment : array();
	foreach ( $environment as $name => $value ) {
		if ( ! is_string( $name ) || ! is_string( $value ) ) {
			unset( $environment[ $name ] );
		}
	}
	ksort( $environment );
	return $environment;
}

function html_api_fuzz_batch_git_output( array $arguments ): string {
	$result = \HtmlApiFuzz\run_git_command( $arguments, 30000, \HtmlApiFuzz\repo_root() );
	if ( 0 !== ( $result['code'] ?? null ) || true === ( $result['timedOut'] ?? false ) ) {
		throw new RuntimeException( 'Sanitized Git probe command failed: git ' . implode( ' ', $arguments ) );
	}
	return (string) $result['stdout'];
}

$options = \HtmlApiFuzz\parse_cli_options( $argv );

try {
	if ( array_key_exists( 'internal-runtime-probe', $options ) ) {
		$scanned = php_ini_scanned_files();
		$scanned_files = array();
		if ( is_string( $scanned ) && '' !== trim( $scanned ) ) {
			foreach ( preg_split( '/\s*,\s*/', trim( $scanned ) ) ?: array() as $path ) {
				if ( '' !== $path ) {
					$scanned_files[] = $path;
				}
			}
		}
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo \HtmlApiFuzz\json_encode_safe( array(
			'phpVersion' => PHP_VERSION,
			'phpBinary' => PHP_BINARY,
			'loadedIni' => php_ini_loaded_file() ?: null,
			'scannedIni' => $scanned_files,
			'extensions' => $extensions,
			'environment' => html_api_fuzz_batch_environment(),
		) ) . "\n";
		exit( 0 );
	}

	if ( array_key_exists( 'internal-git-probe', $options ) ) {
		$commit = trim( html_api_fuzz_batch_git_output( array( 'rev-parse', 'HEAD' ) ) );
		$branch = trim( html_api_fuzz_batch_git_output( array( 'branch', '--show-current' ) ) );
		$status = html_api_fuzz_batch_git_output( array( 'status', '--porcelain=v1', '-z', '--untracked-files=all' ) );
		$pathspecs = \HtmlApiFuzz\CommonCrawlBatchCoordinator::executed_code_pathspecs();
		$index = html_api_fuzz_batch_git_output( array_merge( array( 'ls-files', '-s', '-z', '--' ), $pathspecs ) );
		$tracked = array();
		foreach ( explode( "\0", $index ) as $record ) {
			if ( '' === $record ) {
				continue;
			}
			if ( 1 !== preg_match( '/^([0-7]{6}) [0-9a-f]{40} [0-3]\t(.+)$/s', $record, $match ) ) {
				throw new RuntimeException( 'Git index probe returned an invalid record.' );
			}
			$tracked[] = array( 'mode' => $match[1], 'path' => $match[2] );
		}
		echo \HtmlApiFuzz\json_encode_safe( array(
			'commit' => $commit,
			'branch' => '' === $branch ? null : $branch,
			'statusBase64' => base64_encode( $status ),
			'tracked' => $tracked,
			'environment' => html_api_fuzz_batch_environment(),
		) ) . "\n";
		exit( 0 );
	}

	if ( array_key_exists( 'internal-oracle-probe', $options ) ) {
		$encoded = \HtmlApiFuzz\option_string( $options, 'internal-oracle-probe', null );
		if ( null === $encoded ) {
			throw new InvalidArgumentException( 'Internal oracle probe requires an encoded option object.' );
		}
		$json = base64_decode( $encoded, true );
		if ( false === $json ) {
			throw new InvalidArgumentException( 'Internal oracle probe options are not valid base64.' );
		}
		$oracle_options = \HtmlApiFuzz\StrictJsonParser::decode( $json );
		if ( ! is_array( $oracle_options ) ) {
			throw new InvalidArgumentException( 'Internal oracle probe options must be an object.' );
		}
		$allowed = array( 'dom-oracle', 'lexbor-oracle-bin', 'html5ever-oracle-bin', 'chrome-oracle-script', 'chrome-executable', 'node-bin', 'oracle-timeout-ms', 'chrome-startup-timeout-ms' );
		foreach ( $oracle_options as $name => $value ) {
			if ( ! in_array( $name, $allowed, true ) || ! is_string( $value ) || '' === $value ) {
				throw new InvalidArgumentException( 'Internal oracle probe contains an unexpected option.' );
			}
		}
		$renderer = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
		$result = \HtmlApiFuzz\OracleRenderer::with_explicit_close(
			$renderer,
			static function ( \HtmlApiFuzz\OracleRenderer $renderer ): array {
				return array(
					'metadata' => $renderer->metadata(),
					'replayOptions' => $renderer->replay_options(),
					'workerArgs' => $renderer->worker_args(),
					'environment' => html_api_fuzz_batch_environment(),
				);
			}
		);
		echo \HtmlApiFuzz\json_encode_safe( $result ) . "\n";
		exit( 0 );
	}

	if ( \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
		echo "Usage: php tools/html-api-fuzz/commoncrawl-batch.php --cc-analyzer PATH --workspace DIR --batch NAME --output-dir NEW_DIR --batch-timeout-ms N --chrome-executable PATH --node-bin PATH [--lexbor-oracle-bin PATH] [--html5ever-oracle-bin PATH] [--chrome-oracle-script PATH] [--process-timeout-ms N] [--oracle-timeout-ms N] [--chrome-startup-timeout-ms N] [--checks baseline|full|sampled] [--retain-all] [--require-utf8]\n";
		exit( 0 );
	}

	$result = \HtmlApiFuzz\CommonCrawlBatchCoordinator::run( $options );
	echo \HtmlApiFuzz\json_encode_safe( $result ) . "\n";
	exit( 0 );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
