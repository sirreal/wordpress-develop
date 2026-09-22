#!/usr/bin/env php
<?php
define( 'HTML_API_FUZZ_CODEX_SELF_TESTING', true );
require_once dirname( __DIR__ ) . '/codex-triage-orchestrator.php';

function html_api_fuzz_codex_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function html_api_fuzz_codex_test_tmpdir(): string {
	$dir = sys_get_temp_dir() . '/html-api-fuzz-codex-test-' . bin2hex( random_bytes( 6 ) );
	if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new RuntimeException( 'Could not create test directory: ' . $dir );
	}

	return $dir;
}

function html_api_fuzz_codex_test_expect_exception( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Throwable $error ) {
		return;
	}

	throw new RuntimeException( $message );
}

$repo_root = dirname( __DIR__, 3 );
$tmp       = html_api_fuzz_codex_test_tmpdir();
$triage    = $tmp . '/triage';
$run_dir   = $tmp . '/run';
$diag      = $tmp . '/diagnostics';
\HtmlApiFuzz\ensure_dir( $triage );
\HtmlApiFuzz\ensure_dir( $run_dir );
\HtmlApiFuzz\ensure_dir( $diag );

html_api_fuzz_codex_test_expect_exception(
	static function () use ( $triage, $repo_root ): void {
		html_api_fuzz_codex_parse_args(
			array(
				'codex-triage-orchestrator.php',
				'--triage-dir',
				$triage,
				'--repo-root',
				$repo_root,
				'--max-concurent',
				'4',
			)
		);
	},
	'Unknown CLI options should be rejected.'
);

html_api_fuzz_codex_test_expect_exception(
	static function () use ( $triage, $repo_root ): void {
		html_api_fuzz_codex_parse_args(
			array(
				'codex-triage-orchestrator.php',
				'--triage-dir',
				$triage,
				'--repo-root',
				$repo_root,
				'--mode',
				'fix',
				'--sandbox',
				'read-only',
			)
		);
	},
	'Fix mode should reject a read-only sandbox.'
);

$candidates = html_api_fuzz_codex_load_candidate_signatures(
	array(
		'signatures' => array(
			123456 => array(
				'status'            => 'minimized',
				'minimizeOutputDir' => $triage,
			),
			"bad\nhash" => array(
				'status'            => 'minimized',
				'minimizeOutputDir' => $triage,
			),
		),
	)
);
html_api_fuzz_codex_test_assert( 1 === count( $candidates ), 'Numeric signature hashes should be accepted and unsafe hashes skipped.' );
html_api_fuzz_codex_test_assert( '123456' === $candidates[0][0], 'Numeric signature hash should be cast to a string.' );

$signature = array(
	'failureClass'     => 'tree-mismatch',
	'status'           => 'minimized',
	'resultPath'       => '/etc/passwd',
	'minimizeResult'   => $run_dir . '/minimize-result.json',
	'minimizeOutputDir'=> $run_dir,
);
file_put_contents( $run_dir . '/minimize-result.json', "{}\n" );
$prompt = html_api_fuzz_codex_prompt_for_signature(
	array(
		'triageDir'      => $triage,
		'diagnosticsDir' => $diag,
		'repoRoot'       => $repo_root,
		'runDir'         => $run_dir,
		'mode'           => 'classify',
	),
	'123456',
	$signature
);
html_api_fuzz_codex_test_assert( false === strpos( $prompt, '/etc/passwd' ), 'Prompt should not include artifacts outside the run or triage directories.' );
html_api_fuzz_codex_test_assert( false !== strpos( $prompt, $run_dir . '/minimize-result.json' ), 'Prompt should include expected run artifacts.' );

html_api_fuzz_codex_test_expect_exception(
	static function () use ( $triage, $diag, $repo_root, $run_dir, $signature ): void {
		$unsafe = $signature;
		$unsafe['failureClass'] = "tree\nmismatch";
		html_api_fuzz_codex_prompt_for_signature(
			array(
				'triageDir'      => $triage,
				'diagnosticsDir' => $diag,
				'repoRoot'       => $repo_root,
				'runDir'         => $run_dir,
				'mode'           => 'classify',
			),
			'123456',
			$unsafe
		);
	},
	'Prompt metadata with control characters should be rejected.'
);

$signature_dir = $diag . '/123456';
\HtmlApiFuzz\ensure_dir( $signature_dir );
file_put_contents( $signature_dir . '/claim.json', json_encode( array( 'pid' => getmypid(), 'claimedAtUnix' => 0 ) ) . "\n" );
$claimed = html_api_fuzz_codex_claim_signature( $diag, '123456', $signature, 1 );
html_api_fuzz_codex_test_assert( null !== $claimed, 'Stale claims should be reclaimed even when the old PID is alive.' );
html_api_fuzz_codex_test_assert( 1 === count( glob( $signature_dir . '/claim.stale.*.json' ) ), 'Stale claim should be archived.' );

file_put_contents( $signature_dir . '/done.json', "null\n" );
$claimed = html_api_fuzz_codex_claim_signature( $diag, '123456', $signature, 1 );
html_api_fuzz_codex_test_assert( null !== $claimed, 'Malformed done metadata should not be treated as a successful completed job.' );
html_api_fuzz_codex_test_assert( 1 <= count( glob( $signature_dir . '/done.failed.*.json' ) ), 'Malformed done metadata should be archived for retry.' );

/*
 * Main-loop STOP handling: with a STOP file in the run directory (resolved
 * from the watcher state's runDir), the orchestrator must exit cleanly
 * without launching anything.
 */
\HtmlApiFuzz\write_json_file(
	$triage . '/state.json',
	array(
		'kind'       => 'html-api-fuzz-triage-state',
		'runDir'     => $run_dir,
		'signatures' => array(),
	)
);
file_put_contents( $run_dir . '/STOP', "{}\n" );
$stop_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/codex-triage-orchestrator.php',
		'--triage-dir',
		$triage,
		'--diagnostics-dir',
		$diag,
		'--repo-root',
		$repo_root,
		'--codex-bin',
		'false',
	),
	$repo_root,
	30000
);
html_api_fuzz_codex_test_assert( 0 === $stop_proc['code'] && ! $stop_proc['timedOut'], 'Orchestrator should exit cleanly when a STOP file is present.' );
html_api_fuzz_codex_test_assert( false !== strpos( $stop_proc['output'], 'stop requested' ), 'Orchestrator should report the stop request.' );
html_api_fuzz_codex_test_assert( false === strpos( $stop_proc['output'], 'launched ' ), 'Orchestrator should not launch jobs after a stop request.' );

echo "codex triage orchestrator smoke tests passed\n";
