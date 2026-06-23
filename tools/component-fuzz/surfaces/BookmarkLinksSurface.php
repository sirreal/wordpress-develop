<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes legacy bookmark and link-manager APIs against the in-memory wpdb stub.
 */
final class BookmarkLinksSurface {
	public const NAME = 'bookmark-links';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_optional_bookmark_files();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'bookmark-links.bootstrap.required-apis',
					'Required WordPress bookmark/link APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();
			$fixture = self::seed_fixture( $ctx );

			$rows[] = self::check_get_bookmark_cache_and_outputs( $ctx, $fixture );
			$rows[] = self::check_get_bookmarks_argument_matrix( $ctx, $fixture );
			$rows[] = self::check_wp_list_bookmarks_rendering( $ctx, $fixture );
			$rows[] = self::check_bookmark_edit_links_and_crud( $ctx, $fixture );
			$rows[] = self::check_sanitize_bookmark_fields( $ctx, $fixture );
			$rows[] = self::check_legacy_wrappers( $ctx, $fixture );
			$rows[] = self::check_bookmark_query_cache( $ctx, $fixture );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'bookmark-links.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$state_restored = self::state_matches( $snapshot );
		$rows[]         = self::row(
			$ctx,
			'bookmark-links.state-restored',
			$state_restored,
			array( 'restored' => $state_restored )
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'clean_bookmark_cache',
				'create_initial_taxonomies',
				'current_user_can',
				'edit_bookmark_link',
				'esc_attr',
				'esc_url',
				'get_bookmark',
				'get_bookmark_field',
				'get_bookmarks',
				'get_default_link_to_edit',
				'get_edit_bookmark_link',
				'get_link',
				'get_link_to_edit',
				'get_linkobjects',
				'get_linkobjectsbyname',
				'get_linkrating',
				'get_links',
				'remove_filter',
				'sanitize_bookmark',
				'sanitize_bookmark_field',
				'wp_cache_delete',
				'wp_cache_flush',
				'wp_cache_get',
				'wp_cache_set',
				'wp_delete_link',
				'wp_get_links',
				'wp_get_linksbyname',
				'wp_insert_link',
				'wp_list_bookmarks',
				'wp_parse_args',
				'wp_set_current_user',
				'wp_update_link',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'Component_Fuzz_WPDB_Stub $wpdb';
		}

