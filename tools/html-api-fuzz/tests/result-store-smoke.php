#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_smoke_fail( $message );
	}
}

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-result-store-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$db_path = $work_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME;

$store = new \HtmlApiFuzz\ResultStore( $db_path );

$php_oracle = array(
	'kind'       => 'php-dom',
	'phpVersion' => PHP_VERSION,
);
$lexbor_oracle = array(
	'kind'          => 'lexbor-source',
	'lexborVersion' => '2.10.0',
	'lexborCommit'  => '481c444261a132190a3fb746d6d2f60824af3717',
	'binary'        => '/tmp/lexbor-tree-oracle',
);

$pass_summary = array(
	'kind'              => 'attempt',
	'ok'                => true,
	'status'            => 'passed',
	'failureClass'      => null,
	'seed'              => 11,
	'profile'           => 'document',
	'mode'              => 'document',
	'payloadPolicy'     => 'utf8',
	'inputSource'       => 'generated',
	'inputSha1'         => sha1( 'pass' ),
	'inputLength'       => 4,
	'signature'         => null,
	'oracle'            => $php_oracle,
	'artifactsRetained' => false,
	'resultPath'        => null,
	'replayPath'        => null,
	'logPath'           => null,
	'durationMs'        => 12,
	'workerCode'        => 0,
	'workerTimedOut'    => false,
);
$pass_id = $store->record_attempt( $pass_summary );

$failure_summary = array(
	'kind'              => 'failure',
	'ok'                => false,
	'status'            => 'failed',
	'failureClass'      => 'tree-mismatch',
	'seed'              => 12,
	'profile'           => 'document',
	'mode'              => 'document',
	'payloadPolicy'     => 'utf8',
	'inputSource'       => 'generated',
	'inputSha1'         => sha1( 'fail' ),
	'inputLength'       => 8,
	'signature'         => array(
		'hash'      => 'abc123def456',
		'familyKey' => 'fam456789abc',
	),
	'oracle'            => $lexbor_oracle,
	'artifactsRetained' => true,
	'resultPath'        => $work_dir . '/seed-12/primary/result.json',
	'replayPath'        => $work_dir . '/seed-12/primary/replay.json',
	'logPath'           => null,
	'durationMs'        => 30,
	'workerCode'        => 2,
	'workerTimedOut'    => false,
);
$failure_result = array(
	'ok'           => false,
	'status'       => 'failed',
	'failureClass' => 'tree-mismatch',
	'signature'    => array( 'hash' => 'abc123def456' ),
);
$failure_replay = array(
	'kind'        => 'html-api-fuzz-replay',
	'seed'        => 12,
	'inputBase64' => base64_encode( '<i>fail</i>' ),
);
$failure_summary['failureArtifactsRetained'] = true;
$failure_summary['oracleArtifactsRetained']  = false;
$failure_id = $store->record_attempt( $failure_summary, $failure_result, $failure_replay );

$pruned_summary                      = $failure_summary;
$pruned_summary['seed']              = 13;
$pruned_summary['artifactsRetained'] = false;
$pruned_summary['failureArtifactsRetained'] = false;
$pruned_summary['oracleArtifactsRetained']  = false;
$pruned_summary['resultPath']        = null;
$pruned_summary['replayPath']        = null;
$pruned_id = $store->record_attempt( $pruned_summary, $failure_result, $failure_replay );

$same_seed_summary = $pruned_summary;
$same_seed_summary['signature'] = array(
	'hash'      => 'newseedabc123',
	'familyKey' => 'newseedfam456',
);
$same_seed_result = array(
	'ok'           => false,
	'status'       => 'failed',
	'failureClass' => 'tree-mismatch',
	'signature'    => array( 'hash' => 'newseedabc123' ),
);
$same_seed_replay = array(
	'kind'        => 'html-api-fuzz-replay',
	'seed'        => 13,
	'inputBase64' => base64_encode( '<b>new replay</b>' ),
);
$same_seed_id = $store->record_attempt( $same_seed_summary, $same_seed_result, $same_seed_replay );

