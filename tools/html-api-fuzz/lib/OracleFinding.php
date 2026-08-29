<?php
namespace HtmlApiFuzz;

class OracleFinding {
	public static function from_result( array $result ): ?array {
		$mode             = (string) ( $result['mode'] ?? 'unknown' );
		$fragment_context = (string) ( $result['fragmentContext'] ?? 'body' );
		$dom              = is_array( $result['dom'] ?? null ) ? $result['dom'] : array();
		$oracle           = is_array( $result['oracle'] ?? null ) ? $result['oracle'] : ( is_array( $dom['oracle'] ?? null ) ? $dom['oracle'] : array() );
		$oracle_kind      = (string) ( $oracle['kind'] ?? OracleRenderer::KIND_LEXBOR_SOURCE );

		if ( 'oracle-unsupported' === ( $result['status'] ?? null ) || TreeRenderer::STATUS_UNSUPPORTED === ( $dom['status'] ?? null ) ) {
			return self::build(
				'oracle-limitation',
				$oracle_kind . '-unsupported',
				self::oracle_owner( $oracle_kind ),
				'The selected oracle cannot expose this tree shape faithfully.',
				array(
					'mode'               => $mode,
					'fragmentContext'    => $fragment_context,
					'oracleKind'         => $oracle_kind,
					'oracleFailureClass' => $dom['failureClass'] ?? null,
					'unsupportedMessage' => $dom['unsupported']['message'] ?? $dom['error'] ?? null,
					'family'             => $oracle_kind . '-unsupported',
				)
			);
		}

		if ( 'oracle-parse-error' === ( $result['status'] ?? null ) ) {
			return self::build(
				'oracle-limitation',
				$oracle_kind . '-parse-error',
				self::oracle_owner( $oracle_kind ),
				'The selected oracle could not parse the input, so differential coverage was unavailable.',
				array(
					'mode'               => $mode,
					'fragmentContext'    => $fragment_context,
					'oracleKind'         => $oracle_kind,
					'oracleFailureClass' => $dom['failureClass'] ?? null,
					'message'            => Signature::normalize_message_for_finding( $dom['error'] ?? '' ),
					'family'             => $oracle_kind . '-parse-error',
				)
			);
		}

		return null;
	}

	private static function build( string $classification, string $type, string $suspected_owner, string $reason, array $facts, ?array $upstream = null ): array {
		$facts = array_merge(
			array(
				'classification' => $classification,
				'type'           => $type,
			),
			self::without_nulls( $facts )
		);

		return array(
			'schemaVersion'  => 1,
			'kind'           => 'html-api-fuzz-oracle-finding',
			'classification' => $classification,
			'type'           => $type,
			'suspectedOwner' => $suspected_owner,
			'reason'         => $reason,
			'upstream'       => $upstream,
			'signature'      => self::signature( $classification, $type, $facts ),
		);
	}

	private static function oracle_owner( string $oracle_kind ): string {
		if ( OracleRenderer::KIND_LEXBOR_SOURCE === $oracle_kind ) {
			return 'Lexbor source oracle';
		}
		if ( OracleRenderer::KIND_HTML5EVER_SOURCE === $oracle_kind ) {
			return 'html5ever source oracle';
		}
		if ( OracleRenderer::KIND_CHROME_CDP === $oracle_kind ) {
			return 'Chrome for Testing CDP oracle';
		}

		return $oracle_kind;
	}

	private static function signature( string $classification, string $type, array $facts ): array {
		$signature_facts = self::sort_json_value( $facts );
		$hash = 'oracle-' . substr( sha1( json_encode( $signature_facts, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) ), 0, 12 );
		$family_fact = (string) ( $facts['family'] ?? $type );

		return array(
			'hash'             => $hash,
			'equivalenceClass' => $classification,
			'familyKey'        => 'oracle-' . substr( sha1( $classification . ':' . $family_fact ), 0, 12 ),
			'facts'            => $signature_facts,
			'normalized'       => self::normalized_text( $signature_facts ),
		);
	}

	private static function without_nulls( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( null === $item ) {
				unset( $value[ $key ] );
			}
		}

		return $value;
	}

	private static function sort_json_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( __CLASS__, 'sort_json_value' ), $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_json_value( $item );
		}
		ksort( $value, SORT_STRING );

		return $value;
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
