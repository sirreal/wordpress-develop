#!/usr/bin/env php
<?php
namespace CcAnalyzer\Analysis {
	final class HtmlAnalysisInput {
		public string $inputStateKey;
		public string $inputUrl;
		public string $recordId;
		public string $targetUri;
		public int $responseCode;
		public string $contentType;
		public ?string $transportCharset;
		public array $responseHeaders;
		public string $body;
		public ?int $rangeStart;
		public ?int $rangeLength;

		public function __construct( array $row ) {
			$this->inputStateKey = (string) $row['input_state_key'];
			$this->inputUrl = (string) $row['input_url'];
			$this->recordId = (string) $row['record_id'];
			$this->targetUri = (string) $row['target_uri'];
			$this->responseCode = (int) $row['response_code'];
			$this->contentType = (string) $row['content_type'];
			$this->transportCharset = null === $row['transport_charset'] ? null : (string) $row['transport_charset'];
			$this->responseHeaders = array();
			$this->body = (string) $row['body'];
			$this->rangeStart = null === $row['range_start'] ? null : (int) $row['range_start'];
			$this->rangeLength = null === $row['range_length'] ? null : (int) $row['range_length'];
		}
	}
}

namespace {
	function fake_cc_fail( string $message ): void {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}

	function fake_cc_json( $value ): void {
		echo json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	}

	function fake_cc_database( string $workspace ): \SQLite3 {
		$path = $workspace . '/documents.sqlite';
		if ( ! is_file( $path ) ) {
			fake_cc_fail( 'Fixture documents.sqlite is missing.' );
		}
		$db = new \SQLite3( $path, SQLITE3_OPEN_READWRITE );
		$db->busyTimeout( 5000 );
		return $db;
	}