$oracle_summary = array(
	'kind'              => 'oracle-finding',
	'ok'                => true,
	'status'            => 'oracle-tolerated',
	'failureClass'      => 'oracle-tolerated',
	'seed'              => 14,
	'profile'           => 'document',
	'mode'              => 'document',
	'payloadPolicy'     => 'utf8',
	'inputSource'       => 'generated',
	'inputSha1'         => sha1( 'oracle' ),
	'inputLength'       => 12,
	'signature'         => null,
	'oracle'            => $php_oracle,
	'oracleFinding'     => array(
		'classification' => 'oracle-bug',
		'type'           => 'dom-xlink-dropped-local-name-after-xlink',
		'suspectedOwner' => 'Lexbor/PHP DOM',
		'signature'      => array(
			'hash'      => 'oracle-abc123',
			'familyKey' => 'oracle-fam123',
		),
	),
	'artifactsRetained' => true,
	'failureArtifactsRetained' => false,
	'oracleArtifactsRetained'  => true,
	'resultPath'        => $work_dir . '/seed-14/primary/result.json',
	'replayPath'        => $work_dir . '/seed-14/primary/replay.json',
	'logPath'           => null,
	'durationMs'        => 18,
	'workerCode'        => 0,
	'workerTimedOut'    => false,
);
$oracle_result = array(
	'ok'            => true,
	'status'        => 'oracle-tolerated',
	'oracleFinding' => $oracle_summary['oracleFinding'],
);
$oracle_replay = array(
	'kind'          => 'html-api-fuzz-replay',
	'seed'          => 14,
	'inputBase64'   => base64_encode( '<svg></svg>' ),
	'oracleFinding' => $oracle_summary['oracleFinding'],
);
$oracle_id = $store->record_attempt( $oracle_summary, $oracle_result, $oracle_replay );

html_api_fuzz_smoke_assert( 5 === $store->count_attempts(), 'Expected five recorded attempts.' );
html_api_fuzz_smoke_assert( array( 12 ) === $store->retained_seeds( 'abc123def456' ), 'Expected seed 12 as the retained exemplar for the signature.' );
html_api_fuzz_smoke_assert( array() === $store->retained_seeds( 'unseen' ), 'Expected no retained exemplars for an unseen signature.' );
html_api_fuzz_smoke_assert( array( 14 ) === $store->oracle_retained_seeds( 'oracle-abc123' ), 'Expected seed 14 as the retained exemplar for the oracle signature.' );
html_api_fuzz_smoke_assert( $store->seed_artifacts_retained( 12 ), 'Expected seed 12 to be marked as retained.' );
html_api_fuzz_smoke_assert( ! $store->seed_artifacts_retained( 13 ), 'Expected seed 13 not to be marked as retained.' );
html_api_fuzz_smoke_assert( 5 === $store->max_id(), 'Expected max id of five.' );

$stored_replay = $store->replay_for_seed( 13 );
html_api_fuzz_smoke_assert( is_array( $stored_replay ) && base64_encode( '<b>new replay</b>' ) === ( $stored_replay['inputBase64'] ?? null ), 'Expected seed replay lookup to return the most recent replay for compatibility.' );
html_api_fuzz_smoke_assert( is_array( $store->replay_for_attempt_id( $pruned_id ) ) && base64_encode( '<i>fail</i>' ) === ( $store->replay_for_attempt_id( $pruned_id )['inputBase64'] ?? null ), 'Expected exact attempt replay lookup to survive same-seed reruns.' );
html_api_fuzz_smoke_assert( is_array( $store->replay_for_attempt_id( $same_seed_id ) ) && base64_encode( '<b>new replay</b>' ) === ( $store->replay_for_attempt_id( $same_seed_id )['inputBase64'] ?? null ), 'Expected exact attempt replay lookup to retrieve the newer same-seed replay.' );
html_api_fuzz_smoke_assert( null === $store->replay_for_seed( 11 ), 'Expected no stored replay for a passing seed.' );
$stored_oracle_replay = $store->replay_for_seed( 14 );
html_api_fuzz_smoke_assert( is_array( $stored_oracle_replay ) && base64_encode( '<svg></svg>' ) === ( $stored_oracle_replay['inputBase64'] ?? null ), 'Expected the oracle finding replay to be retrievable from the store.' );
html_api_fuzz_smoke_assert( is_array( $store->replay_for_attempt_id( $oracle_id ) ) && base64_encode( '<svg></svg>' ) === ( $store->replay_for_attempt_id( $oracle_id )['inputBase64'] ?? null ), 'Expected the oracle finding replay to be retrievable by attempt id.' );

