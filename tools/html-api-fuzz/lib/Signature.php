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

		return array(
			'hash'             => $hash,
			'equivalenceClass' => $failure_class,
			'familyKey'        => substr( sha1( $failure_class . ':' . ( $facts['treePath'] ?? $facts['invariant'] ?? $facts['unsupportedMessage'] ?? '' ) ), 0, 12 ),
			'facts'            => $facts,
			'normalized'       => self::normalized_text( $facts ),
		);
	}

	private static function normalize_message( string $message ): string {
		$message = preg_replace( '/\/[^ \n]+/', '<path>', $message );
		$message = preg_replace( '/\d+/', '<n>', (string) $message );
		return trim( $message );
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
