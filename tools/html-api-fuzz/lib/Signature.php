<?php
namespace HtmlApiFuzz;

class Signature {
	public static function from_result( array $result ): ?array {
		if ( $result['ok'] ?? false ) {
			return null;
		}

		$failure_class = $result['failureClass'] ?? 'unknown';
		$facts         = array(
			'failureClass' => $failure_class,
			'mode'         => $result['mode'] ?? 'unknown',
		);

		if ( in_array( $failure_class, array( 'tree-mismatch', 'encoding-mismatch' ), true ) ) {
			$diff = $result['comparison']['firstDifference'] ?? array();
			$facts['treePath']      = $diff['path'] ?? null;
			$facts['wordpressNorm'] = $diff['wordpressNorm'] ?? null;
			$facts['domNorm']       = $diff['domNorm'] ?? null;
		} elseif ( 'tag-invariant-failed' === $failure_class ) {
			$failure = $result['tagProcessor']['failures'][0] ?? array();
			$facts['invariant'] = $failure['name'] ?? 'unknown';
			$facts['throwable'] = $failure['throwable'] ?? null;
		} elseif ( 'normalize-invariant-failed' === $failure_class ) {
			/*
			 * Facts must not include input-derived values (hashes, hex windows,
			 * byte offsets): every distinct failing input would mint a distinct
			 * signature and the watcher would treat one normalize bug as an
			 * unbounded stream of new findings. Forensics stay in result.json.
			 */
			$normalize = $result['tagProcessor']['normalize'] ?? array();
			$failure   = $normalize['failure'] ?? array();
			$facts['invariant']       = $failure['name'] ?? 'normalize-unknown';
			$facts['normalizeStatus'] = $normalize['status'] ?? null;
			$facts['normalizeApi']    = $normalize['api'] ?? null;
			$facts['throwable']       = $normalize['throwable'] ?? $failure['throwable'] ?? null;
			$facts['message']         = self::normalize_message( $failure['message'] ?? '' );
		} elseif ( 'normalize-tree-changed' === $failure_class ) {
			$diff = $result['normalizePreservation']['firstDifference'] ?? array();
			$facts['treePath']      = $diff['path'] ?? null;
			$facts['wordpressNorm'] = $diff['wordpressNorm'] ?? null;
			$facts['domNorm']       = $diff['domNorm'] ?? null;
		} elseif ( in_array( $failure_class, array( 'mutation-tree-mismatch', 'mutation-delta-mismatch' ), true ) ) {
			$diff = $result['mutation']['firstDifference'] ?? array();
			$facts['treePath']      = $diff['path'] ?? null;
			$facts['wordpressNorm'] = $diff['wordpressNorm'] ?? null;
			$facts['domNorm']       = $diff['domNorm'] ?? null;
		} elseif ( 'breadcrumb-mismatch' === $failure_class ) {
			$breadcrumbs = $result['wordpress']['breadcrumbs'] ?? array();
			$divergence  = $breadcrumbs['divergenceDepth'] ?? null;
			$facts['kind']            = $breadcrumbs['kind'] ?? null;
			$facts['divergenceDepth'] = $divergence;
			$facts['expectedAt']      = null === $divergence ? null : ( $breadcrumbs['expected'][ $divergence ] ?? null );
			$facts['actualAt']        = null === $divergence ? null : ( $breadcrumbs['actual'][ $divergence ] ?? null );
		} elseif ( 'resource-limit' === $failure_class ) {
			$limit_failures = self::resource_limit_failures( $result );
			$limit_failures = array_values( array_unique( $limit_failures ) );
			sort( $limit_failures );
			$facts['invariant']     = $limit_failures[0] ?? 'resource-limit';
			$facts['limitFailures'] = $limit_failures;
			$facts['tokenCount']    = $result['tagProcessor']['tokenCount'] ?? $result['wordpress']['tokenCount'] ?? null;
			$facts['nodeCount']     = $result['dom']['nodeCount'] ?? null;
			$facts['tagTokenCount'] = $result['tagProcessor']['tokenCount'] ?? null;
			$facts['wordpressTokenCount'] = $result['wordpress']['tokenCount'] ?? null;
			$facts['domNodeCount']        = $result['dom']['nodeCount'] ?? null;
		} elseif ( 'unsupported' === $failure_class ) {
			$unsupported = $result['wordpress']['unsupported'] ?? array();
			$facts['unsupportedMessage'] = $unsupported['message'] ?? null;
			$facts['unsupportedToken']   = $unsupported['tokenName'] ?? null;
		} elseif ( in_array( $failure_class, array( 'fatal-error', 'oracle-parse-error' ), true ) ) {
			$source = ( ( $result['wordpress']['status'] ?? '' ) === 'error' )
				? ( $result['wordpress'] ?? array() )
				: ( $result['dom'] ?? array() );
			$facts['throwable'] = $source['throwable'] ?? null;
			$facts['message']   = self::normalize_message( $source['error'] ?? $result['failureSnippet'] ?? '' );
		} else {
			$facts['message'] = self::normalize_message( $result['failureSnippet'] ?? $result['status'] ?? '' );
		}

		$hash = substr( sha1( json_encode( $facts, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) ), 0, 12 );

		/*
		 * The family key clusters likely-same-root-cause findings. Prefer the
		 * masked line pair over the tree path: paths embed generated element
		 * names and spread one bug across many families.
		 */
		if ( isset( $facts['wordpressNorm'] ) || isset( $facts['domNorm'] ) ) {
			$family_fact = ( $facts['wordpressNorm'] ?? '' ) . '|' . ( $facts['domNorm'] ?? '' );
		} else {
			$family_fact = $facts['invariant'] ?? $facts['unsupportedMessage'] ?? $facts['kind'] ?? '';
		}

		return array(
			'hash'             => $hash,
			'equivalenceClass' => $failure_class,
			'familyKey'        => substr( sha1( $failure_class . ':' . $family_fact ), 0, 12 ),
			'facts'            => $facts,
			'normalized'       => self::normalized_text( $facts ),
		);
	}

	private static function normalize_message( string $message ): string {
		$message = preg_replace( '/\/[^ \n]+/', '<path>', $message );
		$message = preg_replace( '/\d+/', '<n>', (string) $message );
		return trim( $message );
	}

	public static function normalize_message_for_finding( string $message ): string {
		return self::normalize_message( $message );
	}

	private static function resource_limit_failures( array $result ): array {
		$limit_failures = array();
		foreach ( $result['tagProcessor']['failures'] ?? array() as $failure ) {
			$name = $failure['name'] ?? null;
			if ( is_string( $name ) ) {
				$limit_failures[] = $name;
			}
		}

		foreach ( array( 'wordpress', 'dom' ) as $source ) {
			$failure_class = $result[ $source ]['failureClass'] ?? null;
			if ( in_array( $failure_class, array( 'token-limit-exceeded', 'node-limit-exceeded' ), true ) ) {
				$limit_failures[] = $source . '-' . $failure_class;
			}
		}

		return $limit_failures;
	}

	private static function normalized_text( array $facts ): string {
		$parts = array();
		foreach ( $facts as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
			}
			$parts[] = "{$key}={$value}";
		}
		return implode( "\n", $parts );
	}
}
