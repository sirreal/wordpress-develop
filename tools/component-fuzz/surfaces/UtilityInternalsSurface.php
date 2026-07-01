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
			$rows[] = self::check_parse_and_array_path_helpers( $ctx->fork( 'parse-array' ) );
			$rows[] = self::check_token_map_lookup_and_precompute( $ctx->fork( 'token-map' ) );
			$rows[] = self::check_token_map_non_default_exports( $ctx->fork( 'token-map-export' ) );
			$rows[] = self::check_matches_map_regex( $ctx->fork( 'matches' ) );
			$rows[] = self::check_url_pattern_prefixer( $ctx->fork( 'prefixer' ) );
			$rows[] = self::check_kebab_case_helper( $ctx->fork( 'kebab-case' ) );
			$rows[] = self::check_hierarchy_loop_helpers( $ctx->fork( 'hierarchy-loop' ) );
			$rows[] = self::check_unique_uuid_and_boolean_helpers( $ctx->fork( 'identity-bool' ) );
			$rows[] = self::check_diagnostic_error_helpers( $ctx->fork( 'diagnostic-errors' ) );
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
		foreach ( array( 'WP_Error', 'WP_List_Util', 'WP_MatchesMapRegex', 'WP_Token_Map', 'WP_URL_Pattern_Prefixer' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'current_action',
				'current_filter',
				'did_action',
				'doing_action',
				'trailingslashit',
				'is_wp_error',
				'remove_filter',
				'wp_debug_backtrace_summary',
				'wp_filter_object_list',
				'wp_list_filter',
				'wp_list_pluck',
				'wp_list_sort',
				'wp_trigger_error',
				'wp_parse_list',
				'wp_parse_id_list',
				'wp_parse_slug_list',
				'wp_kses',
				'wp_array_slice_assoc',
				'wp_recursive_ksort',
				'wp_is_numeric_array',
				'_wp_array_get',
				'_wp_array_set',
				'_wp_to_kebab_case',
				'wp_find_hierarchy_loop',
				'wp_find_hierarchy_loop_tortoise_hare',
				'wp_generate_uuid4',
				'wp_is_uuid',
				'wp_json_encode',
				'wp_unique_id',
				'wp_unique_id_from_values',
				'wp_unique_prefixed_id',
				'wp_validate_boolean',
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

	private static function check_parse_and_array_path_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$slug        = strtolower( str_replace( '_', '-', $ctx->identifier( 4, 8 ) ) );
		$list_string = " {$slug},{$slug}-two\t{$ctx->identifier( 3, 6 )}\nrepeat,repeat ,0 ";
		$list_array  = array(
			'first'  => $slug,
			'nested' => array( 'drop' ),
			'object' => (object) array( 'drop' => true ),
			'false'  => false,
			'int'    => $ctx->int( 1, 99 ),
			'null'   => null,
		);

		$parsed_string          = \wp_parse_list( $list_string );
		$expected_parsed_string = preg_split( '/[\s,]+/', $list_string, -1, PREG_SPLIT_NO_EMPTY );
		$parsed_array           = \wp_parse_list( $list_array );
		$expected_parsed_array  = array_filter( $list_array, 'is_scalar' );

		$id_input     = array( '10', '-5', '10', 'bad', 0, '5.9', false, $ctx->int( 11, 30 ) );
		$parsed_ids   = \wp_parse_id_list( $id_input );
		$expected_ids = array_unique( array_map( 'absint', $id_input ) );
		$slug_input   = array( 'Hello World', 'C++ Thing', 'Hello World', $slug . ' Extra' );
		$parsed_slugs = \wp_parse_slug_list( $slug_input );
		$expected_slugs = array_unique( array_map( 'sanitize_title', $slug_input ) );

		self::collect_failure(
			$failures,
			$expected_parsed_string === $parsed_string
				&& $expected_parsed_array === $parsed_array
				&& $expected_ids === $parsed_ids
				&& $expected_slugs === $parsed_slugs,
			'parse-list helpers split strings, preserve scalar array entries, and normalize IDs/slugs',
			array(
				'parsedString' => $parsed_string,
				'parsedArray'  => $parsed_array,
				'ids'          => $parsed_ids,
				'slugs'        => $parsed_slugs,
			)
		);

		$source = array(
			'z'          => array(
				'b' => 2,
				'a' => 1,
			),
			'a'          => array(
				'd' => 4,
				'c' => array(
					'y' => null,
					'x' => 'value-' . $slug,
				),
			),
			'keep'       => 'yes-' . $slug,
			'nullValue'  => null,
		);
		$slice = \wp_array_slice_assoc( $source, array( 'keep', 'nullValue', 'missing', 'a' ) );

		$sorted = $source;
		\wp_recursive_ksort( $sorted );
		$expected_sorted = array(
			'a'         => array(
				'c' => array(
					'x' => 'value-' . $slug,
					'y' => null,
				),
				'd' => 4,
			),
			'keep'      => 'yes-' . $slug,
			'nullValue' => null,
			'z'         => array(
				'a' => 1,
				'b' => 2,
			),
		);

		$mutated = $source;
		\_wp_array_set( $mutated, array( 'a', 'c', 'generated' ), 'set-' . $slug );
		\_wp_array_set( $mutated, array( 'scalar', 'child' ), 'replaced-' . $slug );
		\_wp_array_set( $mutated, array( 'numeric', 0, 'leaf' ), $ctx->int( 100, 999 ) );
		$invalid = $mutated;
		\_wp_array_set( $invalid, array( 'invalid', new \stdClass() ), 'nope' );

		self::collect_failure(
			$failures,
			array(
				'keep' => 'yes-' . $slug,
				'a'    => $source['a'],
			) === $slice
				&& $expected_sorted === $sorted
				&& 'set-' . $slug === \_wp_array_get( $mutated, array( 'a', 'c', 'generated' ), 'missing' )
				&& null === \_wp_array_get( $source, array( 'a', 'c', 'y' ), 'missing' )
				&& 'missing' === \_wp_array_get( $source, array( 'a', 'missing' ), 'missing' )
				&& 'missing' === \_wp_array_get( $source, array(), 'missing' )
				&& 'replaced-' . $slug === \_wp_array_get( $mutated, array( 'scalar', 'child' ), 'missing' )
				&& $invalid === $mutated,
			'array helpers slice isset keys, sort recursively, and get/set nested paths with fail-closed invalid paths',
			array(
				'slice'   => $slice,
				'sorted'  => $sorted,
				'mutated' => $mutated,
				'invalid' => $invalid,
			)
		);

		$numeric_sparse = array(
			2 => 'two',
			4 => 'four',
		);
		$numeric_string_keys = array(
			'0' => 'zero',
			'1' => 'one',
		);
		$mixed_string_keys = array(
			'01' => 'one',
			0    => 'zero',
		);
		self::collect_failure(
			$failures,
			true === \wp_is_numeric_array( $numeric_sparse )
				&& true === \wp_is_numeric_array( $numeric_string_keys )
				&& true === \wp_is_numeric_array( array() )
				&& false === \wp_is_numeric_array( $mixed_string_keys )
				&& false === \wp_is_numeric_array( 'not-an-array' ),
			'wp_is_numeric_array accepts sparse numeric keys and rejects string-keyed/non-array values',
			array(
				'numericSparse'     => $numeric_sparse,
				'numericStringKeys' => array_keys( $numeric_string_keys ),
				'mixedStringKeys'   => array_keys( $mixed_string_keys ),
			)
		);

		return self::row(
			$ctx,
			'utility-internals.parse-list-and-array-path-helpers',
			array() === $failures,
			array( 'failures' => $failures )
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

		$exported      = $map->to_array();
		$precomputed_a = $precomputed instanceof \WP_Token_Map ? $precomputed->to_array() : null;
		self::collect_failure(
			$failures,
			$precomputed instanceof \WP_Token_Map
				&& $round_trips
				&& self::same_string_map( $mappings, $exported )
				&& self::same_string_map( $mappings, $precomputed_a )
				&& ! self::map_has_nul_key( $exported )
				&& ! self::map_has_nul_key( $precomputed_a )
				&& str_contains( $source, \WP_Token_Map::STORAGE_VERSION )
				&& str_contains( $source, '"key_length" => ' . $key_length ),
			'precomputed token-map state and source table round-trip lookup and export data',
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

	private static function check_token_map_non_default_exports( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::token_map_export_cases( $ctx );

		foreach ( $cases as $case ) {
			$key_length = $case['keyLength'];
			$mappings   = $case['mappings'];
			$map        = \WP_Token_Map::from_array( $mappings, $key_length );

			self::collect_failure(
				$failures,
				$map instanceof \WP_Token_Map,
				'WP_Token_Map constructs non-default key-length export fixtures',
				array( 'case' => $case )
			);

			if ( ! $map instanceof \WP_Token_Map ) {
				continue;
			}

			$lookup_ok = true;
			foreach ( $mappings as $token => $replacement ) {
				$length    = null;
				$lookup_ok = $lookup_ok
					&& $map->contains( $token )
					&& $replacement === $map->read_token( '!' . $token . '?', 1, $length )
					&& strlen( $token ) === $length;
			}

			$state       = self::token_map_state( $map );
			$precomputed = \WP_Token_Map::from_precomputed_table( $state );
			$source      = $map->precomputed_php_source_table( '  ' );
			$exported    = $map->to_array();
			$pre_export  = $precomputed instanceof \WP_Token_Map ? $precomputed->to_array() : null;

			self::collect_failure(
				$failures,
				$lookup_ok
					&& $precomputed instanceof \WP_Token_Map
					&& self::same_string_map( $mappings, $exported )
					&& self::same_string_map( $mappings, $pre_export )
					&& ! self::map_has_nul_key( $exported )
					&& ! self::map_has_nul_key( $pre_export )
					&& str_contains( $source, '"key_length" => ' . $key_length ),
				'WP_Token_Map to_array reconstructs non-default key-length prefixes without NUL padding or truncation',
				array(
					'keyLength'   => $key_length,
					'mappings'    => $mappings,
					'exported'    => $exported,
					'precomputed' => $pre_export,
					'source'      => substr( $source, 0, 220 ),
				)
			);
		}

		return self::row(
			$ctx,
			'utility-internals.token-map.non-default-key-length-exports',
			array() === $failures,
			array(
				'cases'    => $cases,
				'failures' => $failures,
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

	private static function check_kebab_case_helper( \ComponentFuzz\FuzzContext $ctx ): array {
		$first  = self::ascii_word_token( $ctx->identifier( 4, 8 ), 'alpha' );
		$second = self::ascii_word_token( $ctx->identifier( 4, 8 ), 'beta' );
		$number = (string) $ctx->int( 10, 99 );

		$fixtures = array(
			'backgroundColor'       => 'background-color',
			'ColorPaletteV2'        => 'color-palette-v-2',
			'HTTPResponseCode'      => 'http-response-code',
			'2XLValue'              => '2-xl-value',
			'foo_bar baz'           => 'foo-bar-baz',
			"Foo's Bar"             => 'foos-bar',
			"Don'tStop"             => 'dont-stop',
			'rock & roll'           => 'rock-roll',
			'var:preset|spacing|40' => 'var-preset-spacing-40',
			'1st Place 22ND Street' => '1st-place-22nd-street',
			'éclairÜber'            => 'éclair-Über',
			$first . ucfirst( $second ) . $number . 'Value' => $first . '-' . $second . '-' . $number . '-value',
			'  ' . $first . '__' . $second . '!!' . $number . '  ' => $first . '-' . $second . '-' . $number,
		);

		$failures = array();
		$observed = array();
		foreach ( $fixtures as $input => $expected ) {
			$actual     = \_wp_to_kebab_case( $input );
			$again      = \_wp_to_kebab_case( $actual );
			$observed[] = array(
				'input'    => $input,
				'expected' => $expected,
				'actual'   => $actual,
				'again'    => $again,
			);

			self::collect_failure(
				$failures,
				$expected === $actual && $actual === $again,
				'_wp_to_kebab_case matches lodash-compat fixture output and is idempotent on its own output',
				end( $observed )
			);

			self::collect_failure(
				$failures,
				'' === $actual
					|| (
						! str_starts_with( $actual, '-' )
						&& ! str_ends_with( $actual, '-' )
						&& ! str_contains( $actual, '--' )
					),
				'_wp_to_kebab_case does not introduce leading, trailing, or doubled separators for generated sane inputs',
				end( $observed )
			);
		}

		return self::row(
			$ctx,
			'utility-internals.kebab-case-helper.lodash-compatibility',
			array() === $failures,
			array(
				'observed' => $observed,
				'failures' => $failures,
			)
		);
	}

	private static function check_hierarchy_loop_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$base       = $ctx->int( 1000, 9000 );
		$cycle      = array(
			$base     => $base + 1,
			$base + 1 => $base + 2,
			$base + 2 => $base,
		);
		$acyclic    = array(
			$base + 10 => $base + 11,
			$base + 11 => $base + 12,
			$base + 12 => 0,
		);
		$self_loop  = array( $base + 20 => $base + 20 );
		$parentless = array(
			$base + 30 => 0,
			$base + 31 => $base + 30,
		);
		$calls      = array();

		$callback = static function ( int $id, array $parents, string $label ) use ( &$calls ): int {
			$calls[] = array(
				'id'    => $id,
				'label' => $label,
			);
			return (int) ( $parents[ $id ] ?? 0 );
		};

		$failures = array();

		$loop = \wp_find_hierarchy_loop( $callback, $base, null, array( $cycle, 'cycle' ) );
		self::collect_failure(
			$failures,
			self::same_int_key_set( $loop, array( $base, $base + 1, $base + 2 ) ),
			'wp_find_hierarchy_loop returns the complete detected cycle for callback-backed maps',
			array(
				'loop'     => $loop,
				'expected' => array( $base, $base + 1, $base + 2 ),
			)
		);

		$no_loop = \wp_find_hierarchy_loop( $callback, $base + 10, null, array( $acyclic, 'acyclic' ) );
		self::collect_failure(
			$failures,
			array() === $no_loop,
			'wp_find_hierarchy_loop returns an empty array for parent chains that terminate at zero',
			array( 'result' => $no_loop )
		);

		$self_loop_result = \wp_find_hierarchy_loop( $callback, $base + 20, null, array( $self_loop, 'self' ) );
		self::collect_failure(
			$failures,
			self::same_int_key_set( $self_loop_result, array( $base + 20 ) ),
			'wp_find_hierarchy_loop handles self-parent loops as one-member cycles',
			array( 'result' => $self_loop_result )
		);

		$override_loop = \wp_find_hierarchy_loop(
			$callback,
			$base + 30,
			$base + 31,
			array(
				$parentless + array( $base + 32 => $base + 30 ),
				'override',
			)
		);
		self::collect_failure(
			$failures,
			self::same_int_key_set( $override_loop, array( $base + 30, $base + 31 ) ),
			'wp_find_hierarchy_loop start_parent override participates in cycle detection without mutating the callback map',
			array(
				'result' => $override_loop,
				'map'    => $parentless,
			)
		);

		$detected_member = \wp_find_hierarchy_loop_tortoise_hare( $callback, $base, array(), array( $cycle, 'direct-detect' ) );
		$direct_loop     = \wp_find_hierarchy_loop_tortoise_hare( $callback, $base + 1, array(), array( $cycle, 'direct-loop' ), true );
		$direct_no_loop  = \wp_find_hierarchy_loop_tortoise_hare( $callback, $base + 10, array(), array( $acyclic, 'direct-acyclic' ) );

		self::collect_failure(
			$failures,
			in_array( $detected_member, array( $base, $base + 1, $base + 2 ), true )
				&& self::same_int_key_set( $direct_loop, array( $base, $base + 1, $base + 2 ) )
				&& false === $direct_no_loop,
			'wp_find_hierarchy_loop_tortoise_hare detects, enumerates, and rejects generated hierarchy loops',
			array(
				'detectedMember' => $detected_member,
				'directLoop'     => $direct_loop,
				'directNoLoop'   => $direct_no_loop,
			)
		);

		$labels = array_values( array_unique( array_column( $calls, 'label' ) ) );
		sort( $labels );
		self::collect_failure(
			$failures,
			array() === array_diff( array( 'acyclic', 'cycle', 'direct-acyclic', 'direct-detect', 'direct-loop', 'override', 'self' ), $labels ),
			'hierarchy loop callbacks receive callback_args for every generated scenario',
			array(
				'labels'     => $labels,
				'callSample' => array_slice( $calls, 0, 12 ),
			)
		);

		return self::row(
			$ctx,
			'utility-internals.hierarchy-loop-helpers.generated-maps',
			array() === $failures,
			array(
				'base'     => $base,
				'failures' => $failures,
			)
		);
	}

	private static function check_unique_uuid_and_boolean_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$token    = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 5, 9 ) ) );
		$token    = '' === $token ? 'utility' : $token;
		$prefix   = 'cfu-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . $token . '-';
		$failures = array();

		$global_first  = \wp_unique_id( $prefix . 'global-' );
		$global_second = \wp_unique_id( $prefix . 'global-' );
		$global_first_number  = self::id_suffix_number( $global_first, $prefix . 'global-' );
		$global_second_number = self::id_suffix_number( $global_second, $prefix . 'global-' );
		self::collect_failure(
			$failures,
			null !== $global_first_number
				&& null !== $global_second_number
				&& $global_first_number + 1 === $global_second_number,
			'wp_unique_id appends a process-wide monotonic integer while preserving generated prefixes',
			array(
				'first'        => $global_first,
				'second'       => $global_second,
				'firstNumber'  => $global_first_number,
				'secondNumber' => $global_second_number,
			)
		);

		$prefixed_a1 = \wp_unique_prefixed_id( $prefix . 'a-' );
		$prefixed_a2 = \wp_unique_prefixed_id( $prefix . 'a-' );
		$prefixed_b1 = \wp_unique_prefixed_id( $prefix . 'b-' );
		self::collect_failure(
			$failures,
			$prefix . 'a-1' === $prefixed_a1
				&& $prefix . 'a-2' === $prefixed_a2
				&& $prefix . 'b-1' === $prefixed_b1,
			'wp_unique_prefixed_id keeps independent monotonic counters per generated prefix',
			array(
				'a1' => $prefixed_a1,
				'a2' => $prefixed_a2,
				'b1' => $prefixed_b1,
			)
		);

		$values = array(
			'z'    => array(
				'b' => $ctx->int( 1, 99 ),
				'a' => $token,
			),
			'list' => array( true, 'false', $ctx->int( -10, 10 ) ),
			'n'    => $ctx->int( 100, 999 ),
		);
		$canonical = $values;
		\wp_recursive_ksort( $canonical );

		$permuted = array(
			'n'    => $values['n'],
			'list' => $values['list'],
			'z'    => array(
				'a' => $values['z']['a'],
				'b' => $values['z']['b'],
			),
		);
		\wp_recursive_ksort( $permuted );

		$hash_prefix = $prefix . 'hash-';
		$hash_id     = \wp_unique_id_from_values( $canonical, $hash_prefix );
		$hash_repeat = \wp_unique_id_from_values( $canonical, $hash_prefix );
		$hash_permute = \wp_unique_id_from_values( $permuted, $hash_prefix );
		$expected_hash = $hash_prefix . substr( md5( (string) \wp_json_encode( $canonical ) ), 0, 8 );
		self::collect_failure(
			$failures,
			$expected_hash === $hash_id
				&& $hash_id === $hash_repeat
				&& $hash_id === $hash_permute,
			'wp_unique_id_from_values is deterministic over canonicalized nested arrays and matches its documented hash shape',
			array(
				'id'       => $hash_id,
				'repeat'   => $hash_repeat,
				'permuted' => $hash_permute,
				'expected' => $expected_hash,
			)
		);

		$uuids = array( \wp_generate_uuid4(), \wp_generate_uuid4(), \wp_generate_uuid4() );
		$uuid_valid = true;
		foreach ( $uuids as $uuid ) {
			$uuid_valid = $uuid_valid
				&& \wp_is_uuid( $uuid )
				&& \wp_is_uuid( $uuid, 4 )
				&& strtolower( $uuid ) === $uuid
				&& 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid );
		}
		$version_one = '123e4567-e89b-12d3-a456-426614174000';
		self::collect_failure(
			$failures,
			$uuid_valid
				&& count( $uuids ) === count( array_unique( $uuids ) )
				&& \wp_is_uuid( $version_one )
				&& ! \wp_is_uuid( $version_one, 4 )
				&& ! \wp_is_uuid( strtoupper( $uuids[0] ) )
				&& ! \wp_is_uuid( str_replace( '-', '', $uuids[0] ) )
				&& ! \wp_is_uuid( 12345 ),
			'wp_generate_uuid4 and wp_is_uuid enforce lowercase UUID shape, V4 version, and variant bits',
			array(
				'uuids'      => $uuids,
				'versionOne' => $version_one,
			)
		);

		$boolean_cases = array(
			array( 'label' => 'true-bool', 'value' => true, 'expected' => true ),
			array( 'label' => 'false-bool', 'value' => false, 'expected' => false ),
			array( 'label' => 'false-lower', 'value' => 'false', 'expected' => false ),
			array( 'label' => 'false-upper', 'value' => 'FALSE', 'expected' => false ),
			array( 'label' => 'false-title', 'value' => 'False', 'expected' => false ),
			array( 'label' => 'true-string', 'value' => 'true', 'expected' => true ),
			array( 'label' => 'zero-string', 'value' => '0', 'expected' => false ),
			array( 'label' => 'one-string', 'value' => '1', 'expected' => true ),
			array( 'label' => 'empty-string', 'value' => '', 'expected' => false ),
			array( 'label' => 'zero-int', 'value' => 0, 'expected' => false ),
			array( 'label' => 'one-int', 'value' => 1, 'expected' => true ),
			array( 'label' => 'null', 'value' => null, 'expected' => false ),
			array( 'label' => 'empty-array', 'value' => array(), 'expected' => false ),
			array( 'label' => 'filled-array', 'value' => array( 0 ), 'expected' => true ),
		);
		$boolean_observed = array();
		$boolean_ok       = true;
		foreach ( $boolean_cases as $case ) {
			$actual                              = \wp_validate_boolean( $case['value'] );
			$boolean_observed[ $case['label'] ] = $actual;
			$boolean_ok                         = $boolean_ok && $case['expected'] === $actual;
		}
		self::collect_failure(
			$failures,
			$boolean_ok,
			'wp_validate_boolean only special-cases false strings before normal boolean casting',
			array( 'observed' => $boolean_observed )
		);

		return self::row(
			$ctx,
			'utility-internals.unique-uuid-boolean-helpers.contracts',
			array() === $failures,
			array(
				'prefix'   => $prefix,
				'failures' => $failures,
			)
		);
	}

	private static function check_diagnostic_error_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 4, 8 ) ) );
		if ( '' === $token ) {
			$token = 'diagnostic';
		}

		self::isolate_hook_callbacks(
			array(
				'is_wp_error_instance',
				'wp_trigger_error_always_run',
				'wp_trigger_error_trigger_error',
				'wp_trigger_error_run',
			)
		);

		$error       = new \WP_Error( 'component_fuzz_' . $token, 'Generated error ' . $token, array( 'seed' => $ctx->seed() ) );
		$error_seen  = array();
		$error_action = static function ( $thing ) use ( $error, &$error_seen ): void {
			$error_seen[] = array(
				'same'          => $thing === $error,
				'codes'         => $thing instanceof \WP_Error ? $thing->get_error_codes() : array(),
				'currentAction' => \current_action(),
				'currentFilter' => \current_filter(),
				'doingAction'   => \doing_action( 'is_wp_error_instance' ),
			);
		};

		$is_wp_error_count_before = \did_action( 'is_wp_error_instance' );
		\add_action( 'is_wp_error_instance', $error_action, 10, 1 );
		$scalar_result = \is_wp_error( 'not an error ' . $token );
		$array_result  = \is_wp_error( array( 'code' => $token ) );
		$object_result = \is_wp_error( (object) array( 'code' => $token ) );
		$throw_result  = \is_wp_error( new \Exception( 'not a wp error' ) );
		$error_result  = \is_wp_error( $error );
		$is_wp_error_count_after = \did_action( 'is_wp_error_instance' );
		$expected_error_seen     = array(
			array(
				'same'          => true,
				'codes'         => $error->get_error_codes(),
				'currentAction' => 'is_wp_error_instance',
				'currentFilter' => 'is_wp_error_instance',
				'doingAction'   => true,
			),
		);

		self::collect_failure(
			$failures,
			false === $scalar_result
				&& false === $array_result
				&& false === $object_result
				&& false === $throw_result
				&& true === $error_result
				&& $is_wp_error_count_before + 1 === $is_wp_error_count_after
				&& $expected_error_seen === $error_seen,
			'is_wp_error returns true only for WP_Error and fires is_wp_error_instance once with the original object/action context',
			array(
				'scalarResult' => $scalar_result,
				'arrayResult'  => $array_result,
				'objectResult' => $object_result,
				'throwResult'  => $throw_result,
				'errorResult'  => $error_result,
				'actionDelta'  => $is_wp_error_count_after - $is_wp_error_count_before,
				'seen'         => $error_seen,
			)
		);

		$pretty_trace = self::debug_backtrace_entry( null, 0, true );
		$raw_trace    = self::debug_backtrace_entry( null, 0, false );
		$skip_trace   = self::debug_backtrace_entry( null, 1, false );
		$ignore_trace = self::debug_backtrace_entry( __CLASS__, 0, false );

		self::collect_failure(
			$failures,
			is_string( $pretty_trace )
				&& self::string_ordered_contains(
					$pretty_trace,
					array(
						__CLASS__ . '::debug_backtrace_entry',
						__CLASS__ . '::debug_backtrace_bridge',
						__CLASS__ . '::collect_debug_backtrace_summary',
					)
				)
				&& is_array( $raw_trace )
				&& ( $raw_trace[0] ?? null ) === __CLASS__ . '::collect_debug_backtrace_summary'
				&& in_array( __CLASS__ . '::debug_backtrace_bridge', $raw_trace, true )
				&& in_array( __CLASS__ . '::debug_backtrace_entry', $raw_trace, true )
				&& is_array( $skip_trace )
				&& ( $skip_trace[0] ?? null ) === __CLASS__ . '::debug_backtrace_bridge'
				&& is_array( $ignore_trace )
				&& self::trace_omits_class( $ignore_trace, __CLASS__ ),
			'wp_debug_backtrace_summary preserves call order, supports raw arrays, skips frames, and omits ignored classes',
			array(
				'pretty' => $pretty_trace,
				'raw'    => array_slice( is_array( $raw_trace ) ? $raw_trace : array(), 0, 5 ),
				'skip'   => array_slice( is_array( $skip_trace ) ? $skip_trace : array(), 0, 5 ),
				'ignore' => array_slice( is_array( $ignore_trace ) ? $ignore_trace : array(), 0, 5 ),
			)
		);

		$always_log  = array();
		$filter_log  = array();
		$run_log     = array();
		$php_errors  = array();
		$event_log   = array();
		$suppressed  = 'component_fuzz_suppressed_' . $token;
		$triggered   = 'component_fuzz_triggered_' . $token;
		$message_one = 'Suppressed diagnostic ' . $token;
		$message_two = 'Diagnostics <strong>' . $token . '</strong><script>bad()</script><a href="javascript:alert(1)">link</a><code>x</code>';
		$wp_debug    = defined( 'WP_DEBUG' ) && WP_DEBUG;

		$always = static function ( string $function_name, string $message, int $error_level ) use ( &$always_log, &$event_log ): void {
			$event_log[]  = array( 'event' => 'always', 'function' => $function_name );
			$always_log[] = compact( 'function_name', 'message', 'error_level' );
		};
		$filter = static function ( bool $trigger, string $function_name, string $message, int $error_level ) use ( $suppressed, &$filter_log, &$event_log ): bool {
			$event_log[]  = array( 'event' => 'filter', 'function' => $function_name );
			$filter_log[] = compact( 'trigger', 'function_name', 'message', 'error_level' );
			return $function_name !== $suppressed;
		};
		$run = static function ( string $function_name, string $message, int $error_level ) use ( &$run_log, &$event_log ): void {
			$event_log[] = array( 'event' => 'run', 'function' => $function_name );
			$run_log[] = compact( 'function_name', 'message', 'error_level' );
		};

		\add_action( 'wp_trigger_error_always_run', $always, 10, 3 );
		\add_filter( 'wp_trigger_error_trigger_error', $filter, 10, 4 );
		\add_action( 'wp_trigger_error_run', $run, 10, 3 );

		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$php_errors ): bool {
				$php_errors[] = array(
					'errno'  => $errno,
					'errstr' => $errstr,
				);
				return true;
			}
		);
		try {
			\wp_trigger_error( $suppressed, $message_one, E_USER_NOTICE );
			\wp_trigger_error( $triggered, $message_two, E_USER_WARNING );
		} finally {
			restore_error_handler();
			\remove_filter( 'wp_trigger_error_trigger_error', $filter, 10 );
		}

		$expected_run_count = $wp_debug ? 1 : 0;
		$second_error       = $php_errors[0]['errstr'] ?? '';
		$event_sequence     = array_map(
			static fn( array $event ): string => $event['event'] . ':' . $event['function'],
			$event_log
		);
		$expected_sequence  = array(
			'always:' . $suppressed,
			'filter:' . $suppressed,
			'always:' . $triggered,
			'filter:' . $triggered,
		);
		if ( $wp_debug ) {
			$expected_sequence[] = 'run:' . $triggered;
		}
		self::collect_failure(
			$failures,
			2 === count( $always_log )
				&& 2 === count( $filter_log )
				&& $expected_run_count === count( $run_log )
				&& $expected_run_count === count( $php_errors )
				&& $expected_sequence === $event_sequence
				&& $suppressed === ( $always_log[0]['function_name'] ?? null )
				&& $message_one === ( $always_log[0]['message'] ?? null )
				&& $suppressed === ( $filter_log[0]['function_name'] ?? null )
				&& true === ( $filter_log[0]['trigger'] ?? null )
				&& $message_one === ( $filter_log[0]['message'] ?? null )
				&& $triggered === ( $always_log[1]['function_name'] ?? null )
				&& $message_two === ( $always_log[1]['message'] ?? null )
				&& $triggered === ( $filter_log[1]['function_name'] ?? null )
				&& true === ( $filter_log[1]['trigger'] ?? null )
				&& $message_two === ( $filter_log[1]['message'] ?? null )
				&& (
					! $wp_debug
					|| (
						$triggered === ( $run_log[0]['function_name'] ?? null )
						&& E_USER_WARNING === ( $php_errors[0]['errno'] ?? null )
						&& str_starts_with( $second_error, $triggered . '(): Diagnostics ' )
						&& str_contains( $second_error, '<strong>' . $token . '</strong>' )
						&& str_contains( $second_error, '<code>x</code>' )
						&& ! str_contains( $second_error, '<script' )
						&& ! str_contains( $second_error, 'javascript:' )
					)
				),
			'wp_trigger_error always runs diagnostics hooks, honors suppression filters, gates PHP errors on WP_DEBUG, and sanitizes emitted messages',
			array(
				'wpDebug'    => $wp_debug,
				'alwaysLog'  => $always_log,
				'filterLog'  => $filter_log,
				'runLog'     => $run_log,
				'phpErrors'  => $php_errors,
				'events'     => $event_sequence,
			)
		);

		return self::row(
			$ctx,
			'utility-internals.diagnostic-error-helpers.hooks-and-traces',
			array() === $failures,
			array(
				'token'    => $token,
				'failures' => $failures,
			)
		);
	}

	private static function debug_backtrace_entry( ?string $ignore_class, int $skip_frames, bool $pretty ) {
		return self::debug_backtrace_bridge( $ignore_class, $skip_frames, $pretty );
	}

	private static function debug_backtrace_bridge( ?string $ignore_class, int $skip_frames, bool $pretty ) {
		return self::collect_debug_backtrace_summary( $ignore_class, $skip_frames, $pretty );
	}

	private static function collect_debug_backtrace_summary( ?string $ignore_class, int $skip_frames, bool $pretty ) {
		return \wp_debug_backtrace_summary( $ignore_class, $skip_frames, $pretty );
	}

	private static function same_int_key_set( $actual, array $expected ): bool {
		if ( ! is_array( $actual ) ) {
			return false;
		}

		$actual_keys = array_map( 'intval', array_keys( $actual ) );
		$expected    = array_map( 'intval', $expected );
		sort( $actual_keys );
		sort( $expected );

		return $expected === $actual_keys;
	}

	private static function id_suffix_number( string $id, string $prefix ): ?int {
		if ( ! str_starts_with( $id, $prefix ) ) {
			return null;
		}

		$suffix = substr( $id, strlen( $prefix ) );
		return ctype_digit( $suffix ) ? (int) $suffix : null;
	}

	private static function ascii_word_token( string $value, string $fallback ): string {
		$value = strtolower( preg_replace( '/[^a-z]+/', '', $value ) ?? '' );
		if ( '' === $value ) {
			return $fallback;
		}

		return $value;
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

	private static function token_map_export_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$suffix = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 4, 8 ) ) );
		if ( '' === $suffix ) {
			$suffix = 'case';
		}

		return array(
			array(
				'keyLength' => 1,
				'mappings'  => array(
					'a' => 'short-a-' . $suffix,
					'ab' => 'prefix-ab-' . $suffix,
					'abc' => 'prefix-abc-' . $suffix,
					'x' => 'short-x-' . $suffix,
					'xy' => 'prefix-xy-' . $suffix,
					'xyz' => 'prefix-xyz-' . $suffix,
				),
			),
			array(
				'keyLength' => 3,
				'mappings'  => array(
					'abc' => 'short-abc-' . $suffix,
					'abcd' => 'prefix-abcd-' . $suffix,
					'abce' => 'prefix-abce-' . $suffix,
					'xyz' => 'short-xyz-' . $suffix,
					'xyza' => 'prefix-xyza-' . $suffix,
					'xyzz' => 'prefix-xyzz-' . $suffix,
				),
			),
		);
	}

	private static function same_string_map( array $expected, $actual ): bool {
		if ( ! is_array( $actual ) || count( $expected ) !== count( $actual ) ) {
			return false;
		}

		ksort( $expected );
		ksort( $actual );
		return $expected === $actual;
	}

	private static function map_has_nul_key( $map ): bool {
		if ( ! is_array( $map ) ) {
			return true;
		}

		foreach ( array_keys( $map ) as $key ) {
			if ( is_string( $key ) && str_contains( $key, "\x00" ) ) {
				return true;
			}
		}

		return false;
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
			$value = $GLOBALS[ $name ] ?? null;
			if ( 'wp_filter' === $name ) {
				$value = self::clone_wp_filter_registry( $value );
			}

			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $value,
			);
		}
		return array( 'globals' => $globals );
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = 'wp_filter' === $name ? self::clone_wp_filter_registry( $entry['value'] ) : $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		$current = self::snapshot_state();
		if ( array_keys( $snapshot['globals'] ) !== array_keys( $current['globals'] ) ) {
			return false;
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			$current_entry = $current['globals'][ $name ];
			if ( $entry['exists'] !== $current_entry['exists'] ) {
				return false;
			}
			if ( ! $entry['exists'] ) {
				continue;
			}

			if ( 'wp_filter' === $name ) {
				if ( $entry['value'] != $current_entry['value'] ) {
					return false;
				}
				continue;
			}

			if ( $entry['value'] !== $current_entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function isolate_hook_callbacks( array $hook_names ): void {
		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return;
		}

		foreach ( $hook_names as $hook_name ) {
			unset( $GLOBALS['wp_filter'][ $hook_name ] );
		}
	}

	private static function clone_wp_filter_registry( $registry ) {
		if ( ! is_array( $registry ) ) {
			return $registry instanceof \WP_Hook ? clone $registry : $registry;
		}

		$clone = array();
		foreach ( $registry as $hook_name => $hook ) {
			$clone[ $hook_name ] = $hook instanceof \WP_Hook ? clone $hook : $hook;
		}

		return $clone;
	}

	private static function string_ordered_contains( string $haystack, array $needles ): bool {
		$offset = 0;
		foreach ( $needles as $needle ) {
			$position = strpos( $haystack, $needle, $offset );
			if ( false === $position ) {
				return false;
			}
			$offset = $position + strlen( $needle );
		}

		return true;
	}

	private static function trace_omits_class( array $trace, string $class_name ): bool {
		foreach ( $trace as $frame ) {
			if ( is_string( $frame ) && str_starts_with( $frame, $class_name . '::' ) ) {
				return false;
			}
		}

		return true;
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