		return $missing;
	}

	private static function check_get_bookmark_cache_and_outputs( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		global $wpdb;

		$link       = $fixture['links'][0];
		$link_id    = (int) $link['link_id'];
		$new_name   = 'Cache Mutated ' . $ctx->identifier( 4, 9 ) . ' <b>name</b>';
		$categories = $fixture['link_categories'][ $link_id ];

		\clean_bookmark_cache( $link_id );

		$object  = \get_bookmark( $link_id, OBJECT, 'raw' );
		$array_a = \get_bookmark( $link_id, ARRAY_A, 'raw' );
		$array_n = \get_bookmark( $link_id, ARRAY_N, 'raw' );
		$missing = \get_bookmark( 999999, OBJECT, 'raw' );
		$cached  = \wp_cache_get( $link_id, 'bookmark' );
		$field   = \get_bookmark_field( 'link_name', $link_id, 'attribute' );

		$wpdb->update( $wpdb->links, array( 'link_name' => $new_name ), array( 'link_id' => $link_id ) );
		$still_cached = \get_bookmark( $link_id, OBJECT, 'raw' );
		\clean_bookmark_cache( $link_id );
		$fresh = \get_bookmark( $link_id, OBJECT, 'raw' );

		$ok = $object instanceof \stdClass
			&& is_array( $array_a )
			&& is_array( $array_n )
			&& null === $missing
			&& $cached instanceof \stdClass
			&& (int) $object->link_id === $link_id
			&& (int) $array_a['link_id'] === $link_id
			&& in_array( $link_id, array_map( 'intval', $array_n ), true )
			&& self::same_int_sets( $categories, $object->link_category ?? array() )
			&& ! self::contains_raw_dangerous_html( (string) $field )
			&& $still_cached instanceof \stdClass
			&& (string) $still_cached->link_name === (string) $link['link_name']
			&& $fresh instanceof \stdClass
			&& (string) $fresh->link_name === $new_name;

		return self::row(
			$ctx,
			'bookmark-links.get-bookmark.output-cache',
			$ok,
			array(
				'linkId'       => $link_id,
				'categories'   => $categories,
				'field'        => $field,
				'cachedName'   => $still_cached->link_name ?? null,
				'freshName'    => $fresh->link_name ?? null,
				'lastSqlShape' => self::sql_shape( $wpdb->last_query ),
			)
		);
	}

	private static function check_get_bookmarks_argument_matrix( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		$cases    = self::bookmark_query_cases( $ctx, $fixture );
		$failures = array();
		$details  = array();

		foreach ( $cases as $case ) {
			\wp_cache_delete( 'get_bookmarks', 'bookmark' );

			$actual      = \get_bookmarks( $case['args'] );
			$actual_ids  = self::bookmark_ids( $actual );
			$expected    = self::expected_bookmarks( $fixture, $case['args'] );
			$expected_ids = array_map(
				static function ( array $row ): int {
					return (int) $row['link_id'];
				},
				$expected
			);

			if ( ! empty( $case['loose'] ) ) {
				$allowed_args = array_merge(
					$case['args'],
					array(
						'limit'   => -1,
						'orderby' => 'link_id',
					)
				);
				$allowed_expected = self::expected_bookmarks( $fixture, $allowed_args );
				$expected_ids     = array_map(
					static function ( array $row ): int {
						return (int) $row['link_id'];
					},
					$allowed_expected
				);
				$limit            = absint( $case['args']['limit'] ?? count( $expected_ids ) );
				$case_ok          = count( $actual ) === min( $limit, count( $expected_ids ) )
					&& array() === array_diff( $actual_ids, $expected_ids )
					&& self::all_objects_have_link_fields( $actual );
				$case['args']     = array_merge( $case['args'], array( 'loose' => true ) );
			} else {
				$case_ok = $actual_ids === $expected_ids
					&& count( $actual ) === count( $expected )
					&& self::all_objects_have_link_fields( $actual );
			}

			$details[] = array(
				'name'        => $case['name'],
				'args'        => $case['args'],
				'actualIds'   => $actual_ids,
				'expectedIds' => $expected_ids,
			);

			if ( ! $case_ok ) {
				$failures[] = end( $details );
			}
		}

		return self::row(
			$ctx,
			'bookmark-links.get-bookmarks.arguments',
			array() === $failures,
			array(
				'cases'    => $details,
				'failures' => $failures,
			)
		);
	}

	private static function check_wp_list_bookmarks_rendering( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		$cat_id = (int) $fixture['categories'][0]['term_id'];

		$list_args = array(
			'echo'             => 0,
			'categorize'       => 0,
			'title_li'         => '',
			'hide_invisible'   => 0,
			'show_description' => 1,
			'show_rating'      => 1,
			'show_updated'     => 1,
			'show_images'      => 0,
			'orderby'          => 'link_id',
			'order'            => 'ASC',
			'limit'            => 4,
			'before'           => '<li class="bookmark-item">',
			'after'            => '</li>',
			'between'          => ' | ',
			'link_before'      => '<span>',
			'link_after'       => '</span>',
		);

		$returned = (string) \wp_list_bookmarks( $list_args );
		ob_start();
		$echo_return = \wp_list_bookmarks( array_merge( $list_args, array( 'echo' => 1 ) ) );
		$echoed      = (string) ob_get_clean();

		$category_html = (string) \wp_list_bookmarks(
			array(
				'echo'             => 0,
				'categorize'       => 1,
				'category'         => (string) $cat_id,
				'hide_invisible'   => 0,
				'show_description' => 1,
				'show_images'      => 0,
				'orderby'          => 'name',
				'title_before'     => '<h3>',
				'title_after'      => '</h3>',
				'class'            => 'linkcat raw<script> custom-class',
			)
		);

		$ok = null === $echo_return
			&& $returned === $echoed
			&& str_contains( $returned, '<a href="' )
			&& str_contains( $returned, '&lt;' )
			&& str_contains( $returned, ' | ' )
			&& str_contains( $category_html, 'linkcat-' . $cat_id )
			&& str_contains( $category_html, "class='xoxo blogroll'" )
			&& ! str_contains( $category_html, 'raw<script>' )
			&& ! self::contains_raw_dangerous_html( $returned )
			&& ! self::contains_raw_dangerous_html( $category_html );

		return self::row(
			$ctx,
			'bookmark-links.wp-list-bookmarks.rendering-escaping',
			$ok,
			array(
				'listPreview'     => self::preview( $returned ),
				'categoryPreview' => self::preview( $category_html ),
			)
		);
	}

	private static function check_bookmark_edit_links_and_crud( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		$link_id = (int) $fixture['links'][1]['link_id'];
		$cat_id  = (int) $fixture['categories'][1]['term_id'];
		$grant   = static function ( array $allcaps, array $caps ): array {
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		};
		$map_cap = static function ( array $caps, string $cap ): array {
			if ( 'manage_links' === $cap ) {
				return array( 'manage_links' );
			}

			return $caps;
		};

		\wp_set_current_user( 1 );
		\add_filter( 'map_meta_cap', $map_cap, 10, 2 );
		\add_filter( 'user_has_cap', $grant, 10, 2 );

		$previous_get = $_GET;

		try {
			$edit_url = \get_edit_bookmark_link( $link_id );

			ob_start();
			\edit_bookmark_link( 'Edit Link', '<span class="edit-link">', '</span>', $link_id );
			$edit_anchor = (string) ob_get_clean();

			$edit_bookmark = \get_link_to_edit( $link_id );

			$_GET['linkurl'] = 'javascript:alert(1)';
			$_GET['name']    = 'Default <script>Link</script>';
			$default_link    = \get_default_link_to_edit();

			$insert_id = \wp_insert_link(
				array(
					'link_name'        => 'Inserted <b>Bookmark</b> ' . $ctx->identifier( 4, 8 ),
					'link_url'         => 'https://inserted.example.test/path?x=<tag>',
					'link_description' => 'Inserted description <script>bad</script>',
					'link_visible'     => 'Y',
					'link_rating'      => 4,
					'link_category'    => array( $cat_id ),
					'link_rel'         => 'friend',
					'link_target'      => '_blank',
				),
				true
			);

			$update_id = is_int( $insert_id )
				? \wp_update_link(
					array(
						'link_id'          => $insert_id,
						'link_name'        => 'Updated Bookmark',
						'link_url'         => 'https://updated.example.test/',
						'link_description' => 'Updated description',
						'link_visible'     => 'N',
						'link_rating'      => 9,
						'link_category'    => array( $cat_id ),
					)
				)
				: 0;
			$updated   = is_int( $insert_id ) ? \get_bookmark( $insert_id, OBJECT, 'raw' ) : null;
			$delete_ok = is_int( $insert_id ) ? \wp_delete_link( $insert_id ) : false;
			$deleted   = is_int( $insert_id ) ? \get_bookmark( $insert_id, OBJECT, 'raw' ) : 'not-run';
		} finally {
			$_GET = $previous_get;
			\remove_filter( 'user_has_cap', $grant, 10 );
			\remove_filter( 'map_meta_cap', $map_cap, 10 );
			\wp_set_current_user( 0 );
		}

		$ok = is_string( $edit_url )
			&& str_contains( $edit_url, 'link.php?action=edit' )
			&& str_contains( $edit_url, 'link_id=' . $link_id )
			&& str_contains( $edit_anchor, '<a href=' )
			&& str_contains( $edit_anchor, 'Edit Link' )
			&& ! self::contains_raw_dangerous_html( $edit_anchor )
			&& $edit_bookmark instanceof \stdClass
			&& ! self::contains_raw_dangerous_html( (string) $edit_bookmark->link_name )
			&& $default_link instanceof \stdClass
			&& '' === $default_link->link_url
			&& ! self::contains_raw_dangerous_html( (string) $default_link->link_name )
			&& is_int( $insert_id )
			&& $insert_id > 0
			&& $update_id === $insert_id
			&& $updated instanceof \stdClass
			&& 'N' === $updated->link_visible
			&& 9 === (int) $updated->link_rating
			&& true === $delete_ok
			&& null === $deleted;

		return self::row(
			$ctx,
			'bookmark-links.edit-links-and-crud',
			$ok,
			array(
				'editUrl'     => $edit_url ?? null,
				'editAnchor'  => self::preview( $edit_anchor ?? '' ),
				'insertId'    => $insert_id ?? null,
				'updateId'    => $update_id ?? null,
				'updated'     => self::bookmark_summary( $updated ?? null ),
				'deleteOk'    => $delete_ok ?? null,
				'defaultLink' => self::bookmark_summary( $default_link ?? null ),
			)
		);
	}

	private static function check_sanitize_bookmark_fields( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		$link_id = (int) $fixture['links'][0]['link_id'];
		$raw     = (object) array(
			'link_id'          => '57xyz',
			'link_url'         => 'javascript:alert(1)',
			'link_name'        => 'Name " <script>bad</script>',
			'link_image'       => '/image.png',
			'link_target'      => '_self"bad',
			'link_category'    => array( '1', '-2', 'bad' ),
			'link_description' => 'Description <script>bad</script>',
			'link_visible'     => 'Y<script>n',
			'link_owner'       => '9',
			'link_rating'      => '8abc',
			'link_updated'     => '2026-06-23 12:00:00',
			'link_rel'         => 'friend" onclick="bad',
			'link_notes'       => "note\n<script>",
			'link_rss'         => 'https://example.test/feed',
		);

		$sanitized = \sanitize_bookmark( clone $raw, 'raw' );
		$attribute = \sanitize_bookmark_field( 'link_name', 'Bad " <script>', $link_id, 'attribute' );
		$js        = \sanitize_bookmark_field( 'link_description', "line\n'</script>", $link_id, 'js' );

		$events  = array();
		$pre     = static function ( $value ) use ( &$events ) {
			$events[] = 'pre_link_name';
			return $value . '-db';
		};
		$edit    = static function ( $value, $bookmark_id ) use ( &$events, $link_id ) {
			$events[] = 'edit_link_name:' . ( (int) $bookmark_id === $link_id ? 'id' : 'other' );
			return $value . '-edit';
		};
		$display = static function ( $value, $bookmark_id, $context ) use ( &$events, $link_id ) {
			$events[] = 'link_description:' . $context . ':' . ( (int) $bookmark_id === $link_id ? 'id' : 'other' );
			return $value . '-display';
		};

		\add_filter( 'pre_link_name', $pre, 10, 1 );
		\add_filter( 'edit_link_name', $edit, 10, 2 );
		\add_filter( 'link_description', $display, 10, 3 );

		try {
			$db_value      = \sanitize_bookmark_field( 'link_name', 'Filtered', $link_id, 'db' );
			$edit_value    = \sanitize_bookmark_field( 'link_name', 'Filtered <b>', $link_id, 'edit' );
			$display_value = \sanitize_bookmark_field( 'link_description', 'Filtered', $link_id, 'display' );
		} finally {
			\remove_filter( 'pre_link_name', $pre, 10 );
			\remove_filter( 'edit_link_name', $edit, 10 );
			\remove_filter( 'link_description', $display, 10 );
		}

		$ok = $sanitized instanceof \stdClass
			&& is_int( $sanitized->link_id )
			&& is_int( $sanitized->link_rating )
			&& preg_match( '/^[YNyn]*$/', (string) $sanitized->link_visible )
			&& '' === $sanitized->link_target
			&& self::all_non_negative_ints( $sanitized->link_category )
			&& ! self::contains_raw_dangerous_html( (string) $attribute )
			&& ! self::contains_raw_dangerous_html( (string) $js )
			&& 'Filtered-db' === $db_value
			&& str_contains( (string) $edit_value, 'Filtered' )
			&& ! str_contains( (string) $edit_value, '<b>' )
			&& 'Filtered-display' === $display_value
			&& array(
				'pre_link_name',
				'edit_link_name:id',
				'link_description:display:id',
			) === $events;

		return self::row(
			$ctx,
			'bookmark-links.sanitize-bookmark-fields',
			$ok,
			array(
				'sanitized'    => self::bookmark_summary( $sanitized ),
				'attribute'    => $attribute,
				'js'           => $js,
				'dbValue'      => $db_value ?? null,
				'editValue'    => $edit_value ?? null,
				'displayValue' => $display_value ?? null,
				'events'       => $events,
			)
		);
	}

	private static function check_legacy_wrappers( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		$link    = $fixture['links'][2];
		$link_id = (int) $link['link_id'];
		$cat     = $fixture['categories'][0];
		$cat_id  = (int) $cat['term_id'];

		$get_link          = \get_link( $link_id, ARRAY_A, 'raw' );
		$objects_by_id     = \get_linkobjects( $cat_id, 'id', 5 );
		$objects_by_name   = \get_linkobjectsbyname( $cat['name'], 'name', 5 );
		$links_by_name     = \wp_get_linksbyname(
			$cat['name'],
			array(
				'echo'        => 0,
				'show_images' => 0,
			)
		);
		$wp_get_links_html = \wp_get_links( 'category=' . $cat_id . '&echo=0&show_images=0' );
		$get_links_html    = \get_links( $cat_id, '', '<br />', ' ', false, 'id', true, true, 5, 1, false );
		$rating            = \get_linkrating( (object) $link );

		$ok = is_array( $get_link )
			&& (int) $get_link['link_id'] === $link_id
			&& self::all_bookmark_objects( $objects_by_id )
			&& self::all_bookmark_objects( $objects_by_name )
			&& is_string( $links_by_name )
			&& is_string( $wp_get_links_html )
			&& is_string( $get_links_html )
			&& (int) $rating === (int) $link['link_rating']
			&& ! self::contains_raw_dangerous_html( $links_by_name )
			&& ! self::contains_raw_dangerous_html( $wp_get_links_html )
			&& ! self::contains_raw_dangerous_html( $get_links_html );

		return self::row(
			$ctx,
			'bookmark-links.legacy-wrappers.safe-paths',
			$ok,
			array(
				'getLinkId'          => $get_link['link_id'] ?? null,
				'objectsById'        => self::bookmark_ids( $objects_by_id ),
				'objectsByName'      => self::bookmark_ids( $objects_by_name ),
				'linksByNamePreview' => self::preview( (string) $links_by_name ),
				'wpGetLinksPreview'  => self::preview( (string) $wp_get_links_html ),
				'getLinksPreview'    => self::preview( (string) $get_links_html ),
				'rating'             => $rating,
			)
		);
	}

	private static function check_bookmark_query_cache( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		global $wpdb;

		$args = array(
			'hide_invisible' => 0,
			'orderby'        => 'link_id',
			'order'          => 'ASC',
		);

		\wp_cache_delete( 'get_bookmarks', 'bookmark' );
		$before      = \get_bookmarks( $args );
		$before_ids  = self::bookmark_ids( $before );
		$cache       = \wp_cache_get( 'get_bookmarks', 'bookmark' );
		$cache_key   = self::get_bookmarks_cache_key( $args );
		$new_link_id = 9700 + $ctx->iteration();

		$wpdb->insert(
			$wpdb->links,
			array(
				'link_id'          => $new_link_id,
				'link_url'         => 'https://cache-probe.example.test/',
				'link_name'        => 'Cache Probe',
				'link_description' => 'Cache probe link',
				'link_visible'     => 'Y',
				'link_owner'       => 1,
				'link_rating'      => 1,
				'link_updated'     => '2026-06-23 12:00:00',
			)
		);

		$cached_after_insert = \get_bookmarks( $args );
		\clean_bookmark_cache( $new_link_id );
		$fresh_after_clean = \get_bookmarks( $args );

		$ok = is_array( $cache )
			&& array_key_exists( $cache_key, $cache )
			&& $before_ids === self::bookmark_ids( $cached_after_insert )
			&& in_array( $new_link_id, self::bookmark_ids( $fresh_after_clean ), true );

		return self::row(
			$ctx,
			'bookmark-links.get-bookmarks.cache',
			$ok,
			array(
				'cacheKey'           => $cache_key,
				'beforeIds'          => $before_ids,
				'cachedAfterInsert'  => self::bookmark_ids( $cached_after_insert ),
				'freshAfterCleanIds' => self::bookmark_ids( $fresh_after_clean ),
				'newLinkId'          => $new_link_id,
				'fixtureLinks'       => count( $fixture['links'] ),
			)
		);
	}

	private static function bookmark_query_cases( \ComponentFuzz\FuzzContext $ctx, array $fixture ): array {
		$cat_a = (int) $fixture['categories'][0]['term_id'];
		$cat_b = (int) $fixture['categories'][1]['term_id'];
		$cat_c = (int) $fixture['categories'][2]['term_id'];
		$links = $fixture['links'];

		return array(
			array(
				'name' => 'default-visible-by-name',
				'args' => array(
					'orderby' => 'name',
					'order'   => 'ASC',
				),
			),
			array(
				'name' => 'include-overrides-category-exclude',
				'args' => array(
					'include'        => $links[1]['link_id'] . ',' . $links[0]['link_id'] . ',99999',
					'exclude'        => (string) $links[0]['link_id'],
					'category'       => (string) $cat_c,
					'hide_invisible' => 0,
					'orderby'        => 'link_id',
					'order'          => 'DESC',
				),
			),
			array(
				'name' => 'exclude-limit-rating-desc',
				'args' => array(
					'exclude'        => $links[3]['link_id'] . ',' . $links[4]['link_id'],
					'hide_invisible' => 0,
					'orderby'        => 'rating',
					'order'          => 'DESC',
					'limit'          => 3,
				),
			),
			array(
				'name' => 'category-name-search-url',
				'args' => array(
					'category_name'  => $fixture['categories'][1]['name'],
					'search'         => 'needle',
					'hide_invisible' => 0,
					'orderby'        => 'url',
					'order'          => 'ASC',
				),
			),
			array(
				'name' => 'multi-category-length-limit',
				'args' => array(
					'category'       => $cat_a . ',' . $cat_b,
					'hide_invisible' => 0,
					'orderby'        => 'length',
					'order'          => 'ASC',
					'limit'          => 5,
				),
			),
			array(
				'name' => 'notes-description-visible-order',
				'args' => array(
					'category'       => (string) $cat_c,
					'hide_invisible' => 0,
					'orderby'        => $ctx->choice( array( 'notes,description', 'visible,name', 'owner,updated' ) ),
					'order'          => $ctx->choice( array( 'ASC', 'DESC', 'sideways' ) ),
					'limit'          => 4,
				),
			),
			array(
				'name' => 'rand-no-cache-shape',
				'args' => array(
					'hide_invisible' => 0,
					'orderby'        => 'rand',
					'limit'          => 4,
				),
				'loose' => true,
			),
		);
	}

	private static function expected_bookmarks( array $fixture, array $args ): array {
		$defaults = array(
			'orderby'        => 'name',
			'order'          => 'ASC',
			'limit'          => -1,
			'category'       => '',
			'category_name'  => '',
			'hide_invisible' => 1,
			'show_updated'   => 0,
			'include'        => '',
			'exclude'        => '',
			'search'         => '',
		);

		$args = \wp_parse_args( $args, $defaults );

		if ( ! empty( $args['include'] ) ) {
			$args['exclude']       = '';
			$args['category']      = '';
			$args['category_name'] = '';
		}

		if ( ! empty( $args['category_name'] ) ) {
			$matched = null;
			foreach ( $fixture['categories'] as $category ) {
				if ( (string) $category['name'] === (string) $args['category_name'] ) {
					$matched = (int) $category['term_id'];
					break;
				}
			}

			if ( null === $matched ) {
				return array();
			}

			$args['category'] = (string) $matched;
		}

		$category_ids = self::id_list( $args['category'] );
		$include_ids  = self::id_list( $args['include'] );
		$exclude_ids  = self::id_list( $args['exclude'] );
		$rows         = array();

		foreach ( $fixture['links'] as $link ) {
			if ( $args['hide_invisible'] && 'Y' !== (string) $link['link_visible'] ) {
				continue;
			}

			if ( array() !== $include_ids && ! in_array( (int) $link['link_id'], $include_ids, true ) ) {
				continue;
			}

			if ( array() !== $exclude_ids && in_array( (int) $link['link_id'], $exclude_ids, true ) ) {
				continue;
			}

			if ( '' !== (string) $args['search'] && ! self::link_matches_search( $link, (string) $args['search'] ) ) {
				continue;
			}

			if ( array() === $category_ids ) {
				$rows[] = $link;
				continue;
			}

			foreach ( $fixture['link_categories'][ (int) $link['link_id'] ] as $category_id ) {
				if ( in_array( (int) $category_id, $category_ids, true ) ) {
					$rows[] = $link;
				}
			}
		}

		$rows = self::sort_expected_bookmarks( $rows, (string) $args['orderby'], (string) $args['order'] );

		if ( -1 !== (int) $args['limit'] ) {
			$rows = array_slice( $rows, 0, absint( $args['limit'] ) );
		}

		return array_values( $rows );
	}

	private static function sort_expected_bookmarks( array $rows, string $orderby, string $order ): array {
		$orderby = strtolower( $orderby );
		$order   = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		if ( 'rand' === $orderby ) {
			usort(
				$rows,
				static function ( array $a, array $b ) {
					return strcmp( md5( 'expected:' . $a['link_id'] ), md5( 'expected:' . $b['link_id'] ) );
				}
			);
			return array_values( $rows );
		}

		$columns = array();
		if ( 'length' === $orderby ) {
			$columns[] = 'length';
		} elseif ( 'link_id' === $orderby ) {
			$columns[] = 'link_id';
		} else {
			$keys = array( 'link_id', 'link_name', 'link_url', 'link_visible', 'link_rating', 'link_owner', 'link_updated', 'link_notes', 'link_description' );
			foreach ( explode( ',', $orderby ) as $ordparam ) {
				$ordparam = trim( $ordparam );
				if ( in_array( 'link_' . $ordparam, $keys, true ) ) {
					$columns[] = 'link_' . $ordparam;
				} elseif ( in_array( $ordparam, $keys, true ) ) {
					$columns[] = $ordparam;
				}
			}
		}

		if ( array() === $columns ) {
			$columns[] = 'link_name';
		}

		usort(
			$rows,
			static function ( array $a, array $b ) use ( $columns, $order ) {
				foreach ( $columns as $column ) {
					if ( 'length' === $column ) {
						$comparison = strlen( (string) $a['link_name'] ) <=> strlen( (string) $b['link_name'] );
					} elseif ( in_array( $column, array( 'link_id', 'link_rating', 'link_owner' ), true ) ) {
						$comparison = (int) $a[ $column ] <=> (int) $b[ $column ];
					} else {
						$comparison = strcasecmp( (string) $a[ $column ], (string) $b[ $column ] );
					}

					if ( 0 !== $comparison ) {
						return 'DESC' === $order ? -$comparison : $comparison;
					}
				}

				$comparison = (int) $a['link_id'] <=> (int) $b['link_id'];
				return 'DESC' === $order ? -$comparison : $comparison;
			}
		);

		return array_values( $rows );
	}

	private static function seed_fixture( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb;

		$categories = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$term_id = 300 + $i + 1;
			$tt_id   = 400 + $i + 1;
			$name    = array( 'Fuzz Links', 'Needle Resources', 'Archive Links' )[ $i ];

			$wpdb->insert(
				$wpdb->terms,
				array(
					'term_id'    => $term_id,
					'name'       => $name,
					'slug'       => 'component-fuzz-link-cat-' . ( $i + 1 ),
					'term_group' => 0,
				)
			);
			$wpdb->insert(
				$wpdb->term_taxonomy,
				array(
					'term_taxonomy_id' => $tt_id,
					'term_id'          => $term_id,
					'taxonomy'         => 'link_category',
					'description'      => 'Component fuzz link category ' . ( $i + 1 ),
					'parent'           => 0,
					'count'            => 0,
				)
			);

			$categories[] = array(
				'term_id'          => $term_id,
				'term_taxonomy_id' => $tt_id,
				'name'             => $name,
			);
		}

		$links = array(
			array(
				'link_id'          => 501,
				'link_url'         => 'https://alpha.example.test/path?a=1&b=<tag>',
				'link_name'        => 'Alpha & <b>One</b>',
				'link_image'       => '',
				'link_target'      => '_blank',
				'link_description' => 'Alpha description <script>bad</script>',
				'link_visible'     => 'Y',
				'link_owner'       => 7,
				'link_rating'      => 3,
				'link_updated'     => '2026-06-23 10:00:00',
				'link_rel'         => 'friend met',
				'link_notes'       => 'notes-alpha',
				'link_rss'         => 'https://alpha.example.test/feed',
			),
			array(
				'link_id'          => 502,
				'link_url'         => 'javascript:alert(1)',
				'link_name'        => 'Beta Hidden',
				'link_image'       => '',
				'link_target'      => '_top',
				'link_description' => 'Hidden needle description',
				'link_visible'     => 'N',
				'link_owner'       => 3,
				'link_rating'      => 8,
				'link_updated'     => '2026-06-21 07:00:00',
				'link_rel'         => 'nofollow',
				'link_notes'       => 'notes-beta',
				'link_rss'         => '',
			),
			array(
				'link_id'          => 503,
				'link_url'         => 'https://needle.example.test/docs',
				'link_name'        => 'Needle Docs',
				'link_image'       => '',
				'link_target'      => '',
				'link_description' => 'Documentation and reference',
				'link_visible'     => 'Y',
				'link_owner'       => 2,
				'link_rating'      => 5,
				'link_updated'     => '2026-06-22 12:15:00',
				'link_rel'         => 'external',
				'link_notes'       => 'notes-needle',
				'link_rss'         => 'https://needle.example.test/feed.xml',
			),
			array(
				'link_id'          => 504,
				'link_url'         => 'mailto:fuzz@example.test',
				'link_name'        => 'Mail Link',
				'link_image'       => '',
				'link_target'      => '',
				'link_description' => 'Mail contact',
				'link_visible'     => 'Y',
				'link_owner'       => 5,
				'link_rating'      => 1,
				'link_updated'     => '2026-06-20 09:30:00',
				'link_rel'         => '',
				'link_notes'       => 'notes-mail',
				'link_rss'         => '',
			),
			array(
				'link_id'          => 505,
				'link_url'         => 'https://zeta.example.test/?q=needle',
				'link_name'        => 'Zeta Reference',
				'link_image'       => '',
				'link_target'      => '_blank',
				'link_description' => 'Reference link',
				'link_visible'     => 'Y',
				'link_owner'       => 9,
				'link_rating'      => 9,
				'link_updated'     => '2026-06-19 16:45:00',
				'link_rel'         => 'tag',
				'link_notes'       => 'notes-zeta',
				'link_rss'         => '',
			),
			array(
				'link_id'          => 506,
				'link_url'         => 'https://omega.example.test/' . rawurlencode( $ctx->identifier( 4, 8 ) ),
				'link_name'        => 'Omega ' . $ctx->identifier( 3, 7 ),
				'link_image'       => '',
				'link_target'      => '',
				'link_description' => 'Generated description ' . $ctx->identifier( 3, 7 ),
				'link_visible'     => $ctx->bool() ? 'Y' : 'N',
				'link_owner'       => $ctx->int( 1, 10 ),
				'link_rating'      => $ctx->int( 0, 10 ),
				'link_updated'     => '2026-06-18 08:00:00',
				'link_rel'         => 'generated',
				'link_notes'       => 'notes-' . $ctx->identifier( 3, 7 ),
				'link_rss'         => '',
			),
		);

		$link_categories = array(
			501 => array( $categories[0]['term_id'], $categories[1]['term_id'] ),
			502 => array( $categories[1]['term_id'] ),
			503 => array( $categories[0]['term_id'], $categories[1]['term_id'] ),
			504 => array( $categories[2]['term_id'] ),
			505 => array( $categories[1]['term_id'], $categories[2]['term_id'] ),
			506 => array( $categories[2]['term_id'] ),
		);

		foreach ( $links as $link ) {
			$wpdb->insert( $wpdb->links, $link );
		}

		$counts_by_tt = array_fill_keys( array_column( $categories, 'term_taxonomy_id' ), 0 );
		$tt_by_term   = array();
		foreach ( $categories as $category ) {
			$tt_by_term[ (int) $category['term_id'] ] = (int) $category['term_taxonomy_id'];
		}

		foreach ( $link_categories as $link_id => $category_ids ) {
			foreach ( $category_ids as $position => $category_id ) {
				$tt_id = $tt_by_term[ (int) $category_id ];
				$wpdb->insert(
					$wpdb->term_relationships,
					array(
						'object_id'        => (int) $link_id,
						'term_taxonomy_id' => $tt_id,
						'term_order'       => $position,
					)
				);
				++$counts_by_tt[ $tt_id ];
			}
		}

		foreach ( $counts_by_tt as $tt_id => $count ) {
			$wpdb->update(
				$wpdb->term_taxonomy,
				array( 'count' => $count ),
				array( 'term_taxonomy_id' => $tt_id )
			);
		}

		return array(
			'categories'      => $categories,
			'links'           => $links,
			'link_categories' => $link_categories,
		);
	}

	private static function load_optional_bookmark_files(): void {
		foreach ( array( 'wp-admin/includes/bookmark.php', 'wp-includes/deprecated.php' ) as $file ) {
			$path = ABSPATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function reset_runtime(): void {
		global $wpdb;

		$wpdb->component_fuzz_reset_content();
		$wpdb->component_fuzz_reset_options(
			array(
				'blog_charset'                  => 'UTF-8',
				'default_link_category'         => 301,
				'gmt_offset'                    => 0,
				'home'                          => 'http://example.test',
				'links_recently_updated_append' => '</em>',
				'links_recently_updated_prepend' => '<em>',
				'links_updated_date_format'     => 'F j, Y g:i a',
				'siteurl'                       => 'http://example.test',
			)
		);

		\wp_cache_flush();

		$GLOBALS['wp']               = new \WP();
		$GLOBALS['wp_rewrite']       = new \WP_Rewrite();
		$GLOBALS['wp_taxonomies']    = array();
		$GLOBALS['wp_post_types']    = array();
		$GLOBALS['wp_post_statuses'] = array();

		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz BookmarkLinks';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/bookmark-links/';
		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['SERVER_SOFTWARE'] = 'ComponentFuzz';
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'current_user',
				'link',
				'pagenow',
				'user_ID',
				'wp',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_post_statuses',
				'wp_post_types',
				'wp_rewrite',
				'wp_taxonomies',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_SOFTWARE' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'server'  => $server,
			'get'     => $_GET,
			'post'    => $_POST,
			'wpdb'    => self::snapshot_wpdb(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_wpdb( $snapshot['wpdb'] );

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}

		$_GET  = $snapshot['get'];
		$_POST = $snapshot['post'];

		self::restore_wpdb( $snapshot['wpdb'] );
	}

	private static function snapshot_wpdb(): ?array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return null;
		}

		$wpdb       = $GLOBALS['wpdb'];
		$reflection = new \ReflectionClass( $wpdb );
		$state      = array(
			'public'  => array(
				'insert_id'     => $wpdb->insert_id,
				'last_error'    => $wpdb->last_error,
				'last_query'    => $wpdb->last_query,
				'num_rows'      => $wpdb->num_rows,
				'rows_affected' => $wpdb->rows_affected,
			),
			'private' => array(),
		);

		foreach ( $reflection->getProperties() as $property ) {
			$name = $property->getName();
			if ( str_starts_with( $name, 'component_fuzz_' ) ) {
				$state['private'][ $name ] = $property->getValue( $wpdb );
			}
		}

		return $state;
	}

	private static function restore_wpdb( ?array $snapshot ): void {
		if ( null === $snapshot || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return;
		}

		$wpdb = $GLOBALS['wpdb'];
		foreach ( $snapshot['public'] as $name => $value ) {
			$wpdb->{$name} = $value;
		}

		$reflection = new \ReflectionClass( $wpdb );
		foreach ( $snapshot['private'] as $name => $value ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}
			$property = $reflection->getProperty( $name );
			$property->setValue( $wpdb, $value );
		}
	}

	private static function state_matches( array $snapshot ): bool {
		return $snapshot === self::snapshot_state();
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function id_list( $value ): array {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) $parts ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);
	}

	private static function link_matches_search( array $link, string $search ): bool {
		foreach ( array( 'link_url', 'link_name', 'link_description' ) as $field ) {
			if ( false !== stripos( (string) $link[ $field ], $search ) ) {
				return true;
			}
		}

		return false;
	}

	private static function bookmark_ids( array $bookmarks ): array {
		$ids = array();
		foreach ( $bookmarks as $bookmark ) {
			if ( is_object( $bookmark ) && isset( $bookmark->link_id ) ) {
				$ids[] = (int) $bookmark->link_id;
			} elseif ( is_array( $bookmark ) && isset( $bookmark['link_id'] ) ) {
				$ids[] = (int) $bookmark['link_id'];
			}
		}

		return $ids;
	}

	private static function all_objects_have_link_fields( array $bookmarks ): bool {
		foreach ( $bookmarks as $bookmark ) {
			if ( ! $bookmark instanceof \stdClass ) {
				return false;
			}

			foreach ( array( 'link_id', 'link_url', 'link_name', 'link_visible', 'link_rating', 'link_description' ) as $field ) {
				if ( ! property_exists( $bookmark, $field ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function all_bookmark_objects( $bookmarks ): bool {
		if ( ! is_array( $bookmarks ) || array() === $bookmarks ) {
			return false;
		}

		foreach ( $bookmarks as $bookmark ) {
			if ( ! $bookmark instanceof \stdClass || ! isset( $bookmark->link_id ) ) {
				return false;
			}
		}

		return true;
	}

	private static function all_non_negative_ints( $values ): bool {
		if ( ! is_array( $values ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! is_int( $value ) || $value < 0 ) {
				return false;
			}
		}

		return true;
	}

	private static function same_int_sets( array $expected, $actual ): bool {
		$expected = array_values( array_unique( array_map( 'intval', $expected ) ) );
		$actual   = array_values( array_unique( array_map( 'intval', (array) $actual ) ) );
		sort( $expected );
		sort( $actual );

		return $expected === $actual;
	}

	private static function get_bookmarks_cache_key( array $args ): string {
		$defaults = array(
			'orderby'        => 'name',
			'order'          => 'ASC',
			'limit'          => -1,
			'category'       => '',
			'category_name'  => '',
			'hide_invisible' => 1,
			'show_updated'   => 0,
			'include'        => '',
			'exclude'        => '',
			'search'         => '',
		);

		return md5( serialize( \wp_parse_args( $args, $defaults ) ) );
	}

	private static function contains_raw_dangerous_html( string $html ): bool {
		$lower = strtolower( $html );

		return str_contains( $lower, '<script' )
			|| str_contains( $lower, '</script' )
			|| str_contains( $lower, 'javascript:' )
			|| (bool) preg_match( '/\son[a-z]+\s*=/i', $html );
	}

	private static function bookmark_summary( $bookmark ): array {
		if ( ! is_object( $bookmark ) && ! is_array( $bookmark ) ) {
			return array( 'type' => gettype( $bookmark ) );
		}

		$row = is_object( $bookmark ) ? get_object_vars( $bookmark ) : $bookmark;

		return array_intersect_key(
			$row,
			array_flip(
				array(
					'link_id',
					'link_name',
					'link_url',
					'link_visible',
					'link_rating',
					'link_category',
				)
			)
		);
	}

	private static function sql_shape( string $sql ): string {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		$sql = preg_replace( '/\b\d+\b/', 'N', (string) $sql );
		$sql = preg_replace( "/'(?:\\\\.|[^'\\\\])*'/", "'S'", (string) $sql );

		return self::preview( (string) $sql );
	}

	private static function preview( $value ) {
		return \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES );
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'type'    => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
