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
				summary_json TEXT,
				result_json TEXT,
				replay_json TEXT
			)'
		);
		$this->db->exec( 'PRAGMA user_version = 1' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_signature_hash ON attempts ( signature_hash )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_family_key ON attempts ( family_key )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_ok ON attempts ( ok )' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS attempts_seed ON attempts ( seed )' );
	}

	/**
	 * Records one attempt. Passing attempts store summary columns only; the
	 * attempt is regenerable from its seed. Failures additionally store the
	 * summary, result, and replay JSON documents.
	 */
	public function record_attempt( array $summary, ?array $result = null, ?array $replay = null ): int {
		$ok        = (bool) ( $summary['ok'] ?? false );
		$statement = $this->db->prepare(
			'INSERT INTO attempts (
				created_at, seed, ok, status, failure_class, signature_hash, family_key,
				profile, mode, payload_policy, input_source, input_sha1, input_length,
				duration_ms, worker_code, worker_timed_out, artifacts_retained,
				summary_json, result_json, replay_json
			) VALUES (
				:created_at, :seed, :ok, :status, :failure_class, :signature_hash, :family_key,
				:profile, :mode, :payload_policy, :input_source, :input_sha1, :input_length,
				:duration_ms, :worker_code, :worker_timed_out, :artifacts_retained,
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
		$statement->bindValue( ':profile', $summary['profile'] ?? null, null === ( $summary['profile'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':mode', $summary['mode'] ?? null, null === ( $summary['mode'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':payload_policy', $summary['payloadPolicy'] ?? null, null === ( $summary['payloadPolicy'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':input_source', $summary['inputSource'] ?? null, null === ( $summary['inputSource'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':input_sha1', $summary['inputSha1'] ?? null, null === ( $summary['inputSha1'] ?? null ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':input_length', $summary['inputLength'] ?? null, null === ( $summary['inputLength'] ?? null ) ? SQLITE3_NULL : SQLITE3_INTEGER );
		$statement->bindValue( ':duration_ms', $summary['durationMs'] ?? null, null === ( $summary['durationMs'] ?? null ) ? SQLITE3_NULL : SQLITE3_INTEGER );
		$statement->bindValue( ':worker_code', $summary['workerCode'] ?? null, null === ( $summary['workerCode'] ?? null ) ? SQLITE3_NULL : SQLITE3_INTEGER );
		$statement->bindValue( ':worker_timed_out', ( $summary['workerTimedOut'] ?? false ) ? 1 : 0, SQLITE3_INTEGER );
		$statement->bindValue( ':artifacts_retained', ( $summary['artifactsRetained'] ?? false ) ? 1 : 0, SQLITE3_INTEGER );
		$statement->bindValue( ':summary_json', $ok ? null : $encode( $summary ), $ok ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':result_json', $ok ? null : $encode( $result ), ( $ok || null === $result ) ? SQLITE3_NULL : SQLITE3_TEXT );
		$statement->bindValue( ':replay_json', $ok ? null : $encode( $replay ), ( $ok || null === $replay ) ? SQLITE3_NULL : SQLITE3_TEXT );
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
		$statement = $this->db->prepare( 'SELECT DISTINCT seed FROM attempts WHERE signature_hash = :hash AND artifacts_retained = 1' );
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

	public function count_attempts(): int {
		return (int) $this->db->querySingle( 'SELECT COUNT(*) FROM attempts' );
	}

	public function close(): void {
		$this->db->close();
	}
}