$failures = $store->failures_after( 0, $store->max_id() );
html_api_fuzz_smoke_assert( 3 === count( $failures ), 'Expected three failure rows.' );
html_api_fuzz_smoke_assert( 12 === ( $failures[0]['record']['seed'] ?? null ), 'Expected the first failure record to be seed 12.' );
html_api_fuzz_smoke_assert( 'abc123def456' === ( $failures[0]['record']['signature']['hash'] ?? null ), 'Expected the failure record to carry its signature.' );

$tail = $store->failures_after( $failures[0]['id'], $store->max_id() );
html_api_fuzz_smoke_assert( 2 === count( $tail ) && 13 === ( $tail[0]['record']['seed'] ?? null ) && 13 === ( $tail[1]['record']['seed'] ?? null ), 'Expected incremental reads to resume after an offset.' );

$oracle_findings = $store->oracle_findings_after( 0, $store->max_id() );
html_api_fuzz_smoke_assert( 1 === count( $oracle_findings ), 'Expected one oracle finding row.' );
html_api_fuzz_smoke_assert( 14 === ( $oracle_findings[0]['record']['seed'] ?? null ), 'Expected the oracle finding record to be seed 14.' );
html_api_fuzz_smoke_assert( 'oracle-abc123' === ( $oracle_findings[0]['record']['oracleFinding']['signature']['hash'] ?? null ), 'Expected the oracle finding record to carry its oracle signature.' );

$store->close();

// Reopen read-only as the watcher does and confirm persistence.
$reader = new \HtmlApiFuzz\ResultStore( $db_path, true );
html_api_fuzz_smoke_assert( 5 === $reader->count_attempts(), 'Expected attempts to persist across reopen.' );
html_api_fuzz_smoke_assert( 3 === count( $reader->failures_after( 0, $reader->max_id() ) ), 'Expected failures to persist across reopen.' );
html_api_fuzz_smoke_assert( 1 === count( $reader->oracle_findings_after( 0, $reader->max_id() ) ), 'Expected oracle findings to persist across reopen.' );
$reader->close();

// The grouping columns must be queryable without json_extract.
$raw = new SQLite3( $db_path, SQLITE3_OPEN_READONLY );
html_api_fuzz_smoke_assert( 2 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE family_key = 'fam456789abc'" ), 'Expected family_key to be stored per failure row.' );
html_api_fuzz_smoke_assert( 1 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE oracle_family_key = 'oracle-fam123'" ), 'Expected oracle_family_key to be stored per oracle finding row.' );
html_api_fuzz_smoke_assert( 1 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE oracle_signature_hash = 'oracle-abc123'" ), 'Expected oracle_signature_hash to be queryable.' );
html_api_fuzz_smoke_assert( 1 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE signature_hash = 'abc123def456' AND failure_artifacts_retained = 1" ), 'Expected failure retention to use its own budget flag.' );
html_api_fuzz_smoke_assert( 1 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE oracle_signature_hash = 'oracle-abc123' AND oracle_artifacts_retained = 1" ), 'Expected oracle retention to use its own budget flag.' );
html_api_fuzz_smoke_assert( 1 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE seed = 11 AND oracle_kind = 'php-dom' AND oracle_version = '" . SQLite3::escapeString( PHP_VERSION ) . "'" ), 'Expected passing rows to keep PHP DOM oracle metadata in scalar columns.' );
html_api_fuzz_smoke_assert( 3 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE oracle_kind = 'lexbor-source' AND oracle_version = '2.10.0' AND oracle_commit = '481c444261a132190a3fb746d6d2f60824af3717'" ), 'Expected Lexbor oracle metadata to be queryable for failure rows.' );
html_api_fuzz_smoke_assert( 3 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE oracle_binary = '/tmp/lexbor-tree-oracle'" ), 'Expected Lexbor oracle binary to be stored in a scalar column.' );
$raw->close();

$future_db_path = $work_dir . '/future.sqlite';
$future = new SQLite3( $future_db_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE );
$future->exec( 'PRAGMA user_version = 99' );
$future->close();
$future_store = new \HtmlApiFuzz\ResultStore( $future_db_path );
$future_store->close();
$future = new SQLite3( $future_db_path, SQLITE3_OPEN_READONLY );
html_api_fuzz_smoke_assert( 99 === (int) $future->querySingle( 'PRAGMA user_version' ), 'Opening a future schema should not downgrade user_version.' );
$future->close();

\HtmlApiFuzz\remove_dir_recursive( $work_dir );
html_api_fuzz_smoke_assert( ! is_dir( $work_dir ), 'Expected remove_dir_recursive to delete the work directory.' );

echo "OK result-store-smoke\n";
