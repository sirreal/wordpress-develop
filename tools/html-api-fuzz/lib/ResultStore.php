<?php
namespace HtmlApiFuzz;

/**
 * Per-lane SQLite store of one row per attempted seed.
 *
 * Replaces the append-only summary.ndjson stream and the per-seed artifact
 * directories for attempts whose artifacts are not retained on disk. Failure
 * rows carry the full result and replay JSON (the replay embeds the input as
 * base64), so a failure remains reproducible after its seed directory is
 * pruned.
 *
 * The scalar columns are denormalized copies of summary fields for direct SQL
 * querying (camelCase JSON keys become snake_case columns); the JSON
 * documents are what programmatic consumers read and are authoritative when
 * the two disagree.
 */
class ResultStore {
	public const FILENAME = 'results.sqlite';

	/** @var \SQLite3 */
	private $db;

	public function __construct( string $path, bool $read_only = false ) {
		$flags    = $read_only ? SQLITE3_OPEN_READONLY : ( SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE );
		$this->db = new \SQLite3( $path, $flags );
		$this->db->busyTimeout( 5000 );
		$this->db->enableExceptions( true );
		if ( ! $read_only ) {
			$this->db->exec( 'PRAGMA journal_mode = WAL' );
			$this->db->exec( 'PRAGMA synchronous = NORMAL' );
			$this->create_schema();
		}
	}

