<?php
namespace HtmlApiFuzz;

class OracleFinding {
	private const ISSUE_REGISTRY = array(
		'dom-xlink-dropped-local-name-after-xlink' => array(
			'issue'   => 'lexbor/lexbor#372',
			'issueUrl' => 'https://github.com/lexbor/lexbor/issues/372',
			'fixedBy' => 'https://github.com/lexbor/lexbor/commit/445c0a20b171533b4be762e18b10d359556eb68c',
		),
		'dom-mathml-heading-scope-reparenting' => array(
			'issue'   => 'lexbor/lexbor#373',
			'issueUrl' => 'https://github.com/lexbor/lexbor/issues/373',
			'fixedBy' => 'https://github.com/lexbor/lexbor/commit/481c444261a132190a3fb746d6d2f60824af3717',
		),
	);

	public static function from_result( array $result ): ?array {
		$mode             = (string) ( $result['mode'] ?? 'unknown' );
		$fragment_context = (string) ( $result['fragmentContext'] ?? 'body' );
		$comparison       = is_array( $result['comparison'] ?? null ) ? $result['comparison'] : array();
		$dom              = is_array( $result['dom'] ?? null ) ? $result['dom'] : array();

		if ( is_string( $comparison['oracleFindingType'] ?? null ) ) {
			return self::from_type( $comparison['oracleFindingType'], $mode, $fragment_context, $comparison['firstDifference'] ?? array() );
		}

		if ( 'oracle-unsupported' === ( $result['status'] ?? null ) || TreeRenderer::STATUS_UNSUPPORTED === ( $dom['status'] ?? null ) ) {
			return self::build(
				'oracle-limitation',
				'dom-template-context-unsupported',
				'PHP DOM API',
				'The DOM oracle cannot expose this tree shape faithfully.',
				array(
					'mode'               => $mode,
					'fragmentContext'    => $fragment_context,
					'domFailureClass'    => $dom['failureClass'] ?? null,
					'unsupportedMessage' => $dom['unsupported']['message'] ?? $dom['error'] ?? null,
					'family'             => 'dom-template-context-unsupported',
				)
			);
		}

		if ( 'oracle-parse-error' === ( $result['status'] ?? null ) ) {
			return self::build(
				'oracle-limitation',
				'dom-parse-error',
				'Lexbor/PHP DOM',
				'The DOM oracle could not parse the input, so differential coverage was unavailable.',
				array(
					'mode'            => $mode,
					'fragmentContext' => $fragment_context,
					'domFailureClass' => $dom['failureClass'] ?? null,
					'message'         => Signature::normalize_message_for_finding( $dom['error'] ?? '' ),
					'family'          => 'dom-parse-error',
				)
			);
		}

		$dom_oracle_line_tolerances = $result['wordpress']['domOracleLineTolerances'] ?? array();
		if ( true === ( $comparison['ok'] ?? null ) && is_array( $dom_oracle_line_tolerances ) && ! empty( $dom_oracle_line_tolerances ) ) {
			return self::from_type(
				'dom-xlink-dropped-local-name-after-xlink',
				$mode,
				$fragment_context,
				array(
					'toleratedLineCount' => count( $dom_oracle_line_tolerances ),
				)
			);
		}

		if ( ! empty( $comparison['scalarToleratedLines'] ) ) {
			return self::build(
				'scalar-tolerance',
				'scalar-substitution-tolerance',
				'WordPress HTML API scalar policy',
				'WordPress deliberately preserves raw scalar bytes that spec-following parsers substitute during preprocessing.',
				array(
					'mode'               => $mode,
					'fragmentContext'    => $fragment_context,
					'toleratedLineCount' => count( (array) $comparison['scalarToleratedLines'] ),
					'family'             => 'scalar-substitution-tolerance',
				)
			);
		}

		return null;
	}

	private static function from_type( string $type, string $mode, string $fragment_context, array $details = array() ): ?array {
		if ( 'dom-form-feed-pre-body-whitespace' === $type ) {
			return self::build(
				'oracle-bug',
				$type,
				'Lexbor/PHP DOM',
				'The DOM oracle mishandles form-feed whitespace before body content.',
				array(
					'mode'            => $mode,
					'fragmentContext' => $fragment_context,
					'family'          => $type,
				)
			);
		}

		if ( 'dom-xlink-dropped-local-name-after-xlink' === $type ) {
			return self::build(
				'oracle-bug',
				$type,
				'Lexbor/PHP DOM',
				'The DOM oracle drops a bare SVG/XLink local-name attribute after the namespaced attribute appears first.',
				array(
					'mode'               => $mode,
					'fragmentContext'    => $fragment_context,
					'toleratedLineCount' => $details['toleratedLineCount'] ?? null,
					'family'             => $type,
				),
				self::ISSUE_REGISTRY[ $type ] ?? null
			);
		}

		if ( 'dom-mathml-heading-scope-reparenting' === $type ) {
			$wordpress_path = is_string( $details['wordpressPath'] ?? null ) ? self::path_pattern( $details['wordpressPath'] ) : null;
			$dom_path       = is_string( $details['domPath'] ?? null ) ? self::path_pattern( $details['domPath'] ) : null;
			return self::build(
				'oracle-bug',
				$type,
				'Lexbor/PHP DOM',
				'The DOM oracle reparents content after an ignored heading end tag inside a MathML text integration point.',
				array(
					'mode'            => $mode,
					'fragmentContext' => $fragment_context,
					'wordpressNorm'   => $details['wordpressNorm'] ?? null,
					'domNorm'         => $details['domNorm'] ?? null,
					'wordpressPath'   => $wordpress_path,
					'domPath'         => $dom_path,
					'family'          => $type,
				),
				self::ISSUE_REGISTRY[ $type ] ?? null
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

	private static function path_pattern( string $path ): string {
		$parts = explode( '/', strtolower( $path ) );
		foreach ( $parts as &$part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( ! in_array( $part, array( 'html', 'head', 'body', 'template', 'content', 'math math', 'math annotation-xml', 'svg svg', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'area' ), true ) ) {
				$part = '*';
			}
		}
		unset( $part );

		return implode( '/', $parts );
	}
}
