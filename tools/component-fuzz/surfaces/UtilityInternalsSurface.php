<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes low-level utility classes that underpin list handling, token lookups, and URL pattern helpers.
 */
final class UtilityInternalsSurface {
	public const NAME = 'utility-internals';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_optional_classes();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'utility-internals.bootstrap-apis-available',
					'Required utility internals are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_list_util_filter_pluck_sort( $ctx->fork( 'list' ) );
			$rows[] = self::check_list_util_chained_state( $ctx->fork( 'list-chain' ) );
			$rows[] = self::check_token_map_lookup_and_precompute( $ctx->fork( 'token-map' ) );
			$rows[] = self::check_matches_map_regex( $ctx->fork( 'matches' ) );
			$rows[] = self::check_url_pattern_prefixer( $ctx->fork( 'prefixer' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'utility-internals.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$state_restored = self::state_matches( $snapshot );
		$rows[]         = self::row(
			$ctx,
			'utility-internals.state-restored',
			$state_restored,
			array( 'restored' => $state_restored )
		);

		return $rows;
	}

	private static function load_optional_classes(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach (
			array(
				'class-wp-list-util.php',
				'class-wp-matchesmapregex.php',
				'class-wp-token-map.php',
				'class-wp-url-pattern-prefixer.php',
			) as $file
		) {
			$path = ABSPATH . WPINC . '/' . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach ( array( 'WP_List_Util', 'WP_MatchesMapRegex', 'WP_Token_Map', 'WP_URL_Pattern_Prefixer' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'trailingslashit',
				'wp_filter_object_list',
				'wp_list_filter',
				'wp_list_pluck',
				'wp_list_sort',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_list_util_filter_pluck_sort( \ComponentFuzz\FuzzContext $ctx ): array {
		$list     = self::list_fixture( $ctx );
		$args     = array(
			'type'   => $ctx->choice( array( 'post', 'page', 'nav', 'media' ) ),
			'active' => $ctx->choice( array( '0', '1' ) ),
		);
		$operator = $ctx->choice( array( 'AND', 'OR', 'NOT', 'invalid' ) );
		$orderby  = array(
			'type'  => 'ASC',
			'score' => $ctx->choice( array( 'ASC', 'DESC' ) ),
			'id'    => 'ASC',
		);

		$util            = new \WP_List_Util( $list );
		$filtered        = $util->filter( $args, $operator );
		$expected_filter = self::reference_filter( $list, $args, $operator );

		$pluck          = \wp_list_pluck( $list, 'label', 'id' );
		$expected_pluck = self::reference_pluck( $list, 'label', 'id' );

		$sorted          = \wp_list_sort( $list, $orderby, 'ASC', true );
		$expected_sorted = self::reference_sort( $list, $orderby, true );

		$field_filter          = \wp_filter_object_list( $list, array( 'active' => '1' ), 'AND', 'label' );
		$expected_field_filter = self::reference_pluck( self::reference_filter( $list, array( 'active' => '1' ), 'AND' ), 'label' );

		$failures = array();
		self::collect_failure(
			$failures,
			self::same_list_values( $expected_filter, $filtered ),
			'WP_List_Util::filter agrees with loose array/object reference semantics',
			array(
				'args'     => $args,
				'operator' => $operator,
				'actual'   => self::summarize_list( $filtered ),
				'expected' => self::summarize_list( $expected_filter ),
			)
		);
		self::collect_failure(
			$failures,
			$expected_pluck === $pluck,
			'wp_list_pluck preserves requested index keys and values',
			array(
				'actual'   => $pluck,
				'expected' => $expected_pluck,
			)
		);
		self::collect_failure(
			$failures,
			array_keys( $expected_sorted ) === array_keys( $sorted )
				&& self::same_list_values( $expected_sorted, $sorted ),
			'wp_list_sort matches numeric/string multi-key ordering and preserves keys',
			array(
				'orderby'      => $orderby,
				'actualKeys'   => array_keys( $sorted ),
				'expectedKeys' => array_keys( $expected_sorted ),
				'actual'       => self::summarize_list( $sorted ),
				'expected'     => self::summarize_list( $expected_sorted ),
			)
		);
		self::collect_failure(
			$failures,
			$expected_field_filter === $field_filter,
			'wp_filter_object_list field projection agrees with filter then pluck',
			array(
				'actual'   => $field_filter,
				'expected' => $expected_field_filter,
			)
		);

		return self::row(
			$ctx,
			'utility-internals.list-util.filter-pluck-sort',
			array() === $failures,
			array(
				'rows'     => self::summarize_list( $list ),
				'failures' => $failures,
			)
		);
	}

	private static function check_list_util_chained_state( \ComponentFuzz\FuzzContext $ctx ): array {
		$list     = self::list_fixture( $ctx );
		$args     = array(
			'type'   => $ctx->choice( array( 'post', 'page', 'nav', 'media' ) ),
			'active' => $ctx->choice( array( '0', '1' ) ),
		);
		$orderby  = array(
			'score' => $ctx->choice( array( 'ASC', 'DESC' ) ),
			'id'    => 'ASC',
		);
		$util     = new \WP_List_Util( $list );
		$failures = array();

		$filtered          = $util->filter( $args, 'AND' );
		$expected_filtered = self::reference_filter( $list, $args, 'AND' );
		self::collect_failure(
			$failures,
			self::same_list_values( $expected_filtered, $filtered )
				&& self::same_list_values( $expected_filtered, $util->get_output() )
				&& self::same_list_values( $list, $util->get_input() ),
			'WP_List_Util chain filter output matches reference without mutating input',
			array(
				'args'           => $args,
				'actualKeys'     => array_keys( $filtered ),
				'expectedKeys'   => array_keys( $expected_filtered ),
				'inputUnchanged' => self::same_list_values( $list, $util->get_input() ),
			)
		);

		$sorted          = $util->sort( $orderby, 'ASC', true );
		$expected_sorted = self::reference_sort( $expected_filtered, $orderby, true );
		self::collect_failure(
			$failures,
			array_keys( $expected_sorted ) === array_keys( $sorted )
				&& self::same_list_values( $expected_sorted, $sorted )
				&& self::same_list_values( $list, $util->get_input() ),
			'WP_List_Util chain sort reorders current output while preserving keys and input',
			array(
				'orderby'        => $orderby,
				'actualKeys'     => array_keys( $sorted ),
				'expectedKeys'   => array_keys( $expected_sorted ),
				'inputUnchanged' => self::same_list_values( $list, $util->get_input() ),
			)
		);

		$plucked          = $util->pluck( 'label', 'id' );
		$expected_plucked = self::reference_pluck( $expected_sorted, 'label', 'id' );
		self::collect_failure(
			$failures,
			$expected_plucked === $plucked
				&& $expected_plucked === $util->get_output()
				&& self::same_list_values( $list, $util->get_input() ),
			'WP_List_Util chain pluck projects sorted output while preserving original input',
			array(
				'actual'         => $plucked,
				'expected'       => $expected_plucked,
				'inputUnchanged' => self::same_list_values( $list, $util->get_input() ),
			)
		);

		return self::row(
			$ctx,
			'utility-internals.list-util.chained-state',
			array() === $failures,
			array(
				'args'     => $args,
				'orderby'  => $orderby,
				'rows'     => self::summarize_list( $list ),
				'failures' => $failures,
			)
		);
	}

	private static function check_token_map_lookup_and_precompute( \ComponentFuzz\FuzzContext $ctx ): array {
		$mappings   = self::token_mappings( $ctx );
		$key_length = $ctx->choice( array( 1, 2, 3 ) );
		$map        = \WP_Token_Map::from_array( $mappings, $key_length );

		if ( ! $map instanceof \WP_Token_Map ) {
			return self::row(
				$ctx,
				'utility-internals.token-map.constructs',
				false,
				array(
					'mappings'   => $mappings,
					'keyLength'  => $key_length,
					'constructed' => false,
				)
			);
		}

		$failures     = array();
		$read_details = array();
		foreach ( $mappings as $token => $replacement ) {
			$text   = 'pre|' . $token . '|post';
			$length = null;
			$read   = $map->read_token( $text, 4, $length );

			$read_details[] = compact( 'token', 'replacement', 'read', 'length' );
			self::collect_failure(
				$failures,
				$map->contains( $token )
					&& $replacement === $read
					&& strlen( $token ) === $length,
				'contains/read_token agree for exact generated token',
				end( $read_details )
			);

			if ( str_starts_with( $token, 'MiX' ) ) {
				$case_variant = self::ascii_case_variant( $token );
				$case_length  = null;
				$case_read    = $map->read_token( 'x' . $case_variant, 1, $case_length, 'ascii-case-insensitive' );
				self::collect_failure(
					$failures,
					$map->contains( $case_variant, 'ascii-case-insensitive' )
						&& $replacement === $case_read
						&& strlen( $token ) === $case_length,
					'ascii-case-insensitive lookup matches ASCII variants',
					array(
						'token'       => $token,
						'caseVariant' => $case_variant,
						'read'        => $case_read,
						'length'      => $case_length,
					)
				);
			}
		}

		$miss_length = null;
		$miss        = $map->read_token( 'prefix-' . $ctx->identifier( 5, 9 ), 0, $miss_length );
		self::collect_failure(
			$failures,
			! $map->contains( "nul\x00token" ) && null === $miss && null === $miss_length,
			'missing and null-containing tokens fail closed',
			array(
				'miss'       => $miss,
				'missLength' => $miss_length,
			)
		);

		$state         = self::token_map_state( $map );
		$precomputed   = \WP_Token_Map::from_precomputed_table( $state );
		$source        = $map->precomputed_php_source_table( '  ' );
		$round_trips   = $precomputed instanceof \WP_Token_Map;
		foreach ( $mappings as $token => $replacement ) {
			$length      = null;
			$round_trips = $round_trips
				&& $replacement === $precomputed->read_token( $token, 0, $length )
				&& strlen( $token ) === $length;
		}

		$exported      = 2 === $key_length ? $map->to_array() : null;
		$precomputed_a = 2 === $key_length && $precomputed instanceof \WP_Token_Map ? $precomputed->to_array() : null;
		self::collect_failure(
			$failures,
			$precomputed instanceof \WP_Token_Map
				&& $round_trips
				&& ( 2 !== $key_length || ( $exported === $precomputed_a && array() === array_diff_assoc( $mappings, $exported ) ) )
				&& str_contains( $source, \WP_Token_Map::STORAGE_VERSION )
				&& str_contains( $source, '"key_length" => ' . $key_length ),
			'precomputed token-map state and source table round-trip lookup data',
			array(
				'stateKeys'      => array_keys( $state ),
				'exported'       => $exported,
				'precomputed'    => $precomputed_a,
				'sourcePreview'  => substr( $source, 0, 220 ),
				'sourceContains' => str_contains( $source, \WP_Token_Map::STORAGE_VERSION ),
			)
		);

		return self::row(
			$ctx,
			'utility-internals.token-map.lookup-precompute',
			array() === $failures,
			array(
				'keyLength' => $key_length,
				'mappings'  => $mappings,
				'reads'     => $read_details,
				'failures'  => $failures,
			)
		);
	}

	private static function check_matches_map_regex( \ComponentFuzz\FuzzContext $ctx ): array {
		$matches = array(
			0  => $ctx->identifier( 3, 8 ),
			1  => $ctx->choice( array( 'alpha beta', 'slash/value', 'snow ☃', 'query&value' ) ),
			2  => $ctx->choice( array( '42', 'category/name', 'a+b', 'éclair' ) ),
			10 => $ctx->choice( array( 'ten value', 'deep/path', '100%' ) ),
		);
		$subject = 'index.php?first=$matches[1]&second=$matches[2]&zero=$matches[0]&missing=$matches[7]&ten=$matches[10]';

		$actual   = \WP_MatchesMapRegex::apply( $subject, $matches );
		$expected = preg_replace_callback(
			'/\$matches\[([1-9][0-9]*)\]/',
			static function ( array $match ) use ( $matches ): string {
				$index = (int) $match[1];
				return isset( $matches[ $index ] ) ? urlencode( $matches[ $index ] ) : '';
			},
			$subject
		);

		return self::row(
			$ctx,
			'utility-internals.matches-map-regex.substitution',
			$expected === $actual
				&& str_contains( $actual, '$matches[0]' )
				&& ! str_contains( $actual, '$matches[7]' )
				&& str_contains( $actual, urlencode( $matches[10] ) ),
			array(
				'matches'  => $matches,
				'subject'  => $subject,
				'actual'   => $actual,
				'expected' => $expected,
			)
		);
	}

	private static function check_url_pattern_prefixer( \ComponentFuzz\FuzzContext $ctx ): array {
		$slug     = strtolower( str_replace( '_', '-', $ctx->identifier( 4, 8 ) ) );
		$contexts = array(
			'home'    => '/front-' . $slug,
			'site'    => '/core-' . $slug . '/',
			'special' => '/front:' . $slug . '?draft',
			'group'   => '/set{' . $slug . '}',
		);
		$prefixer = new \WP_URL_Pattern_Prefixer( $contexts );

		$cases = array(
			array( 'context' => 'home', 'pattern' => '/products/' . $slug . '/*' ),
			array( 'context' => 'home', 'pattern' => '/front-' . $slug . '/already/*' ),
			array( 'context' => 'site', 'pattern' => 'wp-admin/*' ),
			array( 'context' => 'special', 'pattern' => '/next/:id' ),
			array( 'context' => 'group', 'pattern' => '/literal/*' ),
		);

		$failures = array();
		$details  = array();
		foreach ( $cases as $case ) {
			$actual         = $prefixer->prefix_path_pattern( $case['pattern'], $case['context'] );
			$twice          = $prefixer->prefix_path_pattern( $actual, $case['context'] );
			$expected       = self::reference_prefix_path_pattern( $case['pattern'], $contexts[ $case['context'] ] );
			$idempotent     = self::context_can_strip_prefixed_output( $contexts[ $case['context'] ] );
			$twice_expected = $idempotent ? $actual : $twice;
			$details[] = array(
				'case'       => $case,
				'actual'     => $actual,
				'twice'      => $twice,
				'expected'   => $expected,
				'idempotent' => $idempotent,
			);
			self::collect_failure(
				$failures,
				$expected === $actual && $twice_expected === $twice,
				'URL pattern prefixer matches reference and does not double-prefix',
				end( $details )
			);
		}

		return self::row(
			$ctx,
			'utility-internals.url-pattern-prefixer.reference-idempotence',
			array() === $failures,
			array(
				'contexts' => $contexts,
				'cases'    => $details,
				'failures' => $failures,
			)
		);
	}

	private static function list_fixture( \ComponentFuzz\FuzzContext $ctx ): array {
		$list  = array();
		$types = array( 'post', 'page', 'nav', 'media' );
		$count = $ctx->int( 6, 10 );
		for ( $i = 0; $i < $count; $i++ ) {
			$row = array(
				'id'     => 100 + $i,
				'type'   => $types[ ( $i + $ctx->int( 0, 3 ) ) % count( $types ) ],
				'active' => (string) ( $ctx->int( 0, 1 ) ),
				'score'  => $ctx->int( 0, 99 ),
				'label'  => 'Label ' . $ctx->identifier( 3, 7 ) . ' ' . $i,
			);

			$list[ 'k' . $i ] = $ctx->bool() ? (object) $row : $row;
		}

		return $list;
	}

	private static function reference_filter( array $list, array $args, string $operator ): array {
		if ( array() === $args ) {
			return $list;
		}

		$operator = strtoupper( $operator );
		if ( ! in_array( $operator, array( 'AND', 'OR', 'NOT' ), true ) ) {
			return array();
		}

		$count = count( $args );
		$out   = array();
		foreach ( $list as $key => $item ) {
			$matched = 0;
			foreach ( $args as $field => $value ) {
				if ( is_array( $item ) && array_key_exists( $field, $item ) && $value == $item[ $field ] ) {
					++$matched;
				} elseif ( is_object( $item ) && isset( $item->{$field} ) && $value == $item->{$field} ) {
					++$matched;
				}
			}

			if (
				( 'AND' === $operator && $matched === $count )
				|| ( 'OR' === $operator && $matched > 0 )
				|| ( 'NOT' === $operator && 0 === $matched )
			) {
				$out[ $key ] = $item;
			}
		}

		return $out;
	}

	private static function reference_pluck( array $list, string $field, ?string $index_key = null ): array {
		$out = array();
		if ( null === $index_key ) {
			foreach ( $list as $key => $item ) {
				$out[ $key ] = is_object( $item ) ? $item->{$field} : $item[ $field ];
			}
			return $out;
		}

		foreach ( $list as $item ) {
			if ( is_object( $item ) ) {
				if ( isset( $item->{$index_key} ) ) {
					$out[ $item->{$index_key} ] = $item->{$field};
				} else {
					$out[] = $item->{$field};
				}
			} elseif ( isset( $item[ $index_key ] ) ) {
				$out[ $item[ $index_key ] ] = $item[ $field ];
			} else {
				$out[] = $item[ $field ];
			}
		}

		return $out;
	}

	private static function reference_sort( array $list, array $orderby, bool $preserve_keys ): array {
		$callback = static function ( $a, $b ) use ( $orderby ): int {
			$a = (array) $a;
			$b = (array) $b;

			foreach ( $orderby as $field => $direction ) {
				if ( ! isset( $a[ $field ] ) || ! isset( $b[ $field ] ) ) {
					continue;
				}

				if ( $a[ $field ] == $b[ $field ] ) {
					continue;
				}

				$results = 'DESC' === strtoupper( $direction ) ? array( 1, -1 ) : array( -1, 1 );
				if ( is_numeric( $a[ $field ] ) && is_numeric( $b[ $field ] ) ) {
					return $a[ $field ] < $b[ $field ] ? $results[0] : $results[1];
				}

				return 0 > strcmp( $a[ $field ], $b[ $field ] ) ? $results[0] : $results[1];
			}

			return 0;
		};

		if ( $preserve_keys ) {
			uasort( $list, $callback );
		} else {
			usort( $list, $callback );
		}

		return $list;
	}

	private static function token_mappings( \ComponentFuzz\FuzzContext $ctx ): array {
		$base = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 4, 8 ) ) );
		if ( '' === $base ) {
			$base = 'tok';
		}

		return array(
			'A' . $base => 'alpha-' . $base,
			'a' . $base . 'long' => 'long-' . $base,
			$base . ';' => 'semi-' . $base,
			'Z' => 'zed-' . $base,
			'xy' => 'pair-' . $base,
			'MiX' . substr( $base, 0, 3 ) => 'case-' . $base,
		);
	}

	private static function ascii_case_variant( string $token ): string {
		$out = '';
		for ( $i = 0; $i < strlen( $token ); $i++ ) {
			$char = $token[ $i ];
			$out .= ctype_alpha( $char )
				? ( ctype_lower( $char ) ? strtoupper( $char ) : strtolower( $char ) )
				: $char;
		}
		return $out;
	}

	private static function token_map_state( \WP_Token_Map $map ): array {
		$reflection = new \ReflectionClass( $map );
		$state      = array( 'storage_version' => \WP_Token_Map::STORAGE_VERSION );
		foreach ( array( 'key_length', 'groups', 'large_words', 'small_words', 'small_mappings' ) as $property ) {
			$state[ $property ] = $reflection->getProperty( $property )->getValue( $map );
		}
		return $state;
	}

	private static function reference_prefix_path_pattern( string $path_pattern, string $context_path ): string {
		$context_path         = self::escape_pattern_string( trailingslashit( $context_path ) );
		$escaped_context_path = $context_path;
		if ( strcspn( $context_path, ':?#' ) !== strlen( $context_path ) ) {
			$escaped_context_path = '{' . substr( $context_path, 0, -1 ) . '}/';
		}

		if ( str_starts_with( $path_pattern, $context_path ) ) {
			$path_pattern = substr( $path_pattern, strlen( $context_path ) );
		}

		return $escaped_context_path . ltrim( $path_pattern, '/' );
	}

	private static function context_can_strip_prefixed_output( string $context_path ): bool {
		$context_path = self::escape_pattern_string( trailingslashit( $context_path ) );
		return strcspn( $context_path, ':?#' ) === strlen( $context_path );
	}

	private static function escape_pattern_string( string $str ): string {
		return addcslashes( $str, '+*?:{}()\\' );
	}

	private static function same_list_values( array $expected, array $actual ): bool {
		return self::normalize_list( $expected ) === self::normalize_list( $actual );
	}

	private static function normalize_list( array $list ): array {
		$out = array();
		foreach ( $list as $key => $item ) {
			$out[ (string) $key ] = (array) $item;
		}
		return $out;
	}

	private static function summarize_list( array $list ): array {
		$out = array();
		foreach ( $list as $key => $item ) {
			$row          = (array) $item;
			$out[ $key ] = array(
				'id'     => $row['id'] ?? null,
				'type'   => $row['type'] ?? null,
				'active' => $row['active'] ?? null,
				'score'  => $row['score'] ?? null,
				'label'  => $row['label'] ?? null,
			);
		}
		return $out;
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach ( array( 'wp_actions', 'wp_current_filter', 'wp_filter', 'wp_filters' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}
		return array( 'globals' => $globals );
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		return $snapshot === self::snapshot_state();
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'data'    => $data,
			);
		}
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
