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
			$rows[] = self::check_verbose_page_rules_and_root_boundaries( $ctx, $case );
			$rows[] = self::check_rule_precedence_and_storage( $ctx, $case );
			$rows[] = self::check_rule_collision_ordering( $ctx, $case );
			$rows[] = self::check_query_substitution( $ctx, $case );
			$rows[] = self::check_feed_pagination_embed_variants( $ctx, $case );
			$rows[] = self::check_custom_permastruct_variants( $ctx, $case );
			$rows[] = self::check_endpoints( $ctx, $case );
			$rows[] = self::check_endpoint_masks_and_query_vars( $ctx, $case );
			$rows[] = self::check_matches_map_regex_edges( $ctx, $case );
			$rows[] = self::check_query_arg_helpers( $ctx, $case );
			$rows[] = self::check_build_query_parse_agreement( $ctx, $case );
			$rows[] = self::check_wp_parse_url_edges( $ctx, $case );
			$rows[] = self::check_home_site_urls( $ctx, $case );
			$rows[] = self::check_weird_path_fragments_no_throw( $ctx, $case );
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
			$restored      = self::snapshots_match( $snapshot, $after_restore );
			$rows[]        = $ctx->result(
				'rewrite.globals-restored',
				$restored,
				array(
					'trackedGlobals' => array_keys( $snapshot['globals'] ),
					'difference'     => $restored ? null : self::first_value_difference( $snapshot, $after_restore ),
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

	private static function check_verbose_page_rules_and_root_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$verbose_rewrite = self::new_rewrite_for_structure( '/%postname%/', $case );
		$page_rules      = $verbose_rewrite->page_rewrite_rules();
		$date_rewrite    = self::new_rewrite_for_structure( '/%year%/%postname%/', $case );
		$slashless       = self::new_rewrite_for_structure( '/news/%postname%', $case );

		$index_rewrite = self::new_rewrite_for_structure( '/index.php/articles/%postname%/', $case );
		$index_rewrite->add_permastruct(
			'with_front_' . $case['token'],
			'genre/%postname%',
			array(
				'with_front' => true,
				'ep_mask'    => EP_NONE,
				'paged'      => false,
				'feed'       => false,
				'walk_dirs'  => false,
				'endpoints'  => false,
			)
		);
		$index_rewrite->add_permastruct(
			'root_only_' . $case['token'],
			'genre/%postname%',
			array(
				'with_front' => false,
				'ep_mask'    => EP_NONE,
				'paged'      => false,
				'feed'       => false,
				'walk_dirs'  => false,
				'endpoints'  => false,
			)
		);

		$with_front = $index_rewrite->extra_permastructs[ 'with_front_' . $case['token'] ] ?? array();
		$root_only  = $index_rewrite->extra_permastructs[ 'root_only_' . $case['token'] ] ?? array();
		$with_rules = isset( $with_front['struct'] )
			? $index_rewrite->generate_rewrite_rules( $with_front['struct'], EP_NONE, false, false, false, false, false )
			: array();
		$root_rules = isset( $root_only['struct'] )
			? $index_rewrite->generate_rewrite_rules( $root_only['struct'], EP_NONE, false, false, false, false, false )
			: array();

		$checks = array(
			'verbosePostnameEnabled' => true === $verbose_rewrite->use_verbose_page_rules,
			'dateFirstNotVerbose'    => false === $date_rewrite->use_verbose_page_rules,
			'pageRulesGenerated'     => array() !== $page_rules
				&& count( $page_rules ) <= self::MAX_RULES
				&& self::rules_have_query_vars( $page_rules, array( 'pagename' ) )
				&& ! self::rules_contain_unexpanded_tags( $page_rules ),
			'trailingSlashFlag'      => true === $verbose_rewrite->use_trailing_slashes
				&& false === $slashless->use_trailing_slashes,
			'indexRootDetected'      => 'index.php/' === $index_rewrite->root
				&& '/index.php/articles/' === $index_rewrite->front
				&& $index_rewrite->using_index_permalinks(),
			'withFrontStruct'        => '/index.php/articles/genre/%postname%' === ( $with_front['struct'] ?? null ),
			'rootOnlyStruct'         => 'index.php/genre/%postname%' === ( $root_only['struct'] ?? null ),
			'withFrontRules'         => self::rules_have_key_prefix( $with_rules, 'index.php/articles/genre/' )
				&& self::rules_have_query_vars( $with_rules, array( 'name' ) ),
			'rootOnlyRules'          => self::rules_have_key_prefix( $root_rules, 'index.php/genre/' )
				&& self::rules_have_query_vars( $root_rules, array( 'name' ) ),
		);

		return $ctx->result(
			'rewrite.verbose-page-rules-root-index-boundaries',
			! in_array( false, $checks, true ),
			self::case_data( $case ) + array(
				'checks'          => $checks,
				'pageRuleSample'  => self::sample_assoc( $page_rules ),
				'withFrontStruct' => $with_front['struct'] ?? null,
				'rootOnlyStruct'  => $root_only['struct'] ?? null,
				'withRuleSample'  => self::sample_assoc( $with_rules ),
				'rootRuleSample'  => self::sample_assoc( $root_rules ),
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

	private static function check_rule_collision_ordering( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp_rewrite;

		$query_var = $case['customQueryVar'];

		$top_a            = '^cfuzz-collision-top-a-' . $case['token'] . '/?$';
		$top_b            = '^cfuzz-collision-top-b-' . $case['token'] . '/?$';
		$bottom_a         = '^cfuzz-collision-bottom-a-' . $case['token'] . '/?$';
		$top_collision    = '^cfuzz-collision-top-' . $case['token'] . '/?$';
		$bottom_collision = '^cfuzz-collision-bottom-' . $case['token'] . '/?$';
		$cross_collision  = '^cfuzz-collision-cross-' . $case['token'] . '/?$';

		\add_rewrite_rule( $top_a, 'index.php?' . $query_var . '=top-a', 'top' );
		\add_rewrite_rule( $top_b, 'index.php?' . $query_var . '=top-b', 'top' );
		\add_rewrite_rule( $bottom_a, 'index.php?' . $query_var . '=bottom-a', 'bottom' );

		\add_rewrite_rule( $top_collision, 'index.php?' . $query_var . '=top-first', 'top' );
		\add_rewrite_rule( $top_collision, 'index.php?' . $query_var . '=top-second', 'top' );
		\add_rewrite_rule( $bottom_collision, 'index.php?' . $query_var . '=bottom-first', 'bottom' );
		\add_rewrite_rule( $bottom_collision, 'index.php?' . $query_var . '=bottom-second', 'bottom' );
		\add_rewrite_rule( $cross_collision, 'index.php?' . $query_var . '=cross-bottom', 'bottom' );
		\add_rewrite_rule( $cross_collision, 'index.php?' . $query_var . '=cross-top', 'top' );

		$rules       = $wp_rewrite->rewrite_rules();
		$rule_keys   = array_keys( $rules );
		$top_a_pos   = array_search( $top_a, $rule_keys, true );
		$top_b_pos   = array_search( $top_b, $rule_keys, true );
		$bottom_pos  = array_search( $bottom_a, $rule_keys, true );
		$cross_query = $rules[ $cross_collision ] ?? null;

		$checks = array(
			'topBeforeBottom'      => false !== $top_a_pos
				&& false !== $top_b_pos
				&& false !== $bottom_pos
				&& $top_a_pos < $top_b_pos
				&& $top_b_pos < $bottom_pos,
			'topCollisionLastWins' => 'index.php?' . $query_var . '=top-second' === ( $wp_rewrite->extra_rules_top[ $top_collision ] ?? null ),
			'bottomCollisionLastWins' => 'index.php?' . $query_var . '=bottom-second' === ( $wp_rewrite->extra_rules[ $bottom_collision ] ?? null ),
			'crossCollisionStoredBothBuckets' => isset( $wp_rewrite->extra_rules_top[ $cross_collision ], $wp_rewrite->extra_rules[ $cross_collision ] ),
			'crossCollisionBottomWinsInCompiledRules' => 'index.php?' . $query_var . '=cross-bottom' === $cross_query,
		);

		return $ctx->result(
			'rewrite.rules.collision-ordering-and-overwrite-behavior',
			! in_array( false, $checks, true ),
			self::case_data( $case ) + array(
				'checks'         => $checks,
				'topPositions'   => array(
					'topA'   => $top_a_pos,
					'topB'   => $top_b_pos,
					'bottom' => $bottom_pos,
				),
				'topCollision'   => $wp_rewrite->extra_rules_top[ $top_collision ] ?? null,
				'bottomCollision' => $wp_rewrite->extra_rules[ $bottom_collision ] ?? null,
				'crossCollision' => array(
					'top'      => $wp_rewrite->extra_rules_top[ $cross_collision ] ?? null,
					'bottom'   => $wp_rewrite->extra_rules[ $cross_collision ] ?? null,
					'compiled' => $cross_query,
				),
			)
		);
	}

	private static function check_query_substitution( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$rewrite = self::new_rewrite();
		$rewrite->matches = 'matches';
		self::add_surface_tags( $rewrite, $case );

		$segment_a = $case['pathSegments'][0];
		$segment_b = $case['pathSegments'][1];
		$segment_c = (string) $ctx->int( 100, 999 );
		$struct    = 'route/%year%/' . $case['customTag'] . '/' . $case['numericTag'] . '/%postname%';
		$rules     = $rewrite->generate_rewrite_rules( $struct, EP_NONE, false, false, false, false, false );
		$path      = 'route/2025/' . $segment_a . '/' . $segment_c . '/' . $segment_b . '/';
		$matched   = self::substituted_query_vars( $rules, array( 'year', $case['customQueryVar'], $case['numericQueryVar'], 'name' ), $path );
		$template  = $matched['template'];
		$vars      = $matched['vars'];

		$ok = null !== $template
			&& isset( $vars['year'], $vars[ $case['customQueryVar'] ], $vars[ $case['numericQueryVar'] ], $vars['name'] )
			&& '2025' === $vars['year']
			&& $segment_a === $vars[ $case['customQueryVar'] ]
			&& $segment_c === $vars[ $case['numericQueryVar'] ]
			&& $segment_b === $vars['name'];

		return $ctx->result(
			'rewrite.matches.query-template-substitution',
			$ok,
			self::case_data( $case ) + array(
				'struct'       => $struct,
				'path'         => $path,
				'pathSegments' => array( '2025', $segment_a, $segment_c, $segment_b ),
				'template'     => $template,
				'matches'      => $matched['matches'],
				'vars'         => $vars,
				'ruleSample'   => self::sample_assoc( $rules ),
			)
		);
	}

	private static function check_feed_pagination_embed_variants( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$rewrite                  = self::new_rewrite();
		$rewrite->matches         = 'matches';
		$rewrite->pagination_base = 'p' . $case['token'];
		$rewrite->feed_base       = 'feed' . $case['token'];

		$struct         = 'calendar/%year%/%monthnum%/%postname%';
		$rules          = $rewrite->generate_rewrite_rules( $struct, EP_PERMALINK | EP_DATE, true, true, false, true, true );
		$comments_rules = $rewrite->generate_rewrite_rules( $struct, EP_PERMALINK | EP_DATE, true, true, true, true, true );

		$feed_path  = 'calendar/2024/07/' . $case['pathSegments'][0] . '/' . $rewrite->feed_base . '/rss2/';
		$paged_path = 'calendar/2024/07/' . $case['pathSegments'][0] . '/' . $rewrite->pagination_base . '/3/';
		$embed_path = 'calendar/2024/07/' . $case['pathSegments'][0] . '/embed/';

		$feed_match  = self::substituted_query_vars( $rules, array( 'year', 'monthnum', 'name', 'feed' ), $feed_path );
		$paged_match = self::substituted_query_vars( $rules, array( 'year', 'monthnum', 'name', 'paged' ), $paged_path );
		$embed_match = self::substituted_query_vars( $rules, array( 'year', 'monthnum', 'name', 'embed' ), $embed_path );

		$checks = array(
			'ruleBounded'       => count( $rules ) <= self::MAX_RULES,
			'feedQueryVars'     => isset( $feed_match['vars']['year'], $feed_match['vars']['monthnum'], $feed_match['vars']['name'], $feed_match['vars']['feed'] )
				&& '2024' === $feed_match['vars']['year']
				&& '07' === $feed_match['vars']['monthnum']
				&& $case['pathSegments'][0] === $feed_match['vars']['name']
				&& 'rss2' === $feed_match['vars']['feed'],
			'pagedQueryVars'    => isset( $paged_match['vars']['paged'] )
				&& '3' === $paged_match['vars']['paged'],
			'embedQueryVars'    => isset( $embed_match['vars']['embed'] )
				&& 'true' === $embed_match['vars']['embed'],
			'commentsFeedRules' => self::rules_have_literal_query( $comments_rules, 'withcomments=1' ),
			'unexpandedTags'    => ! self::rules_contain_unexpanded_tags( $rules ),
		);

		return $ctx->result(
			'rewrite.feed-pagination-embed-variants-substitute',
			! in_array( false, $checks, true ),
			self::case_data( $case ) + array(
				'checks'            => $checks,
				'struct'            => $struct,
				'feedPath'          => $feed_path,
				'pagedPath'         => $paged_path,
				'embedPath'         => $embed_path,
				'feedMatch'         => $feed_match,
				'pagedMatch'        => $paged_match,
				'embedMatch'        => $embed_match,
				'ruleSample'        => self::sample_assoc( $rules ),
				'commentRuleSample' => self::sample_assoc( $comments_rules ),
			)
		);
	}

	private static function check_custom_permastruct_variants( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$rewrite                           = self::new_rewrite();
		$rewrite->matches                  = 'matches';
		$rewrite->pagination_base          = 'pg' . $case['token'];
		$rewrite->feed_base                = 'feed' . $case['token'];
		$rewrite->comments_pagination_base = 'comments' . $case['token'];
		self::add_surface_tags( $rewrite, $case );

		$permastruct_name = 'variant_' . $case['token'];
		$rewrite->add_permastruct(
			$permastruct_name,
			'library/' . $case['customTag'] . '/' . $case['numericTag'] . '/%postname%',
			array(
				'with_front'  => false,
				'ep_mask'     => EP_PERMALINK | EP_PAGES,
				'paged'       => true,
				'feed'        => true,
				'forcomments' => true,
				'walk_dirs'   => false,
				'endpoints'   => true,
			)
		);

		$stored = $rewrite->extra_permastructs[ $permastruct_name ] ?? null;
		$rules  = is_array( $stored ) ? self::rules_from_permastruct( $rewrite, $stored ) : array();

		$custom_value = $case['pathSegments'][2];
		$post_value   = $case['pathSegments'][3];
		$number_value = (string) $ctx->int( 100, 999 );
		$base_prefix  = is_array( $stored ) ? self::struct_prefix_before_tag( $stored['struct'], $case['customTag'], 'library/' ) : 'library/';
		$base_path    = $base_prefix . $custom_value . '/' . $number_value . '/' . $post_value;

		$feed_path         = $base_path . '/' . $rewrite->feed_base . '/atom/';
		$short_feed_path   = $base_path . '/rss2/';
		$paged_path        = $base_path . '/' . $rewrite->pagination_base . '/7/';
		$comment_page_path = $base_path . '/' . $rewrite->comments_pagination_base . '-5/';
		$post_page_path    = $base_path . '/4/';

		$expected_common = array(
			$case['customQueryVar']  => $custom_value,
			$case['numericQueryVar'] => $number_value,
			'name'                   => $post_value,
		);

		$feed_match         = self::substituted_query_vars( $rules, array( $case['customQueryVar'], $case['numericQueryVar'], 'name', 'feed' ), $feed_path );
		$short_feed_match   = self::substituted_query_vars( $rules, array( $case['customQueryVar'], $case['numericQueryVar'], 'name', 'feed' ), $short_feed_path );
		$paged_match        = self::substituted_query_vars( $rules, array( $case['customQueryVar'], $case['numericQueryVar'], 'name', 'paged' ), $paged_path );
		$comment_page_match = self::substituted_query_vars( $rules, array( $case['customQueryVar'], $case['numericQueryVar'], 'name', 'cpage' ), $comment_page_path );
		$post_page_match    = self::substituted_query_vars( $rules, array( $case['customQueryVar'], $case['numericQueryVar'], 'name', 'page' ), $post_page_path );

		$checks = array(
			'storedArgs'          => is_array( $stored )
				&& false === $stored['with_front']
				&& true === $stored['forcomments']
				&& false === $stored['walk_dirs'],
			'ruleBounded'         => array() !== $rules && count( $rules ) <= self::MAX_RULES,
			'feedBaseVariant'     => self::vars_match_subset( $feed_match['vars'], $expected_common + array( 'feed' => 'atom', 'withcomments' => '1' ) ),
			'shortFeedVariant'    => self::vars_match_subset( $short_feed_match['vars'], $expected_common + array( 'feed' => 'rss2', 'withcomments' => '1' ) ),
			'pagedVariant'        => self::vars_match_subset( $paged_match['vars'], $expected_common + array( 'paged' => '7' ) ),
			'commentPageVariant'  => self::vars_match_subset( $comment_page_match['vars'], $expected_common + array( 'cpage' => '5' ) ),
			'postPageVariant'     => self::vars_match_subset( $post_page_match['vars'], $expected_common + array( 'page' => '4' ) ),
			'unexpandedTags'      => ! self::rules_contain_unexpanded_tags( $rules ),
		);

		return $ctx->result(
			'rewrite.permastruct.custom-feed-paged-comment-page-variants',
			! in_array( false, $checks, true ),
			self::case_data( $case ) + array(
				'checks'          => $checks,
				'stored'          => $stored,
				'basePath'        => $base_path,
				'feedMatch'       => $feed_match,
				'shortFeedMatch'  => $short_feed_match,
				'pagedMatch'      => $paged_match,
				'commentPageMatch' => $comment_page_match,
				'postPageMatch'   => $post_page_match,
				'ruleSample'      => self::sample_assoc( $rules ),
			)
		);
	}

	private static function check_endpoints( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp, $wp_rewrite;

		$json_var  = 'json_' . $case['token'];
		$embed_var = 'embed_' . $case['token'];
		$raw_name  = 'rawjson_' . $case['token'];
		$root_name = 'rooted_' . $case['token'];
		$root_var  = 'root_ep_' . $case['token'];

		\add_rewrite_endpoint( 'json', EP_PERMALINK | EP_PAGES, $json_var );
		\add_rewrite_endpoint( 'embed', EP_PERMALINK, $embed_var );
		\add_rewrite_endpoint( $raw_name, EP_PERMALINK, false );
		\add_rewrite_endpoint( $root_name, EP_ROOT, $root_var );

		$wp_rewrite->matches = 'matches';

		$struct     = 'article/%postname%';
		$without    = $wp_rewrite->generate_rewrite_rules( $struct, EP_PERMALINK, true, true, false, false, false );
		$with       = $wp_rewrite->generate_rewrite_rules( $struct, EP_PERMALINK, true, true, false, false, true );
		$root       = $wp_rewrite->generate_rewrite_rules( '/', EP_ROOT, false, false, false, false, true );
		$json_endpoint_value = 'tail-' . $case['token'];
		$root_endpoint_value = 'root-' . $case['token'];
		$json_path  = 'article/' . $case['pathSegments'][0] . '/json/' . $json_endpoint_value;
		$json_match = self::substituted_query_vars( $with, array( 'name', $json_var ), $json_path );
		$root_path  = $root_name . '/' . $root_endpoint_value;
		$root_match = self::substituted_query_vars( $root, array( $root_var ), $root_path );
		$base_ok    = self::rules_preserve_subset( $without, $with );
		$json_ok    = self::rules_have_endpoint_query( $with, 'json', $json_var );
		$embed_ok   = self::rules_have_endpoint_query( $with, 'embed', $embed_var );
		$raw_ok     = ! in_array( $raw_name, $wp->public_query_vars, true )
			&& self::rules_have_endpoint_query( $with, $raw_name, '' );
		$root_checks = array(
			'publicQueryVar'      => in_array( $root_var, $wp->public_query_vars, true ),
			'rootRulePresent'     => self::rules_have_endpoint_query( $root, $root_name, $root_var ),
			'absentFromPermalink' => ! self::rules_have_endpoint_query( $with, $root_name, $root_var ),
			'valueSubstituted'    => isset( $root_match['vars'][ $root_var ] )
				&& $root_endpoint_value === $root_match['vars'][ $root_var ],
		);
		$json_checks = array(
			'nameSubstituted'     => isset( $json_match['vars']['name'] )
				&& $case['pathSegments'][0] === $json_match['vars']['name'],
			'endpointSubstituted' => isset( $json_match['vars'][ $json_var ] )
				&& $json_endpoint_value === $json_match['vars'][ $json_var ],
		);
		$root_ok     = ! in_array( false, $root_checks, true );
		$json_substitution_ok = ! in_array( false, $json_checks, true );
		$feed_ok       = self::rules_have_query_vars( $with, array( 'feed' ) );
		$core_embed_ok = self::rules_have_literal_query( $with, 'embed=true' );

		$ok = $base_ok
			&& $json_ok
			&& $embed_ok
			&& $raw_ok
			&& $root_ok
			&& $json_substitution_ok
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
				'rootEndpointName' => $root_name,
				'baseRuleCount'    => count( $without ),
				'endpointRuleCount' => count( $with ),
				'rootRuleCount'    => count( $root ),
				'basePreserved'    => $base_ok,
				'jsonEndpoint'     => $json_ok,
				'embedEndpoint'    => $embed_ok,
				'rawEndpointQuery' => $raw_ok,
				'rootEndpoint'     => $root_ok,
				'rootChecks'       => $root_checks,
				'jsonChecks'       => $json_checks,
				'jsonMatch'        => $json_match,
				'rootMatch'        => $root_match,
				'feedRules'        => $feed_ok,
				'coreEmbedRules'   => $core_embed_ok,
				'ruleSample'       => self::sample_assoc( $with ),
			)
		);
	}

	private static function check_endpoint_masks_and_query_vars( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp;

		$rewrite          = self::new_rewrite();
		$rewrite->matches = 'matches';
		self::add_surface_tags( $rewrite, $case );

		$year_endpoint       = 'year_ep_' . $case['token'];
		$month_endpoint      = 'month_ep_' . $case['token'];
		$day_endpoint        = 'day_ep_' . $case['token'];
		$page_endpoint       = 'page_ep_' . $case['token'];
		$permalink_endpoint  = 'permalink_ep_' . $case['token'];
		$attachment_endpoint = 'attachment_ep_' . $case['token'];
		$root_endpoint       = 'root_ep_' . $case['token'];
		$false_endpoint      = 'false_ep_' . $case['token'];

		$page_var       = 'page_var_' . $case['token'];
		$permalink_var  = 'permalink_var_' . $case['token'];
		$attachment_var = 'attachment_var_' . $case['token'];
		$root_var       = 'root_var_' . $case['token'];

		$rewrite->add_endpoint( $year_endpoint, EP_YEAR );
		$rewrite->add_endpoint( $month_endpoint, EP_MONTH, null );
		$rewrite->add_endpoint( $day_endpoint, EP_DAY, false );
		$rewrite->add_endpoint( $page_endpoint, EP_PAGES, $page_var );
		$rewrite->add_endpoint( $permalink_endpoint, EP_PERMALINK, $permalink_var );
		$rewrite->add_endpoint( $attachment_endpoint, EP_ATTACHMENT, $attachment_var );
		$rewrite->add_endpoint( $root_endpoint, EP_ROOT, $root_var );
		$rewrite->add_endpoint( $false_endpoint, EP_PERMALINK, false );

		$date_rules      = $rewrite->generate_rewrite_rules( '%year%/%monthnum%/%day%/%postname%', EP_DATE | EP_PERMALINK, true, true, false, true, true );
		$page_rules      = $rewrite->generate_rewrite_rules( 'pages/%pagename%', EP_PAGES, true, true, false, false, true );
		$permalink_rules = $rewrite->generate_rewrite_rules( 'posts/%postname%', EP_PERMALINK, true, true, false, false, true );
		$root_rules      = $rewrite->generate_rewrite_rules( '/', EP_ROOT, false, false, false, false, true );

		$post_value       = $case['pathSegments'][0];
		$page_value       = $case['pathSegments'][1];
		$attachment_value = $case['pathSegments'][2];

		$year_match = self::substituted_query_vars(
			$date_rules,
			array( 'year', $year_endpoint ),
			'2024/' . $year_endpoint . '/tail-' . $case['token']
		);
		$month_match = self::substituted_query_vars(
			$date_rules,
			array( 'year', 'monthnum', $month_endpoint ),
			'2024/07/' . $month_endpoint . '/tail-' . $case['token']
		);
		$page_match = self::substituted_query_vars(
			$page_rules,
			array( 'pagename', $page_var ),
			'pages/' . $page_value . '/' . $page_endpoint . '/tail-' . $case['token']
		);
		$permalink_match = self::substituted_query_vars(
			$permalink_rules,
			array( 'name', $permalink_var ),
			'posts/' . $post_value . '/' . $permalink_endpoint . '/tail-' . $case['token']
		);
		$attachment_match = self::substituted_query_vars(
			$permalink_rules,
			array( 'attachment', $attachment_var ),
			'posts/' . $post_value . '/attachment/' . $attachment_value . '/' . $attachment_endpoint . '/tail-' . $case['token']
		);
		$root_match = self::substituted_query_vars(
			$root_rules,
			array( $root_var ),
			$root_endpoint . '/tail-' . $case['token']
		);

		$checks = array(
			'defaultQueryVarRegistered' => in_array( $year_endpoint, $wp->public_query_vars, true ),
			'nullQueryVarRegisteredAsName' => in_array( $month_endpoint, $wp->public_query_vars, true ),
			'falseQueryVarNotRegistered' => ! in_array( $day_endpoint, $wp->public_query_vars, true )
				&& ! in_array( $false_endpoint, $wp->public_query_vars, true ),
			'yearEndpointSpecificity' => self::vars_match_subset(
				$year_match['vars'],
				array(
					'year'          => '2024',
					$year_endpoint  => 'tail-' . $case['token'],
				)
			),
			'monthEndpointSpecificity' => self::vars_match_subset(
				$month_match['vars'],
				array(
					'year'           => '2024',
					'monthnum'       => '07',
					$month_endpoint  => 'tail-' . $case['token'],
				)
			),
			'dayFalseEndpointRulesPresent' => self::rules_have_endpoint_query( $date_rules, $day_endpoint, '' ),
			'falsePermalinkEndpointRulesPresent' => self::rules_have_endpoint_query( $permalink_rules, $false_endpoint, '' ),
			'pageEndpointSubstitution' => self::vars_match_subset(
				$page_match['vars'],
				array(
					'pagename' => $page_value,
					$page_var  => 'tail-' . $case['token'],
				)
			),
			'permalinkEndpointSubstitution' => self::vars_match_subset(
				$permalink_match['vars'],
				array(
					'name'          => $post_value,
					$permalink_var  => 'tail-' . $case['token'],
				)
			),
			'attachmentEndpointSubstitution' => self::vars_match_subset(
				$attachment_match['vars'],
				array(
					'attachment'    => $attachment_value,
					$attachment_var => 'tail-' . $case['token'],
				)
			),
			'rootEndpointSubstitution' => self::vars_match_subset(
				$root_match['vars'],
				array(
					$root_var => 'tail-' . $case['token'],
				)
			),
			'rootAbsentFromPermalinkRules' => ! self::rules_have_endpoint_query( $permalink_rules, $root_endpoint, $root_var ),
		);

		return $ctx->result(
			'rewrite.endpoints.mask-specific-query-var-propagation',
			! in_array( false, $checks, true ),
			self::case_data( $case ) + array(
				'checks'          => $checks,
				'yearMatch'       => $year_match,
				'monthMatch'      => $month_match,
				'pageMatch'       => $page_match,
				'permalinkMatch'  => $permalink_match,
				'attachmentMatch' => $attachment_match,
				'rootMatch'       => $root_match,
				'endpointNames'   => array(
					$year_endpoint,
					$month_endpoint,
					$day_endpoint,
					$page_endpoint,
					$permalink_endpoint,
					$attachment_endpoint,
					$root_endpoint,
					$false_endpoint,
				),
				'dateRuleSample'  => self::sample_assoc( $date_rules ),
				'pageRuleSample'  => self::sample_assoc( $page_rules ),
				'permalinkRuleSample' => self::sample_assoc( $permalink_rules ),
				'rootRuleSample'  => self::sample_assoc( $root_rules ),
			)
		);
	}

	private static function check_matches_map_regex_edges( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$matches = array(
			0  => 'whole-match',
			1  => 'a b',
			2  => 'a+b&c=d',
			3  => '/slash%percent',
			10 => 'ten=10&x=' . $case['token'],
		);

		$subject = 'one=$matches[1]&two=$matches[2]&three=$matches[3]&ten=$matches[10]&missing=$matches[9]&zero=$matches[0]&leading=$matches[01]&negative=$matches[-1]&again=$matches[1]';
		$mapped  = \WP_MatchesMapRegex::apply( $subject, $matches );
		$parsed  = array();
		\wp_parse_str( $mapped, $parsed );

		$checks = array(
			'reservedCharsUrlencoded' => isset( $parsed['two'] ) && $matches[2] === $parsed['two'],
			'slashPercentUrlencoded'  => isset( $parsed['three'] ) && $matches[3] === $parsed['three'],
			'twoDigitIndexSubstituted' => isset( $parsed['ten'] ) && $matches[10] === $parsed['ten'],
			'missingIndexBecomesEmpty' => array_key_exists( 'missing', $parsed ) && '' === $parsed['missing'],
			'zeroIndexLeftLiteral'    => isset( $parsed['zero'] ) && '$matches[0]' === $parsed['zero'],
			'leadingZeroLeftLiteral'  => isset( $parsed['leading'] ) && '$matches[01]' === $parsed['leading'],
			'negativeLeftLiteral'     => isset( $parsed['negative'] ) && '$matches[-1]' === $parsed['negative'],
			'repeatedIndexStable'     => isset( $parsed['one'], $parsed['again'] ) && $parsed['one'] === $parsed['again'],
			'noEligibleReferencesRemain' => ! str_contains( $mapped, '$matches[1]' )
				&& ! str_contains( $mapped, '$matches[2]' )
				&& ! str_contains( $mapped, '$matches[3]' )
				&& ! str_contains( $mapped, '$matches[10]' ),
		);

		return $ctx->result(
			'rewrite.matches-map-regex.substitution-edge-cases',
			! in_array( false, $checks, true ),
			self::case_data( $case ) + array(
				'checks' => $checks,
				'mapped' => $mapped,
				'parsed' => $parsed,
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

	private static function check_build_query_parse_agreement( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$raw_reserved = $case['suspiciousQuery'] . '&segment=' . $case['pathSegments'][2];
		$data         = array(
			'token'    => $case['token'],
			'unicode'  => rawurlencode( $case['unicodeSlug'] ),
			'reserved' => rawurlencode( $raw_reserved ),
			'empty'    => '',
			'zero'     => 0,
			'false'    => false,
			'null'     => null,
			'list'     => array(
				rawurlencode( $case['pathSegments'][0] ),
				rawurlencode( 'space value' ),
				null,
			),
			'nested'   => array(
				'leaf'  => rawurlencode( 'a=b&c=' . $case['token'] ),
				'false' => false,
				'zero'  => 0,
				'empty' => '',
				'null'  => null,
			),
		);

		$built  = \build_query( $data );
		$parsed = array();
		\wp_parse_str( $built, $parsed );

		$expected = self::expected_parse_for_build_query( $data );

		$unsafe_built  = \build_query( array( 'unsafe' => $raw_reserved ) );
		$unsafe_parsed = array();
		\wp_parse_str( $unsafe_built, $unsafe_parsed );

		$encoded_built  = \build_query( array( 'safe' => rawurlencode( $raw_reserved ) ) );
		$encoded_parsed = array();
		\wp_parse_str( $encoded_built, $encoded_parsed );

		$checks = array(
			'generatedRoundTrip'     => $expected == $parsed,
			'nullsOmitted'           => ! array_key_exists( 'null', $parsed )
				&& ! array_key_exists( 'null', $parsed['nested'] ?? array() ),
			'falseValuesBecomeZero'  => isset( $parsed['false'], $parsed['nested']['false'] )
				&& '0' === $parsed['false']
				&& '0' === $parsed['nested']['false'],
			'reservedRequiresEncoding' => ( $unsafe_parsed['unsafe'] ?? null ) !== $raw_reserved,
			'encodedReservedAgreement' => isset( $encoded_parsed['safe'] )
				&& $raw_reserved === $encoded_parsed['safe'],
		);

		return $ctx->result(
			'rewrite.query-args.build-query-parse-generated-agreement',
			! in_array( false, $checks, true ),
			self::case_data( $case ) + array(
				'checks'        => $checks,
				'built'         => $built,
				'parsed'        => $parsed,
				'expected'      => $expected,
				'unsafeBuilt'   => $unsafe_built,
				'unsafeParsed'  => $unsafe_parsed,
				'encodedBuilt'  => $encoded_built,
				'encodedParsed' => $encoded_parsed,
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
		$query_path    = '/needs encoding/' . $case['pathSegments'][2] . '?q=' . rawurlencode( $case['suspiciousQuery'] ) . '#frag';
		$home_query    = \home_url( $query_path, 'https' );
		$absolute_path = 'https://elsewhere.test/escape?x=1';
		$home_absolute = \home_url( $absolute_path, 'https' );
		$site_relative_protocol_path = \site_url( '//cdn.example.test/lib.js', 'relative' );
		$home_default_scheme = \home_url( 'default-scheme/' . $case['token'], null );
		$site_unknown_scheme = \site_url( 'unknown-scheme/' . $case['token'], 'rest' );

		$ok = array(
			'homeHttps'    => self::HOME_URL . '/' . $path === $home_https,
			'homeRelative' => '/site-base/' . $path === $home_relative,
			'siteHttp'     => self::SITE_URL . '/' . $path === $site_http,
			'siteHttps'    => 'https://example.test/wp' === $site_https,
			'homeHost'     => 'example.test' === \wp_parse_url( $home_https, PHP_URL_HOST ),
			'sitePath'     => '/wp/' . $path === \wp_parse_url( $site_http, PHP_URL_PATH ),
			'queryFragmentAppendedVerbatim' => self::HOME_URL . ltrim( $query_path, '/' ) !== $home_query
				&& self::HOME_URL . '/' . ltrim( $query_path, '/' ) === $home_query
				&& str_ends_with( $home_query, '#frag' ),
			'absolutePathDoesNotEscapeHome' => self::HOME_URL . '/' . $absolute_path === $home_absolute,
			'schemeRelativePathIsPath' => '/wp/cdn.example.test/lib.js' === $site_relative_protocol_path,
			'defaultHomeSchemeFromOption' => self::HOME_URL . '/default-scheme/' . $case['token'] === $home_default_scheme,
			'unknownSiteSchemeFallsBack' => self::SITE_URL . '/unknown-scheme/' . $case['token'] === $site_unknown_scheme,
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
				'homeQuery'    => $home_query,
				'homeAbsolute' => $home_absolute,
				'siteRelativeProtocolPath' => $site_relative_protocol_path,
				'homeDefaultScheme' => $home_default_scheme,
				'siteUnknownScheme' => $site_unknown_scheme,
			)
		);
	}

	private static function check_weird_path_fragments_no_throw( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$rewrite          = self::new_rewrite();
		$rewrite->matches = 'matches';
		self::add_surface_tags( $rewrite, $case );

		$rules     = $rewrite->generate_rewrite_rules( 'odd/' . $case['customTag'], EP_NONE, false, false, false, false, false );
		$fragments = array(
			'',
			'space value',
			'a+b&c=d',
			'semi;colon,comma',
			'brackets[]{}',
			'percent%zz',
			"line\nbreak",
			'slash/value',
			chr( 0 ) . 'nul',
			chr( 255 ) . chr( 254 ) . 'invalid',
		);

		$failures = array();
		foreach ( $fragments as $fragment ) {
			$encoded = rawurlencode( $fragment );

			try {
				$url        = \home_url( '/weird/' . $encoded . '?q=' . $encoded, 'https' );
				$parsed_url = \wp_parse_url( $url );
				$raw_parsed = \wp_parse_url( 'https://example.test/raw/' . $fragment );
				$match      = self::substituted_query_vars( $rules, array( $case['customQueryVar'] ), 'odd/' . $encoded . '/' );

				$matched_expected_value = '' === $encoded
					? null === $match['template']
					: isset( $match['vars'][ $case['customQueryVar'] ] )
						&& $encoded === $match['vars'][ $case['customQueryVar'] ];
				$ok = is_array( $parsed_url )
					&& ( is_array( $raw_parsed ) || false === $raw_parsed )
					&& $matched_expected_value;

				if ( ! $ok ) {
					$failures[] = array(
						'hex'       => bin2hex( $fragment ),
						'encoded'   => $encoded,
						'parsedUrl' => $parsed_url,
						'rawParsed' => $raw_parsed,
						'match'     => $match,
					);
				}
			} catch ( \Throwable $e ) {
				$failures[] = array(
					'hex'       => bin2hex( $fragment ),
					'encoded'   => $encoded,
					'throwable' => self::describe_throwable( $e ),
				);
			}
		}

		return $ctx->result(
			'rewrite.paths.weird-utf8-reserved-fragments-no-throw',
			array() === $failures,
			self::case_data( $case ) + array(
				'checked'  => count( $fragments ),
				'failures' => array_slice( $failures, 0, 5 ),
				'ruleSample' => self::sample_assoc( $rules ),
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
			'regexTag'           => '%cfuzz_rx_' . $token . '%',
			'regexQueryVar'      => 'cfuzz_rx_' . $token,
			'numericTag'         => '%cfuzz_num_' . $token . '%',
			'numericQueryVar'    => 'cfuzz_num_' . $token,
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
			'index.php/root/%year%/%postname%/',
			'bounded/' . $case['regexTag'] . '/' . $case['numericTag'] . '/%postname%',
			'preview/%post_id%/%postname%',
			'date/%year%/%monthnum%/%day%',
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

	private static function new_rewrite_for_structure( string $structure, array $case ): \WP_Rewrite {
		$rewrite = self::new_rewrite();
		self::add_surface_tags( $rewrite, $case );
		self::configure_rewrite_structure( $rewrite, $structure );

		return $rewrite;
	}

	private static function configure_rewrite_structure( \WP_Rewrite $rewrite, string $structure ): void {
		$rewrite->permalink_structure   = $structure;
		$percent_position               = strpos( $structure, '%' );
		$rewrite->front                 = false === $percent_position ? $structure : substr( $structure, 0, $percent_position );
		$rewrite->root                  = $rewrite->using_index_permalinks() ? $rewrite->index . '/' : '';
		$rewrite->use_trailing_slashes  = str_ends_with( $structure, '/' );
		$rewrite->use_verbose_page_rules = 1 === preg_match( '/^[^%]*%(?:postname|category|tag|author)%/', $structure );

		unset(
			$rewrite->author_structure,
			$rewrite->date_structure,
			$rewrite->page_structure,
			$rewrite->search_structure,
			$rewrite->feed_structure,
			$rewrite->comment_feed_structure
		);
	}

	private static function add_core_like_tags( \WP_Rewrite $rewrite ): void {
		$rewrite->add_rewrite_tag( '%category%', '(.+?)', 'category_name=' );
		$rewrite->add_rewrite_tag( '%tag%', '([^/]+)', 'tag=' );
	}

	private static function add_surface_tags( \WP_Rewrite $rewrite, array $case ): void {
		$rewrite->add_rewrite_tag( $case['customTag'], '([^/]+)', $case['customQueryVar'] . '=' );
		$rewrite->add_rewrite_tag( $case['regexTag'], '([a-z][a-z0-9-]{0,18})', $case['regexQueryVar'] . '=' );
		$rewrite->add_rewrite_tag( $case['numericTag'], '([0-9]{2,4})', $case['numericQueryVar'] . '=' );
	}

	private static function rules_from_permastruct( \WP_Rewrite $rewrite, array $permastruct ): array {
		return $rewrite->generate_rewrite_rules(
			$permastruct['struct'],
			$permastruct['ep_mask'],
			$permastruct['paged'],
			$permastruct['feed'],
			$permastruct['forcomments'],
			$permastruct['walk_dirs'],
			$permastruct['endpoints']
		);
	}

	private static function struct_prefix_before_tag( string $struct, string $tag, string $fallback ): string {
		$position = strpos( $struct, $tag );
		if ( false === $position ) {
			return $fallback;
		}

		return substr( $struct, 0, $position );
	}

	private static function vars_match_subset( array $actual, array $expected ): bool {
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) || $actual[ $key ] !== $value ) {
				return false;
			}
		}

		return true;
	}

	private static function expected_parse_for_build_query( array $data ): array {
		$expected = array();

		foreach ( $data as $key => $value ) {
			if ( null === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$expected[ $key ] = self::expected_parse_for_build_query( $value );
				continue;
			}

			if ( false === $value ) {
				$expected[ $key ] = '0';
				continue;
			}

			$expected[ $key ] = rawurldecode( (string) $value );
		}

		return $expected;
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

	private static function substituted_query_vars( array $rules, array $vars, string $path ): array {
		$matches  = array();
		$template = self::first_matching_rule_with_query_vars( $rules, $vars, $path, $matches );
		$parsed   = array();

		if ( null !== $template ) {
			$query = preg_replace( '!^.+\?!', '', $template['query'] );
			$query = \WP_MatchesMapRegex::apply( $query, $matches );
			\wp_parse_str( $query, $parsed );
		}

		return array(
			'template' => $template,
			'matches'  => $matches,
			'vars'     => $parsed,
		);
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

	private static function rules_have_key_prefix( array $rules, string $prefix ): bool {
		foreach ( $rules as $regex => $query ) {
			if ( str_starts_with( (string) $regex, $prefix ) ) {
				return true;
			}
		}

		return false;
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
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'globals' => $globals,
			'_SERVER' => self::clone_value( $_SERVER ),
			'_GET'    => self::clone_value( $_GET ),
		);
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		$_SERVER = self::clone_value( $snapshot['_SERVER'] );
		$_GET    = self::clone_value( $snapshot['_GET'] );
	}

	private static function case_data( array $case ): array {
		return array(
			'token'              => $case['token'],
			'permalinkStructure' => $case['permalinkStructure'],
			'customTag'          => $case['customTag'],
			'customQueryVar'     => $case['customQueryVar'],
			'regexTag'           => $case['regexTag'],
			'numericTag'         => $case['numericTag'],
			'unicodeSlug'        => $case['unicodeSlug'],
			'suspiciousQuery'    => $case['suspiciousQuery'],
		);
	}

	private static function safe_token( string $value ): string {
		$value = strtolower( preg_replace( '/[^A-Za-z0-9_]+/', '_', $value ) ?? '' );
		$value = trim( $value, '_' );

		return '' === $value ? 'x' : $value;
	}

	private static function snapshots_match( array $expected, array $actual ): bool {
		return $expected == $actual;
	}

	private static function clone_value( $value ) {
		if ( is_object( $value ) ) {
			return clone $value;
		}

		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		return $value;
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