	private function create_schema(): void {
		$this->db->exec(
			'CREATE TABLE IF NOT EXISTS attempts (
				id INTEGER PRIMARY KEY,
				created_at TEXT NOT NULL,
				seed INTEGER NOT NULL,
				ok INTEGER NOT NULL,
				status TEXT NOT NULL,
				failure_class TEXT,
				signature_hash TEXT,
				family_key TEXT,
				oracle_finding_class TEXT,
				oracle_finding_type TEXT,
				oracle_suspected_owner TEXT,
				oracle_signature_hash TEXT,
				oracle_family_key TEXT,
				oracle_kind TEXT,
				oracle_version TEXT,
				oracle_commit TEXT,
				oracle_binary TEXT,
				oracle_browser_pid INTEGER,
				profile TEXT,
				mode TEXT,
				payload_policy TEXT,
				input_source TEXT,
				input_sha1 TEXT,
				input_length INTEGER,
				duration_ms INTEGER,
				worker_code INTEGER,
				worker_timed_out INTEGER NOT NULL DEFAULT 0,
				artifacts_retained INTEGER NOT NULL DEFAULT 0,
				failure_artifacts_retained INTEGER,
				oracle_artifacts_retained INTEGER,
				summary_json TEXT,
				result_json TEXT,
				replay_json TEXT
			)'
		);
		$this->ensure_column( 'attempts', 'oracle_finding_class', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_finding_type', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_suspected_owner', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_signature_hash', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_family_key', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_kind', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_version', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_commit', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_binary', 'TEXT' );
		$this->ensure_column( 'attempts', 'oracle_browser_pid', 'INTEGER' );
		$this->ensure_column( 'attempts', 'failure_artifacts_retained', 'INTEGER' );
		$this->ensure_column( 'attempts', 'oracle_artifacts_retained', 'INTEGER' );
		if ( (int) $this->db->querySingle( 'PRAGMA user_version' ) < 3 ) {
			$this->db->exec( 'PRAGMA user_version = 3' );
		}
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_signature_hash ON attempts ( signature_hash )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_family_key ON attempts ( family_key )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_oracle_signature_hash ON attempts ( oracle_signature_hash )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_oracle_family_key ON attempts ( oracle_family_key )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_oracle_kind ON attempts ( oracle_kind )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_ok ON attempts ( ok )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_seed ON attempts ( seed )' );
	}

	private function ensure_column( string $table, string $column, string $definition ): void {
		if ( $this->has_column( $table, $column ) ) {
			return;
		}

		$this->db->exec( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
	}

	private function has_column( string $table, string $column ): bool {
		$result = $this->db->query( 'PRAGMA table_info(' . $table . ')' );
		while ( false !== ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) ) {
			if ( $column === ( $row['name'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Records one attempt. Passing attempts store summary columns only; the
	 * attempt is regenerable from its seed. Failures additionally store the
	 * summary, result, and replay JSON documents.
	 */
	public function record_attempt( array $summary, ?array $result = null, ?array $replay = null ): int {
		$ok             = (bool) ( $summary['ok'] ?? false );
		$oracle_finding = is_array( $summary['oracleFinding'] ?? null ) ? $summary['oracleFinding'] : null;
		$oracle         = is_array( $summary['oracle'] ?? null ) ? $summary['oracle'] : null;
		$store_json     = ! $ok || null !== $oracle_finding;
		$artifacts_retained = (bool) ( $summary['artifactsRetained'] ?? false );
		$failure_artifacts_retained = array_key_exists( 'failureArtifactsRetained', $summary )
			? (bool) $summary['failureArtifactsRetained']
			: ( ! $ok && $artifacts_retained );
		$oracle_artifacts_retained = array_key_exists( 'oracleArtifactsRetained', $summary )
			? (bool) $summary['oracleArtifactsRetained']
			: ( null !== $oracle_finding && $artifacts_retained );
		$statement = $this->db->prepare(
			'INSERT INTO attempts (
				created_at, seed, ok, status, failure_class, signature_hash, family_key,
				oracle_finding_class, oracle_finding_type, oracle_suspected_owner, oracle_signature_hash, oracle_family_key,
				oracle_kind, oracle_version, oracle_commit, oracle_binary, oracle_browser_pid,
				profile, mode, payload_policy, input_source, input_sha1, input_length,
				duration_ms, worker_code, worker_timed_out, artifacts_retained,
				failure_artifacts_retained, oracle_artifacts_retained,
				summary_json, result_json, replay_json
			) VALUES (
				:created_at, :seed, :ok, :status, :failure_class, :signature_hash, :family_key,
				:oracle_finding_class, :oracle_finding_type, :oracle_suspected_owner, :oracle_signature_hash, :oracle_family_key,
				:oracle_kind, :oracle_version, :oracle_commit, :oracle_binary, :oracle_browser_pid,
				:profile, :mode, :payload_policy, :input_source, :input_sha1, :input_length,
				:duration_ms, :worker_code, :worker_timed_out, :artifacts_retained,
				:failure_artifacts_retained, :oracle_artifacts_retained,
				:summary_json, :result_json, :replay_json
			)'
		);

		// A row must never become invisible to triage because one value could
		// not be encoded; fall back to a minimal document instead.
		$encode = static function ( $value ) use ( $summary ): ?string {
			if ( null === $value ) {
				return null;
			}
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
			if ( false !== $json ) {
				return $json;
			}
			return json_encode(
				array(
					'kind'        => 'html-api-fuzz-encode-fallback',
					'encodeError' => json_last_error_msg(),
					'ok'          => (bool) ( $summary['ok'] ?? false ),
					'seed'        => (int) ( $summary['seed'] ?? 0 ),
					'status'      => (string) ( $summary['status'] ?? 'unknown' ),
					'signature'   => array( 'hash' => $summary['signature']['hash'] ?? null ),
					'oracleFinding' => array(
						'classification' => $summary['oracleFinding']['classification'] ?? null,
						'type'           => $summary['oracleFinding']['type'] ?? null,
						'suspectedOwner' => $summary['oracleFinding']['suspectedOwner'] ?? null,
						'signature'      => array(
							'hash'      => $summary['oracleFinding']['signature']['hash'] ?? null,
							'familyKey' => $summary['oracleFinding']['signature']['familyKey'] ?? null,
						),
					),
				),
				JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
			);
		};

		$statement->bindValue( ':created_at', gmdate( 'c' ), SQLITE3_TEXT );
		$statement->bindValue( ':seed', (int) ( $summary['seed'] ?? 0 ), SQLITE3_INTEGER );
		$statement->bindValue( ':ok', $ok ? 1 : 0, SQLITE3_INTEGER );
		$statement->bindValue( ':status', (string) ( $summary['status'] ?? 'unknown' ), SQLITE3_TEXT );
		$statement->bindValue( ':failure_class', $summary['failureClass'] ?? null, null === ( $summary['failureClass'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$signature_hash = $summary['signature']['hash'] ?? null;
		$statement->bindValue( ':signature_hash', $signature_hash, null === $signature_hash ? SQLITE3_NULL : SQLITE3_TEXT );
		$family_key = $summary['signature']['familyKey'] ?? null;
		$statement->bindValue( ':family_key', $family_key, null === $family_key ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_finding_class = $oracle_finding['classification'] ?? null;
		$statement->bindValue( ':oracle_finding_class', $oracle_finding_class, null === $oracle_finding_class ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_finding_type = $oracle_finding['type'] ?? null;
		$statement->bindValue( ':oracle_finding_type', $oracle_finding_type, null === $oracle_finding_type ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_suspected_owner = $oracle_finding['suspectedOwner'] ?? null;
		$statement->bindValue( ':oracle_suspected_owner', $oracle_suspected_owner, null === $oracle_suspected_owner ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_signature_hash = $oracle_finding['signature']['hash'] ?? null;
		$statement->bindValue( ':oracle_signature_hash', $oracle_signature_hash, null === $oracle_signature_hash ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_family_key = $oracle_finding['signature']['familyKey'] ?? null;
		$statement->bindValue( ':oracle_family_key', $oracle_family_key, null === $oracle_family_key ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_kind = $oracle['kind'] ?? null;
		$statement->bindValue( ':oracle_kind', $oracle_kind, null === $oracle_kind ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_version = $oracle['browserVersion'] ?? $oracle['html5everVersion'] ?? $oracle['lexborVersion'] ?? null;
		$statement->bindValue( ':oracle_version', $oracle_version, null === $oracle_version ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_commit = $oracle['html5everCommit'] ?? $oracle['html5everChecksum'] ?? $oracle['lexborCommit'] ?? null;
		$statement->bindValue( ':oracle_commit', $oracle_commit, null === $oracle_commit ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_binary = $oracle['chromeExecutable'] ?? $oracle['binary'] ?? null;
		$statement->bindValue( ':oracle_binary', $oracle_binary, null === $oracle_binary ? SQLITE3_NULL : SQLITE3_TEXT );
		$oracle_browser_pid = is_int( $oracle['browserPid'] ?? null ) ? $oracle['browserPid'] : null;
		$statement->bindValue( ':oracle_browser_pid', $oracle_browser_pid, null === $oracle_browser_pid ? SQLITE3_NULL : SQLITE3_INTEGER );
		$statement->bindValue( ':profile', $summary['profile'] ?? null, null === ( $summary['profile'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':mode', $summary['mode'] ?? null, null === ( $summary['mode'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':payload_policy', $summary['payloadPolicy'] ?? null, null === ( $summary['payloadPolicy'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':input_source', $summary['inputSource'] ?? null, null === ( $summary['inputSource'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':input_sha1', $summary['inputSha1'] ?? null, null === ( $summary['inputSha1'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':input_length', $summary['inputLength'] ?? null, null === ( $summary['inputLength'] ?? null ) ? SQLITE3_NULL : SQLITE3_INTEGER );
		$statement->bindValue( ':duration_ms', $summary['durationMs'] ?? null, null === ( $summary['durationMs'] ?? null ) ? SQLITE3_NULL : SQLITE3_INTEGER );
		$statement->bindValue( ':worker_code', $summary['workerCode'] ?? null, null === ( $summary['workerCode'] ?? null ) ? SQLITE3_NULL : SQLITE3_INTEGER );
		$statement->bindValue( ':worker_timed_out', ( $summary['workerTimedOut'] ?? false ) ? 1 : 0, SQLITE3_INTEGER );
		$statement->bindValue( ':artifacts_retained', $artifacts_retained ? 1 : 0, SQLITE3_INTEGER );
		$statement->bindValue( ':failure_artifacts_retained', $failure_artifacts_retained ? 1 : 0, SQLITE3_INTEGER );
		$statement->bindValue( ':oracle_artifacts_retained', $oracle_artifacts_retained ? 1 : 0, SQLITE3_INTEGER );
		$statement->bindValue( ':summary_json', $store_json ? $encode( $summary ) : null, $store_json ? SQLITE3_TEXT : SQLITE3_NULL );
		$statement->bindValue( ':result_json', $store_json ? $encode( $result ) : null, ( ! $store_json || null === $result ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':replay_json', $store_json ? $encode( $replay ) : null, ( ! $store_json || null === $replay ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->execute();
		$statement->close();

		return $this->db->lastInsertRowID();
	}

	/**
	 * Distinct seeds for a signature whose artifact directories were retained.
	 * The caller checks which of those directories still exist on disk, so the
	 * exemplar cap survives runner restarts that re-record the same seeds.
	 */
	public function retained_seeds( string $signature_hash ): array {
		$retained_column = $this->has_column( 'attempts', 'failure_artifacts_retained' )
			? 'COALESCE(failure_artifacts_retained, artifacts_retained)'
			: 'artifacts_retained';
		$statement = $this->db->prepare( "SELECT DISTINCT seed FROM attempts WHERE signature_hash = :hash AND {$retained_column} = 1" );
		$statement->bindValue( ':hash', $signature_hash, SQLITE3_TEXT );
		$result = $statement->execute();

		$seeds = array();
		while ( false !== ( $row = $result->fetchArray( SQLITE3_NUM ) ) ) {
			$seeds[] = (int) $row[0];
		}
		$statement->close();

		return $seeds;
	}

	public function oracle_retained_seeds( string $signature_hash ): array {
		if ( ! $this->has_column( 'attempts', 'oracle_signature_hash' ) ) {
			return array();
		}

		$retained_column = $this->has_column( 'attempts', 'oracle_artifacts_retained' )
			? 'COALESCE(oracle_artifacts_retained, artifacts_retained)'
			: 'artifacts_retained';
		$statement = $this->db->prepare( "SELECT DISTINCT seed FROM attempts WHERE oracle_signature_hash = :hash AND {$retained_column} = 1" );
		$statement->bindValue( ':hash', $signature_hash, SQLITE3_TEXT );
		$result = $statement->execute();

		$seeds = array();
		while ( false !== ( $row = $result->fetchArray( SQLITE3_NUM ) ) ) {
			$seeds[] = (int) $row[0];
		}
		$statement->close();

		return $seeds;
	}

	/**
	 * Whether any recorded attempt for this seed retained its artifact
	 * directory. Guards re-runs of a previously retained seed from deleting
	 * the exemplar directory the earlier row points at.
	 */
	public function seed_artifacts_retained( int $seed ): bool {
		$statement = $this->db->prepare( 'SELECT 1 FROM attempts WHERE seed = :seed AND artifacts_retained = 1 LIMIT 1' );
		$statement->bindValue( ':seed', $seed, SQLITE3_INTEGER );
		$result = $statement->execute();
		$row    = $result->fetchArray( SQLITE3_NUM );
		$statement->close();

		return false !== $row && null !== $row;
	}

	/**
	 * The most recent stored replay document for a seed, for reproducing a
	 * failure whose artifact directory was pruned.
	 */
	public function replay_for_seed( int $seed ): ?array {
		$statement = $this->db->prepare( 'SELECT replay_json FROM attempts WHERE seed = :seed AND replay_json IS NOT NULL ORDER BY id DESC LIMIT 1' );
		$statement->bindValue( ':seed', $seed, SQLITE3_INTEGER );
		$result = $statement->execute();
		$row    = $result->fetchArray( SQLITE3_NUM );
		$statement->close();

		if ( false === $row || ! is_string( $row[0] ?? null ) ) {
			return null;
		}
		$replay = json_decode( $row[0], true );

		return is_array( $replay ) ? $replay : null;
	}

	public function replay_for_attempt_id( int $id ): ?array {
		$statement = $this->db->prepare( 'SELECT replay_json FROM attempts WHERE id = :id AND replay_json IS NOT NULL LIMIT 1' );
		$statement->bindValue( ':id', $id, SQLITE3_INTEGER );
		$result = $statement->execute();
		$row    = $result->fetchArray( SQLITE3_NUM );
		$statement->close();

		if ( false === $row || ! is_string( $row[0] ?? null ) ) {
			return null;
		}
		$replay = json_decode( $row[0], true );

		return is_array( $replay ) ? $replay : null;
	}

	public function update_replay_for_attempt( int $id, array $replay ): void {
		$json = json_encode( $replay, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			throw new \RuntimeException( 'Could not encode replay JSON: ' . json_last_error_msg() );
		}

		$statement = $this->db->prepare( 'UPDATE attempts SET replay_json = :replay_json WHERE id = :id' );
		$statement->bindValue( ':replay_json', $json, SQLITE3_TEXT );
		$statement->bindValue( ':id', $id, SQLITE3_INTEGER );
		$statement->execute();
		$statement->close();
	}

	public function max_id(): int {
		$row = $this->db->querySingle( 'SELECT MAX(id) FROM attempts' );

		return (int) $row;
	}

	/**
	 * Failure rows in the id range (after_id, up_to_id], oldest first. Each
	 * entry carries the row id and the decoded summary record.
	 */
	public function failures_after( int $after_id, int $up_to_id ): array {
		$statement = $this->db->prepare(
			'SELECT id, summary_json FROM attempts WHERE id > :after AND id <= :up_to AND ok = 0 ORDER BY id'
		);
		$statement->bindValue( ':after', $after_id, SQLITE3_INTEGER );
		$statement->bindValue( ':up_to', $up_to_id, SQLITE3_INTEGER );
		$result = $statement->execute();

		$rows = array();
		while ( false !== ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) ) {
			$record = null === $row['summary_json'] ? null : json_decode( $row['summary_json'], true );
			if ( ! is_array( $record ) ) {
				continue;
			}
			$rows[] = array(
				'id'     => (int) $row['id'],
				'record' => $record,
			);
		}
		$statement->close();

		return $rows;
	}

	public function oracle_findings_after( int $after_id, int $up_to_id ): array {
		if ( ! $this->has_column( 'attempts', 'oracle_signature_hash' ) ) {
			return array();
		}

		$statement = $this->db->prepare(
			'SELECT id, summary_json FROM attempts WHERE id > :after AND id <= :up_to AND oracle_signature_hash IS NOT NULL ORDER BY id'
		);
		$statement->bindValue( ':after', $after_id, SQLITE3_INTEGER );
		$statement->bindValue( ':up_to', $up_to_id, SQLITE3_INTEGER );
		$result = $statement->execute();

		$rows = array();
		while ( false !== ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) ) {
			$record = null === $row['summary_json'] ? null : json_decode( $row['summary_json'], true );
			if ( ! is_array( $record ) ) {
				continue;
			}
			$rows[] = array(
				'id'     => (int) $row['id'],
				'record' => $record,
			);
		}
		$statement->close();

		return $rows;
	}

	public function count_attempts(): int {
		return (int) $this->db->querySingle( 'SELECT COUNT(*) FROM attempts' );
	}

	public function close(): void {
		$this->db->close();
	}
}
