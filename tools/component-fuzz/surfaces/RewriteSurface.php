<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB WordPress rewrite and routing helpers.
 */
final class RewriteSurface {
	public const NAME = 'rewrite';

	private const HOME_URL  = 'https://example.test/site-base';
	private const SITE_URL  = 'http://example.test/wp';
	private const MAX_RULES = 300;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'rewrite.bootstrap-apis-available',
					'Required WordPress rewrite/routing APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();
		$ob_level = ob_get_level();

		try {
			$case = self::case_for_context( $ctx );
			self::install_option_filters( $case );
			self::prepare_globals();

			$rows[] = self::check_rewrite_tags( $ctx, $case );
			$rows[] = self::check_permastruct_generation( $ctx, $case );
			$rows[] = self::check_rule_precedence_and_storage( $ctx, $case );
			$rows[] = self::check_query_substitution( $ctx, $case );
			$rows[] = self::check_endpoints( $ctx, $case );
			$rows[] = self::check_query_arg_helpers( $ctx, $case );
			$rows[] = self::check_wp_parse_url_edges( $ctx, $case );
			$rows[] = self::check_home_site_urls( $ctx, $case );
			$rows[] = self::check_url_to_postid_cheap_paths( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'rewrite.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_globals( $snapshot );

			$after_restore = self::snapshot_globals();
			$rows[]        = $ctx->result(
				'rewrite.globals-restored',
				$snapshot === $after_restore,
				array(
					'trackedGlobals' => array_keys( $snapshot['globals'] ),
					'difference'     => $snapshot === $after_restore ? null : self::first_value_difference( $snapshot, $after_restore ),
				)
			);
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Rewrite', 'WP_MatchesMapRegex' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_permastruct',
				'add_query_arg',
				'add_rewrite_endpoint',
				'add_rewrite_rule',
				'add_rewrite_tag',
				'build_query',
				'home_url',
				'remove_permastruct',
				'remove_query_arg',
				'remove_rewrite_tag',
				'site_url',
				'url_to_postid',
				'wp_parse_str',
				'wp_parse_url',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_rewrite_tags( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp, $wp_rewrite;

		$before_count       = count( $wp_rewrite->rewritecode );
		$invalid_candidates = array( '', '%', 'not-percent-wrapped', '%x', 'x%' );
		foreach ( $invalid_candidates as $invalid_tag ) {
			\add_rewrite_tag( $invalid_tag, '([^/]+)', 'invalid=' );
		}

		$invalid_noop = $before_count === count( $wp_rewrite->rewritecode );

		$tag       = $case['customTag'];
		$query_var = $case['customQueryVar'];
		\add_rewrite_tag( $tag, '([^/]+)', $query_var . '=' );
		$first_position = array_search( $tag, $wp_rewrite->rewritecode, true );
		$first_count    = count( $wp_rewrite->rewritecode );

		$updated_query_var = $query_var . '_updated';
		\add_rewrite_tag( $tag, '([A-Z0-9_-]+)', $updated_query_var . '=' );
		$updated_position = array_search( $tag, $wp_rewrite->rewritecode, true );

		$default_tag = '%route_' . $case['token'] . '%';
		$default_var = trim( $default_tag, '%' );
		\add_rewrite_tag( $default_tag, '([^/]+)' );

		$rules = $wp_rewrite->generate_rewrite_rule( 'edge/' . $tag . '/' . $default_tag, false );

		$removed_tag = '%remove_' . $case['token'] . '%';
		\add_rewrite_tag( $removed_tag, '([^/]+)', 'removed=' );
		\remove_rewrite_tag( $removed_tag );
		$removed = ! in_array( $removed_tag, $wp_rewrite->rewritecode, true );

		$ok = $invalid_noop
			&& false !== $first_position
			&& $first_position === $updated_position
			&& $first_count === count( $wp_rewrite->rewritecode ) - 1
			&& '([A-Z0-9_-]+)' === $wp_rewrite->rewritereplace[ $updated_position ]
			&& $updated_query_var . '=' === $wp_rewrite->queryreplace[ $updated_position ]
			&& in_array( $default_var, $wp->public_query_vars, true )
			&& $removed
			&& self::rules_have_query_vars( $rules, array( $updated_query_var, $default_var ) )
			&& ! self::rules_contain_any( $rules, array( $tag, $default_tag ) );

		return $ctx->result(
			'rewrite.tags.registration-update-query-vars',
			$ok,
			self::case_data( $case ) + array(
				'invalidNoop'      => $invalid_noop,
				'customTag'        => $tag,
				'firstPosition'    => $first_position,
				'updatedPosition'  => $updated_position,
				'defaultTag'       => $default_tag,
				'defaultQueryVars' => in_array( $default_var, $wp->public_query_vars, true ),
				'removedTag'       => $removed_tag,
				'removed'          => $removed,
				'ruleCount'        => count( $rules ),
				'ruleSample'       => self::sample_assoc( $rules ),
			)
		);
	}

	private static function check_permastruct_generation( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$checked  = 0;

		foreach ( self::permastruct_corpus( $case ) as $struct ) {
			$rewrite_a = self::new_rewrite();
			$rewrite_b = self::new_rewrite();
			self::add_surface_tags( $rewrite_a, $case );
			self::add_surface_tags( $rewrite_b, $case );

			$rules_first  = $rewrite_a->generate_rewrite_rules( $struct, EP_PERMALINK | EP_PAGES, true, true, false, true, true );
			$rules_second = $rewrite_a->generate_rewrite_rules( $struct, EP_PERMALINK | EP_PAGES, true, true, false, true, true );
			$rules_fresh  = $rewrite_b->generate_rewrite_rules( $struct, EP_PERMALINK | EP_PAGES, true, true, false, true, true );
			$short_first  = $rewrite_a->generate_rewrite_rule( $struct, false );
			$short_direct = $rewrite_a->generate_rewrite_rules( $struct, EP_NONE, false, false, false, false );

			++$checked;
			$expected_vars = self::query_vars_for_struct( $rewrite_a, $struct );
			$case_ok       = $rules_first === $rules_second
				&& $rules_first === $rules_fresh
				&& $short_first === $short_direct
				&& count( $rules_first ) <= self::MAX_RULES
				&& self::array_has_unique_keys( $rules_first )
				&& self::rules_have_query_vars( $rules_first, $expected_vars )
				&& ! self::rules_contain_unexpanded_tags( $rules_first );

			if ( ! $case_ok ) {
				$failures[] = array(
					'struct'         => $struct,
					'expectedVars'   => $expected_vars,
					'ruleCount'      => count( $rules_first ),
					'firstFreshDiff' => self::first_value_difference( $rules_first, $rules_fresh ),
					'shortDiff'      => self::first_value_difference( $short_first, $short_direct ),
					'ruleSample'     => self::sample_assoc( $rules_first ),
				);
			}
		}

		return $ctx->result(
			'rewrite.permastruct.rules-deterministic-idempotent',
			array() === $failures,
			self::case_data( $case ) + array(
				'checked'  => $checked,
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_rule_precedence_and_storage( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp_rewrite;

		$top_regex      = '^cfuzz-top-' . $case['token'] . '/([^/]+)/?$';
		$bottom_regex   = '^cfuzz-bottom-' . $case['token'] . '/?$';
		$external_regex = '^cfuzz-external-' . $case['token'] . '/([^/]+)/?$';
		$query_var      = $case['customQueryVar'];

		\add_rewrite_rule( $bottom_regex, array( $query_var => 'bottom', 'drop' => false ), 'bottom' );
		\add_rewrite_rule( $top_regex, 'index.php?' . $query_var . '=$matches[1]', 'top' );
		\add_rewrite_rule( $external_regex, 'external.php?thing=$matches[1]', 'top' );

		$permastruct_name = 'cfuzz_struct_' . $case['token'];
		\add_permastruct(
			$permastruct_name,
			'archive/' . $case['customTag'],
			array(
				'with_front'  => false,
				'ep_mask'     => EP_NONE,
				'paged'       => false,
				'feed'        => false,
				'walk_dirs'   => false,
				'endpoints'   => false,
			)
		);
		$stored_permastruct = $wp_rewrite->extra_permastructs[ $permastruct_name ] ?? null;
		\remove_permastruct( $permastruct_name );
		$removed_permastruct = ! isset( $wp_rewrite->extra_permastructs[ $permastruct_name ] );

		$rules      = $wp_rewrite->rewrite_rules();
		$rule_keys  = array_keys( $rules );
		$top_pos    = array_search( $top_regex, $rule_keys, true );
		$bottom_pos = array_search( $bottom_regex, $rule_keys, true );

		$overlap = array_intersect( array_keys( $wp_rewrite->extra_rules_top ), array_keys( $wp_rewrite->extra_rules ) );
		$ok      = isset( $wp_rewrite->extra_rules_top[ $top_regex ] )
			&& isset( $wp_rewrite->extra_rules[ $bottom_regex ] )
			&& 'index.php?' . $query_var . '=bottom' === $wp_rewrite->extra_rules[ $bottom_regex ]
			&& isset( $wp_rewrite->non_wp_rules[ $external_regex ] )
			&& ! isset( $wp_rewrite->extra_rules_top[ $external_regex ] )
			&& is_array( $stored_permastruct )
			&& $wp_rewrite->root . 'archive/' . $case['customTag'] === $stored_permastruct['struct']
			&& $removed_permastruct
			&& false !== $top_pos
			&& false !== $bottom_pos
			&& $top_pos < $bottom_pos
			&& array() === $overlap;

		return $ctx->result(
			'rewrite.rules.precedence-storage-external-permastruct',
			$ok,
			self::case_data( $case ) + array(
				'topRegex'             => $top_regex,
				'bottomRegex'          => $bottom_regex,
				'externalRegex'        => $external_regex,
				'topPosition'          => $top_pos,
				'bottomPosition'       => $bottom_pos,
				'bottomQuery'          => $wp_rewrite->extra_rules[ $bottom_regex ] ?? null,
				'externalStored'       => $wp_rewrite->non_wp_rules[ $external_regex ] ?? null,
				'storedPermastruct'    => $stored_permastruct,
				'removedPermastruct'   => $removed_permastruct,
				'topBottomKeyOverlap'  => $overlap,
				'compiledRulesSample'  => self::sample_assoc( $rules ),
				'compiledRulesCount'   => count( $rules ),
			)
		);
	}

	private static function check_query_substitution( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$rewrite = self::new_rewrite();
		$rewrite->matches = 'matches';
		self::add_surface_tags( $rewrite, $case );

		$segment_a = $case['pathSegments'][0];
		$segment_b = $case['pathSegments'][1];
		$struct    = 'route/' . $case['customTag'] . '/%postname%';
		$rules     = $rewrite->generate_rewrite_rules( $struct, EP_NONE, false, false, false, false, false );
		$path      = 'route/' . $segment_a . '/' . $segment_b . '/';
		$matches   = array();
		$template  = self::first_matching_rule_with_query_vars( $rules, array( $case['customQueryVar'], 'name' ), $path, $matches );

		$matched = false;
		$vars    = array();
		if ( null !== $template ) {
			$matched = true;
			$query   = preg_replace( '!^.+\?!', '', $template['query'] );
			$query   = \WP_MatchesMapRegex::apply( $query, $matches );
			\wp_parse_str( $query, $vars );
		}

		$ok = null !== $template
			&& $matched
			&& isset( $vars[ $case['customQueryVar'] ], $vars['name'] )
			&& $segment_a === $vars[ $case['customQueryVar'] ]
			&& $segment_b === $vars['name'];

		return $ctx->result(
			'rewrite.matches.query-template-substitution',
			$ok,
			self::case_data( $case ) + array(
				'struct'       => $struct,
				'path'         => $path,
				'pathSegments' => array( $segment_a, $segment_b ),
				'template'     => $template,
				'matched'      => $matched,
				'vars'         => $vars,
				'ruleSample'   => self::sample_assoc( $rules ),
			)
		);
	}

	private static function check_endpoints( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp, $wp_rewrite;

		$json_var  = 'json_' . $case['token'];
		$embed_var = 'embed_' . $case['token'];
		$raw_name  = 'rawjson_' . $case['token'];

		\add_rewrite_endpoint( 'json', EP_PERMALINK | EP_PAGES, $json_var );
		\add_rewrite_endpoint( 'embed', EP_PERMALINK, $embed_var );
		\add_rewrite_endpoint( $raw_name, EP_PERMALINK, false );

		$struct   = 'article/%postname%';
		$without  = $wp_rewrite->generate_rewrite_rules( $struct, EP_PERMALINK, true, true, false, false, false );
		$with     = $wp_rewrite->generate_rewrite_rules( $struct, EP_PERMALINK, true, true, false, false, true );
		$base_ok  = self::rules_preserve_subset( $without, $with );
		$json_ok  = self::rules_have_endpoint_query( $with, 'json', $json_var );
		$embed_ok = self::rules_have_endpoint_query( $with, 'embed', $embed_var );
		$raw_ok   = ! in_array( $raw_name, $wp->public_query_vars, true )
			&& self::rules_have_endpoint_query( $with, $raw_name, '' );
		$feed_ok  = self::rules_have_query_vars( $with, array( 'feed' ) );
		$core_embed_ok = self::rules_have_literal_query( $with, 'embed=true' );

		$ok = $base_ok
			&& $json_ok
			&& $embed_ok
			&& $raw_ok
			&& $feed_ok
			&& $core_embed_ok
			&& in_array( $json_var, $wp->public_query_vars, true )
			&& in_array( $embed_var, $wp->public_query_vars, true )
			&& ! self::rules_contain_unexpanded_tags( $with );

		return $ctx->result(
			'rewrite.endpoints.feed-embed-json-preserve-base',
			$ok,
			self::case_data( $case ) + array(
				'struct'           => $struct,
				'endpointVars'     => array( $json_var, $embed_var ),
				'rawEndpointName'  => $raw_name,
				'baseRuleCount'    => count( $without ),
				'endpointRuleCount' => count( $with ),
				'basePreserved'    => $base_ok,
				'jsonEndpoint'     => $json_ok,
				'embedEndpoint'    => $embed_ok,
				'rawEndpointQuery' => $raw_ok,
				'feedRules'        => $feed_ok,
				'coreEmbedRules'   => $core_embed_ok,
				'ruleSample'       => self::sample_assoc( $with ),
			)
		);
	}

	private static function check_query_arg_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$build_data = array(
			'alpha'  => '1',
			'zero'   => 0,
			'false'  => false,
			'empty'  => '',
			'nested' => array( 'leaf' => 'value' ),
			'null'   => null,
		);
		$built      = \build_query( $build_data );
		$parsed     = array();
		\wp_parse_str( $built, $parsed );

		$base_url = 'https://example.test/base/path?keep=1&encoded=a%2Bb#frag';
		$pairs    = array(
			'added' => rawurlencode( $case['unicodeSlug'] ),
			'space' => rawurlencode( 'a b' ),
			'empty' => '',
			'drop'  => false,
		);
		$added    = \add_query_arg( $pairs, $base_url );
		$parts    = \wp_parse_url( $added );
		$added_qs = array();
		\wp_parse_str( $parts['query'] ?? '', $added_qs );
		$removed = \remove_query_arg( array( 'added', 'space', 'empty', 'drop' ), $added );
		$removed_parts = \wp_parse_url( $removed );
		$removed_qs    = array();
		\wp_parse_str( $removed_parts['query'] ?? '', $removed_qs );

		$ok = array(
			'buildNullOmitted'       => ! array_key_exists( 'null', $parsed ),
			'buildFalseStringZero'   => isset( $parsed['false'] ) && '0' === $parsed['false'],
			'buildNestedRoundTrip'   => isset( $parsed['nested']['leaf'] ) && 'value' === $parsed['nested']['leaf'],
			'fragmentPreserved'      => str_ends_with( $added, '#frag' ),
			'addedValuesParse'       => isset( $added_qs['added'], $added_qs['space'], $added_qs['empty'] )
				&& $case['unicodeSlug'] === $added_qs['added']
				&& 'a b' === $added_qs['space']
				&& '' === $added_qs['empty'],
			'falseRemovesKey'        => ! array_key_exists( 'drop', $added_qs ),
			'removeQueryArgs'        => ! array_key_exists( 'added', $removed_qs )
				&& ! array_key_exists( 'space', $removed_qs )
				&& ! array_key_exists( 'empty', $removed_qs )
				&& isset( $removed_qs['keep'], $removed_qs['encoded'] )
				&& '1' === $removed_qs['keep']
				&& 'a+b' === $removed_qs['encoded'],
			'removeAliasAgreement'   => \remove_query_arg( 'added', $added ) === \add_query_arg( 'added', false, $added ),
		);

		return $ctx->result(
			'rewrite.query-args.build-add-remove-round-trip',
			! in_array( false, $ok, true ),
			self::case_data( $case ) + array(
				'checks'       => $ok,
				'built'        => $built,
				'parsed'       => $parsed,
				'addedUrl'     => $added,
				'addedQuery'   => $added_qs,
				'removedUrl'   => $removed,
				'removedQuery' => $removed_qs,
			)
		);
	}

	private static function check_wp_parse_url_edges( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$cases = array(
			'full'             => array(
				'http://username:password@host.name:9090/path?arg1=value1&arg2=value2#anchor',
				array(
					'scheme'   => 'http',
					'host'     => 'host.name',
					'port'     => 9090,
					'user'     => 'username',
					'pass'     => 'password',
					'path'     => '/path',
					'query'    => 'arg1=value1&arg2=value2',
					'fragment' => 'anchor',
				),
			),
			'schemeless'       => array( '//example.com/path/', array( 'host' => 'example.com', 'path' => '/path/' ) ),
			'repeated-slashes' => array( 'http://example.com//path/', array( 'scheme' => 'http', 'host' => 'example.com', 'path' => '//path/' ) ),
			'colon-path'       => array( '/path/http://example.net/', array( 'path' => '/path/http://example.net/' ) ),
			'path-only-colon'  => array( '/://example.com/', array( 'path' => '/://example.com/' ) ),
			'query-only'       => array( '?only=query', array( 'query' => 'only=query' ) ),
			'ipv6'             => array( '//[::FFFF::127.0.0.1]/', array( 'host' => '[::FFFF::127.0.0.1]', 'path' => '/' ) ),
			'empty'            => array( '', array( 'path' => '' ) ),
			'generated'        => array(
				'https://example.test/' . implode( '/', array_map( 'rawurlencode', $case['pathSegments'] ) ) . '?q=' . rawurlencode( $case['suspiciousQuery'] ),
				null,
			),
		);

		$failures = array();
		foreach ( $cases as $name => $entry ) {
			list( $url, $expected ) = $entry;
			$actual = \wp_parse_url( $url );
			if ( null !== $expected && $expected !== $actual ) {
				$failures[] = array(
					'name'     => $name,
					'url'      => $url,
					'expected' => $expected,
					'actual'   => $actual,
				);
				continue;
			}

			if ( is_array( $actual ) && ! self::component_calls_match_parsed_url( $url, $actual ) ) {
				$failures[] = array(
					'name'   => $name,
					'url'    => $url,
					'actual' => $actual,
					'reason' => 'Component-specific wp_parse_url() calls did not match the full parsed array.',
				);
			}
		}

		return $ctx->result(
			'rewrite.wp-parse-url.documented-edge-behavior',
			array() === $failures,
			self::case_data( $case ) + array(
				'checked'  => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_home_site_urls( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$path          = 'route/' . rawurlencode( $case['unicodeSlug'] ) . '//leaf';
		$home_https    = \home_url( $path, 'https' );
		$home_relative = \home_url( '/' . $path, 'relative' );
		$site_http     = \site_url( $path, 'http' );
		$site_https    = \site_url( '', 'https' );

		$ok = array(
			'homeHttps'    => self::HOME_URL . '/' . $path === $home_https,
			'homeRelative' => '/site-base/' . $path === $home_relative,
			'siteHttp'     => self::SITE_URL . '/' . $path === $site_http,
			'siteHttps'    => 'https://example.test/wp' === $site_https,
			'homeHost'     => 'example.test' === \wp_parse_url( $home_https, PHP_URL_HOST ),
			'sitePath'     => '/wp/' . $path === \wp_parse_url( $site_http, PHP_URL_PATH ),
		);

		return $ctx->result(
			'rewrite.home-site-url.scheme-relative-paths',
			! in_array( false, $ok, true ),
			self::case_data( $case ) + array(
				'checks'       => $ok,
				'path'         => $path,
				'homeHttps'    => $home_https,
				'homeRelative' => $home_relative,
				'siteHttp'     => $site_http,
				'siteHttps'    => $site_https,
			)
		);
	}

	private static function check_url_to_postid_cheap_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp_rewrite;

		$ids = array(
			'p'             => 79 + $ctx->iteration(),
			'page_id'       => 1000 + $ctx->iteration(),
			'attachment_id' => 2000 + $ctx->iteration(),
		);

		$observed = array();
		foreach ( $ids as $query_key => $id ) {
			$observed[ $query_key ] = \url_to_postid( \home_url( '/?' . $query_key . '=' . $id . '&ignored=' . $case['token'] ) );
		}

		$offsite = \url_to_postid( 'https://elsewhere.test/?p=99999' );

		$old_structure = $wp_rewrite->permalink_structure;
		$old_rules     = $wp_rewrite->rules ?? null;
		$wp_rewrite->permalink_structure = '';
		$wp_rewrite->rules               = array();
		$plain = \url_to_postid( \home_url( '/plain/path/' . rawurlencode( $case['unicodeSlug'] ) ) );
		$wp_rewrite->permalink_structure = $old_structure;
		$wp_rewrite->rules               = $old_rules;

		$ok = $ids === $observed && 0 === $offsite && 0 === $plain;

		return $ctx->result(
			'rewrite.url-to-postid.no-db-early-paths',
			$ok,
			self::case_data( $case ) + array(
				'expectedIds' => $ids,
				'observedIds' => $observed,
				'offsite'     => $offsite,
				'plain'       => $plain,
				'note'        => 'Pretty permalink resolution is intentionally not exercised because it instantiates WP_Query.',
			)
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::safe_token( $ctx->identifier( 4, 10 ) );

		$unicode_slugs = array( 'cafe-%C3%A9', 'niño', '雪', 'مرحبا', 'emoji-%F0%9F%99%82' );
		$path_segments = array(
			'plain-' . $token,
			'percent%2Fencoded',
			'a+b=c&d',
			'regex.()+[]{}',
			$ctx->choice( $unicode_slugs ),
		);

		return array(
			'token'              => $token,
			'customTag'          => '%cfuzz_' . $token . '%',
			'customQueryVar'     => 'cfuzz_' . $token,
			'permalinkStructure' => $ctx->choice(
				array(
					'/%year%/%monthnum%/%day%/%postname%/',
					'/blog/%category%/%postname%/',
					'/%post_id%/%postname%/',
					'/author/%author%/',
					'/search/%search%/',
					'/index.php/%year%/%postname%/',
					'/tag/%tag%/',
				)
			),
			'unicodeSlug'        => $ctx->choice( $unicode_slugs ),
			'pathSegments'       => array_values( $path_segments ),
			'suspiciousQuery'    => $ctx->choice(
				array(
					'a=1&&b=%zz',
					'redirect=https%3A%2F%2Fevil.test%2F%3Fx%3D1',
					'arr[]=1&arr[deep]=2',
					'q=<script>alert(1)</script>',
					'empty=&repeat=1&repeat=2',
				)
			),
			'requestUri'         => '/seed-' . $ctx->iteration() . '/?q=' . rawurlencode( $token ),
		);
	}

	private static function permastruct_corpus( array $case ): array {
		return array(
			'post/%postname%',
			'page/%pagename%',
			'category/%category%',
			'tag/%tag%',
			'%year%/%monthnum%/%day%/%postname%',
			'author/%author%',
			'search/%search%',
			'custom//' . $case['customTag'] . '/%postname%',
		);
	}

	private static function install_option_filters( array $case ): void {
		$options = array(
			'home'                => self::HOME_URL,
			'siteurl'             => self::SITE_URL,
			'permalink_structure' => $case['permalinkStructure'],
			'page_on_front'       => 0,
			'show_on_front'       => 'posts',
		);

		foreach ( $options as $option => $value ) {
			\add_filter(
				'pre_option_' . $option,
				static function ( $pre_option = false, $option_name = '', $default_value = false ) use ( $value ) {
					return $value;
				},
				0,
				3
			);
		}
	}

	private static function prepare_globals(): void {
		$GLOBALS['wp'] = new \WP();
		if ( ! isset( $GLOBALS['wp_post_types'] ) || ! is_array( $GLOBALS['wp_post_types'] ) ) {
			$GLOBALS['wp_post_types'] = array();
		}

		$GLOBALS['wp_rewrite'] = self::new_rewrite();

		$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
		$_SERVER['PHP_SELF']    = $_SERVER['PHP_SELF'] ?? '/index.php';
		$_SERVER['HTTPS']       = $_SERVER['HTTPS'] ?? 'off';
	}

	private static function new_rewrite(): \WP_Rewrite {
		$rewrite = new \WP_Rewrite();
		self::add_core_like_tags( $rewrite );
		return $rewrite;
	}

	private static function add_core_like_tags( \WP_Rewrite $rewrite ): void {
		$rewrite->add_rewrite_tag( '%category%', '(.+?)', 'category_name=' );
		$rewrite->add_rewrite_tag( '%tag%', '([^/]+)', 'tag=' );
	}

	private static function add_surface_tags( \WP_Rewrite $rewrite, array $case ): void {
		$rewrite->add_rewrite_tag( $case['customTag'], '([^/]+)', $case['customQueryVar'] . '=' );
	}

	private static function rules_have_query_vars( array $rules, array $vars ): bool {
		$vars = array_values( array_filter( array_unique( $vars ), 'strlen' ) );
		if ( array() === $vars ) {
			return true;
		}

		foreach ( $rules as $query ) {
			$found_all = true;
			foreach ( $vars as $var ) {
				if ( ! str_contains( (string) $query, $var . '=' ) ) {
					$found_all = false;
					break;
				}
			}
			if ( $found_all ) {
				return true;
			}
		}

		return false;
	}

	private static function first_matching_rule_with_query_vars( array $rules, array $vars, string $path, array &$matches ): ?array {
		foreach ( $rules as $regex => $query ) {
			$found_all = true;
			foreach ( $vars as $var ) {
				if ( ! str_contains( (string) $query, $var . '=' ) ) {
					$found_all = false;
					break;
				}
			}

			if ( $found_all && 1 === preg_match( '#^' . $regex . '#', $path, $matches ) ) {
				return array(
					'regex' => (string) $regex,
					'query' => (string) $query,
				);
			}
		}

		return null;
	}

	private static function rules_have_endpoint_query( array $rules, string $endpoint, string $query_var ): bool {
		foreach ( $rules as $regex => $query ) {
			if ( ! str_contains( (string) $regex, $endpoint ) ) {
				continue;
			}

			if ( '' === $query_var || str_contains( (string) $query, '&' . $query_var . '=' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function rules_have_literal_query( array $rules, string $literal ): bool {
		foreach ( $rules as $query ) {
			if ( str_contains( (string) $query, $literal ) ) {
				return true;
			}
		}

		return false;
	}

	private static function rules_preserve_subset( array $expected_subset, array $rules ): bool {
		foreach ( $expected_subset as $regex => $query ) {
			if ( ! array_key_exists( $regex, $rules ) || $rules[ $regex ] !== $query ) {
				return false;
			}
		}

		return true;
	}

	private static function rules_contain_any( array $rules, array $needles ): bool {
		foreach ( $rules as $regex => $query ) {
			foreach ( $needles as $needle ) {
				if ( str_contains( (string) $regex, $needle ) || str_contains( (string) $query, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function rules_contain_unexpanded_tags( array $rules ): bool {
		foreach ( $rules as $regex => $query ) {
			if ( 1 === preg_match( '/%[A-Za-z0-9_]+%/', (string) $regex . ' ' . (string) $query ) ) {
				return true;
			}
		}

		return false;
	}

	private static function query_vars_for_struct( \WP_Rewrite $rewrite, string $struct ): array {
		preg_match_all( '/%[^%]+%/', $struct, $matches );
		if ( empty( $matches[0] ) ) {
			return array();
		}

		$map = array();
		foreach ( $rewrite->rewritecode as $index => $tag ) {
			$query = $rewrite->queryreplace[ $index ] ?? '';
			$var   = rtrim( (string) $query, '=' );
			if ( '' !== $var ) {
				$map[ $tag ] = $var;
			}
		}

		$vars = array();
		foreach ( array_unique( $matches[0] ) as $tag ) {
			if ( isset( $map[ $tag ] ) ) {
				$vars[] = $map[ $tag ];
			}
		}

		return $vars;
	}

	private static function component_calls_match_parsed_url( string $url, array $parts ): bool {
		$components = array(
			PHP_URL_SCHEME   => 'scheme',
			PHP_URL_HOST     => 'host',
			PHP_URL_PORT     => 'port',
			PHP_URL_USER     => 'user',
			PHP_URL_PASS     => 'pass',
			PHP_URL_PATH     => 'path',
			PHP_URL_QUERY    => 'query',
			PHP_URL_FRAGMENT => 'fragment',
		);

		foreach ( $components as $component => $key ) {
			$expected = $parts[ $key ] ?? null;
			if ( $expected !== \wp_parse_url( $url, $component ) ) {
				return false;
			}
		}

		return true;
	}

	private static function array_has_unique_keys( array $array ): bool {
		$keys = array_keys( $array );
		return count( $keys ) === count( array_unique( $keys ) );
	}

	private static function sample_assoc( array $array, int $limit = 3 ): array {
		$sample = array();
		foreach ( $array as $key => $value ) {
			$sample[ $key ] = $value;
			if ( count( $sample ) >= $limit ) {
				break;
			}
		}

		return $sample;
	}

	private static function snapshot_globals(): array {
		$globals = array();
		foreach (
			array(
				'wp',
				'wp_rewrite',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'wp_post_types',
				'wp_object_cache',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'_SERVER' => $_SERVER,
			'_GET'    => $_GET,
		);
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		$_SERVER = $snapshot['_SERVER'];
		$_GET    = $snapshot['_GET'];
	}

	private static function case_data( array $case ): array {
		return array(
			'token'              => $case['token'],
			'permalinkStructure' => $case['permalinkStructure'],
			'customTag'          => $case['customTag'],
			'customQueryVar'     => $case['customQueryVar'],
			'unicodeSlug'        => $case['unicodeSlug'],
			'suspiciousQuery'    => $case['suspiciousQuery'],
		);
	}

	private static function safe_token( string $value ): string {
		$value = strtolower( preg_replace( '/[^A-Za-z0-9_]+/', '_', $value ) ?? '' );
		$value = trim( $value, '_' );

		return '' === $value ? 'x' : $value;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function first_value_difference( $expected, $actual, string $path = '$' ): ?array {
		if ( gettype( $expected ) !== gettype( $actual ) ) {
			return array(
				'path'     => $path,
				'expected' => gettype( $expected ),
				'actual'   => gettype( $actual ),
			);
		}

		if ( is_array( $expected ) ) {
			$keys = array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) );
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $expected ) || ! array_key_exists( $key, $actual ) ) {
					return array(
						'path'     => $path . '[' . var_export( $key, true ) . ']',
						'expected' => array_key_exists( $key, $expected ) ? 'present' : 'missing',
						'actual'   => array_key_exists( $key, $actual ) ? 'present' : 'missing',
					);
				}

				$difference = self::first_value_difference( $expected[ $key ], $actual[ $key ], $path . '[' . var_export( $key, true ) . ']' );
				if ( null !== $difference ) {
					return $difference;
				}
			}

			return null;
		}

		if ( is_object( $expected ) ) {
			if ( $expected !== $actual ) {
				return array(
					'path'     => $path,
					'expected' => '[object ' . get_class( $expected ) . ']',
					'actual'   => '[object ' . get_class( $actual ) . ']',
				);
			}

			return null;
		}

		if ( $expected !== $actual ) {
			return array(
				'path'     => $path,
				'expected' => $expected,
				'actual'   => $actual,
			);
		}

		return null;
	}
}