	function fake_cc_control( string $workspace ): array {
		$path = $workspace . '/control.json';
		if ( ! is_file( $path ) ) {
			return array();
		}
		$value = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $value ) ? $value : array();
	}

	function fake_cc_batch( \SQLite3 $db, string $name ): array {
		$statement = $db->prepare(
			'SELECT b.*, COUNT(d.record_id) AS document_count,
				COALESCE(SUM(d.byte_length), 0) AS byte_count,
				(SELECT COUNT(*) FROM document_batch_runs r WHERE r.batch_name = b.name) AS run_count
			FROM document_batches b LEFT JOIN document_batch_documents d ON d.batch_name = b.name
			WHERE b.name = :name GROUP BY b.name LIMIT 1'
		);
		$statement->bindValue( ':name', $name, SQLITE3_TEXT );
		$row = $statement->execute()->fetchArray( SQLITE3_ASSOC );
		if ( ! is_array( $row ) ) {
			fake_cc_fail( 'Fixture batch not found.' );
		}
		return array(
			'name' => (string) $row['name'],
			'sourceMode' => (string) $row['source_mode'],
			'crawlSelection' => (string) $row['crawl_selection'],
			'crawlId' => (string) $row['crawl_id'],
			'urlPattern' => (string) $row['url_pattern'],
			'requestedLimit' => (int) $row['requested_limit'],
			'statePath' => (string) $row['state_path'],
			'cachePath' => (string) $row['cache_path'],
			'status' => (string) $row['status'],
			'documentCount' => (int) $row['document_count'],
			'byteCount' => (int) $row['byte_count'],
			'runCount' => (int) $row['run_count'],
			'createdAt' => (string) $row['created_at'],
			'updatedAt' => (string) $row['updated_at'],
			'completedAt' => null === $row['completed_at'] ? null : (string) $row['completed_at'],
		);
	}

	$args = $argv;
	array_shift( $args );
	$workspace = null;
	if ( '--workspace' === ( $args[0] ?? null ) ) {
		$workspace = $args[1] ?? null;
		$args = array_slice( $args, 2 );
	}
	if ( ! is_string( $workspace ) || ! is_dir( $workspace ) ) {
		fake_cc_fail( 'Expected fixture --workspace.' );
	}
	if ( 'batch' !== ( $args[0] ?? null ) ) {
		fake_cc_fail( 'Fixture supports only batch commands.' );
	}
	$operation = $args[1] ?? '';
	$batch_name = $args[2] ?? '';
	$db = fake_cc_database( $workspace );
	$control = fake_cc_control( $workspace );
	$mode = is_string( $control['mode'] ?? null ) ? $control['mode'] : 'ok';

	if ( 'info' === $operation ) {
		if ( 'nonzero-info' === $mode ) {
			fake_cc_fail( 'Forced fixture info failure.' );
		}
		if ( 'timeout-info' === $mode ) {
			usleep( 5000000 );
		}
		if ( 'truncate-info' === $mode ) {
			echo str_repeat( 'x', 2 * 1024 * 1024 );
			exit( 0 );
		}
		if ( 'malformed-info' === $mode ) {
			echo "{broken info\n";
			exit( 0 );
		}
		fake_cc_json( fake_cc_batch( $db, $batch_name ) );
		exit( 0 );
	}

	if ( 'verify' === $operation ) {
		if ( 'malformed-verify' === $mode ) {
			echo "[]\n";
			exit( 0 );
		}
		$statement = $db->prepare(
			'SELECT COUNT(*) FROM document_batch_documents d
			LEFT JOIN cached_documents c ON c.input_state_key = d.input_state_key AND c.record_id = d.record_id
			WHERE d.batch_name = :name AND c.record_id IS NULL'
		);
		$statement->bindValue( ':name', $batch_name, SQLITE3_TEXT );
		$missing = (int) $statement->execute()->fetchArray( SQLITE3_NUM )[0];
		$batch = fake_cc_batch( $db, $batch_name );
		fake_cc_json( array( 'batchName' => $batch_name, 'documentCount' => $batch['documentCount'], 'missingCount' => $missing, 'missingDocuments' => array() ) );
		exit( 0 === $missing ? 0 : 1 );
	}

	if ( 'run' !== $operation ) {
		fake_cc_fail( 'Unknown fixture batch operation.' );
	}
	$analysis_script = $args[3] ?? null;
	if ( ! is_string( $analysis_script ) || ! is_file( $analysis_script ) ) {
		fake_cc_fail( 'Fixture analysis callback is missing.' );
	}
	$run_index = fake_cc_batch( $db, $batch_name )['runCount'] + 1;
	$trigger_run = is_int( $control['triggerRun'] ?? null ) ? $control['triggerRun'] : 1;
	$triggered = $run_index === $trigger_run;
	foreach ( array( 'NODE_OPTIONS', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_BIND_NOW', 'DYLD_LIBRARY_PATH', 'HTML_API_CC_UNEXPECTED', 'HTML_API_FUZZ_UNEXPECTED' ) as $forbidden ) {
		if ( false !== getenv( $forbidden ) ) {
			fake_cc_fail( "Inherited forbidden environment variable: {$forbidden}" );
		}
	}
	if ( $triggered && 'nonzero' === $mode ) {
		fake_cc_fail( 'Forced fixture nonzero exit.' );
	}
	if ( $triggered && 'timeout' === $mode ) {
		usleep( 5000000 );
	}
	if ( $triggered && 'truncate' === $mode ) {
		fwrite( STDERR, str_repeat( 'x', 2 * 1024 * 1024 ) );
	}

	$output_dir = getenv( 'CC_ANALYZER_OUTPUT_DIR' );
	if ( ! is_string( $output_dir ) || '' === $output_dir ) {
		fake_cc_fail( 'Fixture output directory environment is missing.' );
	}
	file_put_contents( $output_dir . '/observed-environment.json', json_encode( getenv(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
	putenv( 'CC_ANALYZER_MODE=batch' );
	putenv( 'CC_ANALYZER_BATCH=' . $batch_name );
	putenv( 'CC_ANALYZER_MANIFEST=' . $workspace . '/documents.sqlite' );
	$_ENV['CC_ANALYZER_MODE'] = 'batch';
	$_ENV['CC_ANALYZER_BATCH'] = $batch_name;
	$_ENV['CC_ANALYZER_MANIFEST'] = $workspace . '/documents.sqlite';
	$callback = require $analysis_script;
	if ( ! is_callable( $callback ) ) {
		fake_cc_fail( 'Fixture analysis script did not return a callback.' );
	}
	$query = $db->prepare(
		'SELECT c.* FROM document_batch_documents d
		JOIN cached_documents c ON c.input_state_key = d.input_state_key AND c.record_id = d.record_id
		WHERE d.batch_name = :name ORDER BY d.sequence ASC'
	);
	$query->bindValue( ':name', $batch_name, SQLITE3_TEXT );
	$result = $query->execute();
	$documents = array();
	while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
		$documents[] = $row;
	}
	if ( $triggered && 'reorder' === $mode ) {
		$documents = array_reverse( $documents );
	}
	if ( $triggered && 'partial' === $mode ) {
		array_pop( $documents );
	}
	if ( $triggered && 'duplicate' === $mode && ! empty( $documents ) ) {
		array_splice( $documents, 1, 0, array( $documents[0] ) );
	}
	$analyzed = 0;
	foreach ( $documents as $index => $row ) {
		if ( $triggered && 'wrong-body' === $mode && 0 === $index ) {
			$row['body'] .= 'changed';
		}
		$callback( new \CcAnalyzer\Analysis\HtmlAnalysisInput( $row ) );
		++$analyzed;
	}
	if ( $triggered && 'mutate-cache' === $mode ) {
		$db->exec( "UPDATE cached_documents SET body = body || 'x' WHERE rowid = (SELECT MIN(rowid) FROM cached_documents)" );
	}
	if ( $triggered && 'self-change' === $mode ) {
		file_put_contents( __FILE__, "\n", FILE_APPEND );
	}
	if ( $triggered && 'mutate-trust-file' === $mode ) {
		$mutation_path = $control['mutationPath'] ?? null;
		if ( ! is_string( $mutation_path ) || ! is_file( $mutation_path ) || false === file_put_contents( $mutation_path, "\n", FILE_APPEND ) ) {
			fake_cc_fail( 'Could not mutate the requested fixture trust file.' );
		}
	}
	if ( $triggered && 'chmod-trust-file' === $mode ) {
		$mutation_path = $control['mutationPath'] ?? null;
		if ( ! is_string( $mutation_path ) || ! is_file( $mutation_path ) || ! chmod( $mutation_path, 0600 ) ) {
			fake_cc_fail( 'Could not change the requested fixture trust-file mode.' );
		}
	}
	if ( $triggered && 'tamper-previous-evidence' === $mode ) {
		$previous_summary = dirname( $output_dir ) . '/lexbor-source/commoncrawl-summary.ndjson';
		if ( ! is_file( $previous_summary ) || false === file_put_contents( $previous_summary, "{}\n", FILE_APPEND ) ) {
			fake_cc_fail( 'Could not tamper with prior-run evidence.' );
		}
	}
	if ( $triggered && 'block-root-complete' === $mode ) {
		$blocked_marker = dirname( $output_dir ) . '/.complete';
		if ( ! mkdir( $blocked_marker, 0700 ) ) {
			fake_cc_fail( 'Could not block the coordinator root completion marker.' );
		}
	}
	$started = gmdate( 'c' );
	$callback_hash = hash_file( 'sha256', $analysis_script );
	$insert = $db->prepare(
		'INSERT INTO document_batch_runs (batch_name, analysis_script, analysis_script_hash, documents_analyzed, started_at, completed_at)
		VALUES (:batch, :script, :hash, :count, :started, :completed)'
	);
	$insert->bindValue( ':batch', $batch_name, SQLITE3_TEXT );
	$insert->bindValue( ':script', realpath( $analysis_script ), SQLITE3_TEXT );
	$insert->bindValue( ':hash', $triggered && 'wrong-callback-hash' === $mode ? str_repeat( '0', 64 ) : $callback_hash, SQLITE3_TEXT );
	$insert->bindValue( ':count', $triggered && 'wrong-count' === $mode ? $analyzed + 1 : $analyzed, SQLITE3_INTEGER );
	$insert->bindValue( ':started', $started, SQLITE3_TEXT );
	$insert->bindValue( ':completed', gmdate( 'c' ), SQLITE3_TEXT );
	$insert->execute();
	$run_id = $db->lastInsertRowID();
	if ( $triggered && 'malformed-run' === $mode ) {
		echo "{broken run\n";
		exit( 0 );
	}
	$batch = fake_cc_batch( $db, $batch_name );
	$reported_count = $triggered && 'wrong-count' === $mode ? $analyzed + 1 : $analyzed;
	fake_cc_json( array(
		'batch' => $batch,
		'run' => array(
			'batchName' => $batch_name,
			'runId' => $run_id,
			'analysisScript' => realpath( $analysis_script ),
			'analysisScriptHash' => $triggered && 'wrong-callback-hash' === $mode ? str_repeat( '0', 64 ) : $callback_hash,
			'documentsAnalyzed' => $reported_count,
			'startedAt' => $started,
			'completedAt' => gmdate( 'c' ),
		),
		'documentsAnalyzed' => $reported_count,
	) );
}
