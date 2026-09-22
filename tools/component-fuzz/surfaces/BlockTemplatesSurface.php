<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB block template and block theme template resolution APIs.
 */
final class BlockTemplatesSurface {
	public const NAME = 'block-templates';

	private const MAX_FAILURES = 12;
	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'block-templates.bootstrap-apis-available',
					'Required WordPress block template APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot  = self::snapshot_state();
		$rows      = array();
		$temp_root = null;
		$cleanup   = null;

		try {
			$temp_root = self::make_temp_root( $ctx );
			self::prepare_sandbox( $temp_root );

			$case = self::case_for_context( $ctx, $temp_root );
			self::write_case_files( $case );
			self::install_case_filters( $case );
			self::reset_runtime_caches();

			$rows[] = self::check_registry_lifecycle( $ctx, $case );
			$rows[] = self::check_file_template_objects( $ctx, $case );
			$rows[] = self::check_get_and_list_consistency( $ctx, $case );
			$rows[] = self::check_template_part_resolution( $ctx, $case );
			$rows[] = self::check_loader_resolution( $ctx, $case );
			foreach ( self::check_malformed_and_traversal_guards( $ctx, $case ) as $row ) {
				$rows[] = $row;
			}
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'block-templates.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $temp_root ) {
				self::reset_runtime_caches();
				$cleanup = self::remove_dir_recursive( $temp_root );
			}
			self::restore_state( $snapshot );
		}

		if ( null !== $temp_root ) {
			$rows[] = $ctx->result(
				'block-templates.temp-sandbox-cleaned',
				true === $cleanup && ! file_exists( $temp_root ),
				array(
					'root'    => self::preview( $temp_root ),
					'cleaned' => true === $cleanup,
					'exists'  => file_exists( $temp_root ),
				)
			);
		}

		$rows[] = $ctx->result(
			'block-templates.global-static-state-restored',
			self::state_restored( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedStatics' => array_keys( $snapshot['statics'] ),
			)
		);

		return $rows;
	}

	public static function filter_template_posts_pre_query( $posts, $query ) {
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return $posts;
		}

		$post_type  = $query->get( 'post_type' );
		$post_types = is_array( $post_type ) ? $post_type : array( $post_type );

		foreach ( $post_types as $type ) {
			if ( in_array( $type, array( 'wp_template', 'wp_template_part' ), true ) ) {
				return array();
			}
		}

		return $posts;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Block_Template', 'WP_Block_Templates_Registry', 'WP_Query', 'WP_Theme' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_build_block_template_result_from_file',
				'_get_block_templates_files',
				'add_filter',
				'add_theme_support',
				'current_theme_supports',
				'get_block_file_template',
				'get_block_template',
				'get_block_templates',
				'get_block_theme_folders',
				'get_stylesheet',
				'get_stylesheet_directory',
				'get_template',
				'get_template_directory',
				'is_wp_error',
				'locate_block_template',
				'locate_template',
				'register_block_template',
				'remove_filter',
				'resolve_block_template',
				'unregister_block_template',
				'wp_cache_delete',
				'wp_clean_theme_json_cache',
				'wp_json_encode',
				'wp_normalize_path',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_registry_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$registry = \WP_Block_Templates_Registry::get_instance();
		$name     = $case['registry']['name'];
		$slug     = $case['registry']['slug'];
		$allowed_post_type = $case['registry']['postTypes'][0];
		$other_post_type   = 'page' === $allowed_post_type ? 'post' : 'page';

		$registered = \register_block_template(
			$name,
			array(
				'title'       => $case['registry']['title'],
				'description' => $case['registry']['description'],
				'content'     => $case['registry']['content'],
				'post_types'  => $case['registry']['postTypes'],
			)
		);
		$by_name    = $registry->get_registered( $name );
		$by_slug    = $registry->get_by_slug( $slug );
		$by_query   = $registry->get_by_query(
			array(
				'slug__in'  => array( $slug ),
				'post_type' => $case['registry']['postTypes'][0],
			)
		);
		$duplicate  = self::call_silenced(
			static function () use ( $name ) {
				return \register_block_template( $name, array( 'title' => 'Duplicate' ) );
			}
		);
		$uppercase  = self::call_silenced(
			static function () use ( $slug ) {
				return \register_block_template( 'ComponentFuzz//' . $slug, array() );
			}
		);
		$collision_description = 'Registered collision template ' . $case['registry']['description'];
		$collision_name        = $case['registry']['namespace'] . '//' . $case['templates']['prioritySlug'];
		$collision             = \register_block_template(
			$collision_name,
			array(
				'title'       => 'Registered collision ' . $case['registry']['title'],
				'description' => $collision_description,
				'content'     => $case['registry']['content'],
				'post_types'  => array( 'page', 'post' ),
			)
		);

		self::assert_template_object(
			$failures,
			'registry.registered-template',
			$registered,
			array(
				'id'              => $case['theme']['childSlug'] . '//' . $slug,
				'theme'           => $case['theme']['childSlug'],
				'slug'            => $slug,
				'type'            => 'wp_template',
				'source'          => 'plugin',
				'origin'          => 'plugin',
				'status'          => 'publish',
				'plugin'          => $case['registry']['namespace'],
				'title'           => $case['registry']['title'],
				'description'     => $case['registry']['description'],
				'post_types'      => $case['registry']['postTypes'],
				'contentContains' => $case['registry']['marker'],
				'is_custom'       => true,
			)
		);

		self::record_if_false(
			$failures,
			$registered instanceof \WP_Block_Template
				&& $registered === $by_name
				&& $registered === $by_slug
				&& isset( $by_query[ $name ] )
				&& $by_query[ $name ] === $registered,
			'registry.lookup-by-name-slug-query-returns-same-object',
			array(
				'name'          => $name,
				'queryKeys'     => array_keys( $by_query ),
				'byNameClass'   => is_object( $by_name ) ? get_class( $by_name ) : gettype( $by_name ),
				'bySlugClass'   => is_object( $by_slug ) ? get_class( $by_slug ) : gettype( $by_slug ),
				'registeredOk'  => $registered instanceof \WP_Block_Template,
			)
		);

		self::record_if_false(
			$failures,
			! $duplicate['threw']
				&& \is_wp_error( $duplicate['value'] )
				&& in_array( 'template_already_registered', $duplicate['value']->get_error_codes(), true )
				&& ! $uppercase['threw']
				&& \is_wp_error( $uppercase['value'] )
				&& in_array( 'template_name_no_uppercase', $uppercase['value']->get_error_codes(), true ),
			'registry.duplicates-and-invalid-names-return-errors',
			array(
				'duplicate' => self::describe_call( $duplicate ),
				'uppercase' => self::describe_call( $uppercase ),
			)
		);

		$registered_list = \get_block_templates(
			array(
				'slug__in'  => array( $slug ),
				'post_type' => $allowed_post_type,
			),
			'wp_template'
		);
		$filtered_list   = \get_block_templates(
			array(
				'slug__in'  => array( $slug ),
				'post_type' => $other_post_type,
			),
			'wp_template'
		);
		$listed_plugin   = self::templates_by_slug( $registered_list )[ $slug ] ?? null;
		$filtered_plugin = self::templates_by_slug( $filtered_list )[ $slug ] ?? null;

		self::assert_template_object(
			$failures,
			'registry.public-list-includes-plugin-template',
			$listed_plugin,
			array(
				'id'              => $case['theme']['childSlug'] . '//' . $slug,
				'theme'           => $case['theme']['childSlug'],
				'slug'            => $slug,
				'type'            => 'wp_template',
				'source'          => 'plugin',
				'origin'          => 'plugin',
				'plugin'          => $case['registry']['namespace'],
				'post_types'      => $case['registry']['postTypes'],
				'contentContains' => $case['registry']['marker'],
			)
		);
		self::record_if_false(
			$failures,
			$listed_plugin instanceof \WP_Block_Template && null === $filtered_plugin,
			'registry.public-list-honors-post-type-filter-for-plugin-template',
			array(
				'allowedPostType' => $allowed_post_type,
				'otherPostType'   => $other_post_type,
				'allowedSlugs'    => array_keys( self::templates_by_slug( $registered_list ) ),
				'otherSlugs'      => array_keys( self::templates_by_slug( $filtered_list ) ),
			)
		);

		$collision_list    = \get_block_templates( array( 'slug__in' => array( $case['templates']['prioritySlug'] ) ), 'wp_template' );
		$collision_matches = array_values(
			array_filter(
				$collision_list,
				static function ( $template ) use ( $case ): bool {
					return $template instanceof \WP_Block_Template && $case['templates']['prioritySlug'] === $template->slug;
				}
			)
		);
		$collision_listed  = $collision_matches[0] ?? null;
		self::record_if_false(
			$failures,
			$collision instanceof \WP_Block_Template
				&& 1 === count( $collision_matches )
				&& $collision_listed instanceof \WP_Block_Template
				&& 'theme' === $collision_listed->source
				&& $case['registry']['namespace'] === $collision_listed->plugin
				&& $collision_description === $collision_listed->description
				&& str_contains( $collision_listed->content, $case['templates']['priorityChildMarker'] )
				&& ! str_contains( $collision_listed->content, $case['registry']['marker'] ),
			'registry.public-list-enriches-theme-file-collisions-without-duplicates',
			array(
				'registeredCollision' => self::template_summary( $collision ),
				'listedCollision'     => self::template_summary( $collision_listed ),
				'matchCount'          => count( $collision_matches ),
				'listedSlugs'         => array_keys( self::templates_by_slug( $collision_list ) ),
			)
		);

		$unregistered = \unregister_block_template( $name );
		$unregistered_collision = \unregister_block_template( $collision_name );
		$missing      = self::call_silenced(
			static function () use ( $name ) {
				return \unregister_block_template( $name );
			}
		);

		self::record_if_false(
			$failures,
			$registered instanceof \WP_Block_Template
				&& $unregistered === $registered
				&& $collision instanceof \WP_Block_Template
				&& $unregistered_collision === $collision
				&& null === $registry->get_registered( $name )
				&& null === $registry->get_registered( $collision_name )
				&& null === $registry->get_by_slug( $slug )
				&& null === $registry->get_by_slug( $case['templates']['prioritySlug'] )
				&& ! $missing['threw']
				&& \is_wp_error( $missing['value'] )
				&& in_array( 'template_not_registered', $missing['value']->get_error_codes(), true ),
			'registry.unregister-removes-exact-template-and-missing-errors',
			array(
				'name'                  => $name,
				'collisionName'         => $collision_name,
				'unregistered'          => self::template_summary( $unregistered ),
				'unregisteredCollision' => self::template_summary( $unregistered_collision ),
				'missing'               => self::describe_call( $missing ),
			)
		);

		return self::row(
			$ctx,
			'block-templates.registry.lifecycle-duplicates-unregister',
			$failures,
			array(
				'name'      => $name,
				'slug'      => $slug,
				'postTypes' => $case['registry']['postTypes'],
			)
		);
	}

	private static function check_file_template_objects( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures     = array();
		$template_set = \_get_block_templates_files(
			'wp_template',
			array( 'slug__in' => array( $case['templates']['prioritySlug'], $case['templates']['parentOnlySlug'], $case['templates']['weirdSlug'], 'index' ) )
		);
		$priority_file = self::find_template_file( $template_set, $case['templates']['prioritySlug'] );
		$parent_file   = self::find_template_file( $template_set, $case['templates']['parentOnlySlug'] );
		$weird_file    = self::find_template_file( $template_set, $case['templates']['weirdSlug'] );
		$index_file    = self::find_template_file( $template_set, 'index' );

		$child_folders  = \get_block_theme_folders( $case['theme']['childSlug'] );
		$parent_folders = \get_block_theme_folders( $case['theme']['parentSlug'] );

		self::record_if_false(
			$failures,
			$child_folders === $case['theme']['folders'] && $parent_folders === $case['theme']['folders'],
			'get_block_theme_folders.matches-synthetic-folder-mode',
			array(
				'expected' => $case['theme']['folders'],
				'child'    => $child_folders,
				'parent'   => $parent_folders,
			)
		);

		self::record_if_false(
			$failures,
			is_array( $priority_file )
				&& $priority_file['theme'] === $case['theme']['childSlug']
				&& $priority_file['path'] === $case['templates']['priorityChildPath']
				&& is_array( $parent_file )
				&& $parent_file['theme'] === $case['theme']['parentSlug']
				&& $parent_file['path'] === $case['templates']['parentOnlyPath']
				&& is_array( $weird_file )
				&& $weird_file['path'] === $case['templates']['weirdPath']
				&& is_array( $index_file )
				&& $index_file['theme'] === $case['theme']['parentSlug'],
			'_get_block_templates_files.discovers-child-parent-and-weird-files',
			array(
				'priorityFile' => self::file_summary( $priority_file ),
				'parentFile'   => self::file_summary( $parent_file ),
				'weirdFile'    => self::file_summary( $weird_file ),
				'indexFile'    => self::file_summary( $index_file ),
			)
		);

		if ( is_array( $priority_file ) ) {
			$built_priority = \_build_block_template_result_from_file( $priority_file, 'wp_template' );
			self::assert_template_object(
				$failures,
				'file.priority-built-template',
				$built_priority,
				array(
					'id'              => $case['theme']['childSlug'] . '//' . $case['templates']['prioritySlug'],
					'theme'           => $case['theme']['childSlug'],
					'slug'            => $case['templates']['prioritySlug'],
					'type'            => 'wp_template',
					'source'          => 'theme',
					'origin'          => null,
					'status'          => 'publish',
					'has_theme_file'  => true,
					'title'           => $case['templates']['priorityTitle'],
					'post_types'      => $case['templates']['priorityPostTypes'],
					'contentContains' => $case['templates']['priorityChildMarker'],
				)
			);
		}

		$priority = \get_block_file_template( $case['theme']['childSlug'] . '//' . $case['templates']['prioritySlug'], 'wp_template' );
		$parent   = \get_block_file_template( $case['theme']['childSlug'] . '//' . $case['templates']['parentOnlySlug'], 'wp_template' );
		$weird    = \get_block_file_template( $case['theme']['childSlug'] . '//' . $case['templates']['weirdSlug'], 'wp_template' );
		$index    = \get_block_file_template( $case['theme']['childSlug'] . '//index', 'wp_template' );

		self::assert_template_object(
			$failures,
			'file.priority-direct-template-child-wins',
			$priority,
			array(
				'id'              => $case['theme']['childSlug'] . '//' . $case['templates']['prioritySlug'],
				'theme'           => $case['theme']['childSlug'],
				'slug'            => $case['templates']['prioritySlug'],
				'type'            => 'wp_template',
				'source'          => 'theme',
				'has_theme_file'  => true,
				'contentContains' => $case['templates']['priorityChildMarker'],
			)
		);
		self::assert_template_object(
			$failures,
			'file.parent-direct-template-falls-back-to-parent-file',
			$parent,
			array(
				'id'              => $case['theme']['childSlug'] . '//' . $case['templates']['parentOnlySlug'],
				'theme'           => $case['theme']['childSlug'],
				'slug'            => $case['templates']['parentOnlySlug'],
				'type'            => 'wp_template',
				'source'          => 'theme',
				'has_theme_file'  => true,
				'contentContains' => $case['templates']['parentOnlyMarker'],
			)
		);
		self::assert_template_object(
			$failures,
			'file.weird-filename-template-round-trips',
			$weird,
			array(
				'id'              => $case['theme']['childSlug'] . '//' . $case['templates']['weirdSlug'],
				'theme'           => $case['theme']['childSlug'],
				'slug'            => $case['templates']['weirdSlug'],
				'type'            => 'wp_template',
				'source'          => 'theme',
				'has_theme_file'  => true,
				'contentContains' => $case['templates']['weirdMarker'],
			)
		);
		self::assert_template_object(
			$failures,
			'file.default-index-template-is-not-custom',
			$index,
			array(
				'id'              => $case['theme']['childSlug'] . '//index',
				'theme'           => $case['theme']['childSlug'],
				'slug'            => 'index',
				'type'            => 'wp_template',
				'source'          => 'theme',
				'has_theme_file'  => true,
				'is_custom'       => false,
				'contentContains' => $case['templates']['indexMarker'],
			)
		);

		return self::row(
			$ctx,
			'block-templates.file-objects.stable-fields-child-parent',
			$failures,
			array(
				'child'      => $case['theme']['childSlug'],
				'parent'     => $case['theme']['parentSlug'],
				'folderMode' => $case['theme']['folderMode'],
				'slugs'      => array( $case['templates']['prioritySlug'], $case['templates']['parentOnlySlug'], $case['templates']['weirdSlug'], 'index' ),
			)
		);
	}

	private static function check_get_and_list_consistency( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$slugs    = array(
			$case['templates']['prioritySlug'],
			$case['templates']['parentOnlySlug'],
			$case['templates']['customSlug'],
			$case['templates']['weirdSlug'],
			'index',
		);

		$list       = \get_block_templates( array( 'slug__in' => $slugs ), 'wp_template' );
		$by_slug    = self::templates_by_slug( $list );
		$page_list  = \get_block_templates(
			array(
				'slug__in'  => array( $case['templates']['customSlug'] ),
				'post_type' => 'page',
			),
			'wp_template'
		);
		$post_list  = \get_block_templates(
			array(
				'slug__in'  => array( $case['templates']['customSlug'] ),
				'post_type' => 'post',
			),
			'wp_template'
		);
		$raw_files  = \_get_block_templates_files(
			'wp_template',
			array(
				'slug__in'     => array( $case['templates']['prioritySlug'], $case['templates']['parentOnlySlug'] ),
				'slug__not_in' => array( $case['templates']['prioritySlug'] ),
			)
		);
		$get_result = \get_block_template( $case['theme']['childSlug'] . '//' . $case['templates']['prioritySlug'], 'wp_template' );

		foreach ( $slugs as $slug ) {
			if ( ! isset( $by_slug[ $slug ] ) ) {
				self::record_failure(
					$failures,
					'get_block_templates.slug__in-includes-file-template',
					array(
						'missingSlug' => $slug,
						'actualSlugs' => array_keys( $by_slug ),
					)
				);
			}
		}

		self::record_if_false(
			$failures,
			isset( $by_slug[ $case['templates']['prioritySlug'] ] )
				&& $get_result instanceof \WP_Block_Template
				&& $get_result->slug === $by_slug[ $case['templates']['prioritySlug'] ]->slug
				&& $get_result->content === $by_slug[ $case['templates']['prioritySlug'] ]->content
				&& $get_result->source === $by_slug[ $case['templates']['prioritySlug'] ]->source,
			'get_block_template.matches-listed-file-template',
			array(
				'get'  => self::template_summary( $get_result ),
				'list' => isset( $by_slug[ $case['templates']['prioritySlug'] ] ) ? self::template_summary( $by_slug[ $case['templates']['prioritySlug'] ] ) : null,
			)
		);

		self::record_if_false(
			$failures,
			isset( self::templates_by_slug( $page_list )[ $case['templates']['customSlug'] ] )
				&& ! isset( self::templates_by_slug( $post_list )[ $case['templates']['customSlug'] ] ),
			'get_block_templates.post_type-honors-theme-json-post-types',
			array(
				'customSlug' => $case['templates']['customSlug'],
				'pageSlugs'  => array_keys( self::templates_by_slug( $page_list ) ),
				'postSlugs'  => array_keys( self::templates_by_slug( $post_list ) ),
			)
		);

		self::record_if_false(
			$failures,
			array( $case['templates']['parentOnlySlug'] ) === self::file_slugs( $raw_files ),
			'_get_block_templates_files.slug__not_in-excludes-before-build',
			array(
				'expected' => array( $case['templates']['parentOnlySlug'] ),
				'actual'   => self::file_slugs( $raw_files ),
			)
		);

		return self::row(
			$ctx,
			'block-templates.get-list.consistent-file-fallbacks',
			$failures,
			array(
				'slugs'     => $slugs,
				'listCount' => count( $list ),
			)
		);
	}

	private static function check_template_part_resolution( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$header   = \get_block_file_template( $case['theme']['childSlug'] . '//' . $case['parts']['headerSlug'], 'wp_template_part' );
		$footer   = \get_block_template( $case['theme']['childSlug'] . '//' . $case['parts']['footerSlug'], 'wp_template_part' );
		$area_set = \get_block_templates( array( 'area' => $case['parts']['headerArea'] ), 'wp_template_part' );
		$raw_area = \_get_block_templates_files( 'wp_template_part', array( 'area' => $case['parts']['headerArea'] ) );
		$missing  = \get_block_file_template( $case['theme']['childSlug'] . '//missing-part-' . $case['parts']['headerSlug'], 'wp_template_part' );

		self::assert_template_object(
			$failures,
			'part.header-file-template-child-wins-with-area',
			$header,
			array(
				'id'              => $case['theme']['childSlug'] . '//' . $case['parts']['headerSlug'],
				'theme'           => $case['theme']['childSlug'],
				'slug'            => $case['parts']['headerSlug'],
				'type'            => 'wp_template_part',
				'source'          => 'theme',
				'area'            => $case['parts']['headerArea'],
				'has_theme_file'  => true,
				'title'           => $case['parts']['headerTitle'],
				'contentContains' => $case['parts']['headerChildMarker'],
			)
		);
		self::assert_template_object(
			$failures,
			'part.footer-template-round-trips-with-area',
			$footer,
			array(
				'id'              => $case['theme']['childSlug'] . '//' . $case['parts']['footerSlug'],
				'theme'           => $case['theme']['childSlug'],
				'slug'            => $case['parts']['footerSlug'],
				'type'            => 'wp_template_part',
				'source'          => 'theme',
				'area'            => $case['parts']['footerArea'],
				'has_theme_file'  => true,
				'title'           => $case['parts']['footerTitle'],
				'contentContains' => $case['parts']['footerMarker'],
			)
		);

		$area_slugs = array_keys( self::templates_by_slug( $area_set ) );
		self::record_if_false(
			$failures,
			in_array( $case['parts']['headerSlug'], $area_slugs, true )
				&& ! in_array( $case['parts']['footerSlug'], $area_slugs, true )
				&& array( $case['parts']['headerSlug'] ) === self::file_slugs( $raw_area )
				&& null === $missing,
			'template-part.area-query-and-missing-part-fail-closed',
			array(
				'area'      => $case['parts']['headerArea'],
				'areaSlugs' => $area_slugs,
				'rawSlugs'  => self::file_slugs( $raw_area ),
				'missing'   => self::template_summary( $missing ),
			)
		);

		return self::row(
			$ctx,
			'block-templates.template-parts.file-backed-area-resolution',
			$failures,
			array(
				'header' => $case['parts']['headerSlug'],
				'footer' => $case['parts']['footerSlug'],
				'areas'  => array( $case['parts']['headerArea'], $case['parts']['footerArea'] ),
			)
		);
	}

	private static function check_loader_resolution( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$resolved = \resolve_block_template(
			'page',
			array( 'missing.php', $case['templates']['prioritySlug'] . '.php', 'index.php' ),
			''
		);
		$child_php_wins = \resolve_block_template(
			'page',
			array( $case['templates']['fallbackConflictSlug'] . '.php', 'index.php' ),
			$case['templates']['fallbackConflictPhpPath']
		);
		$located = \locate_block_template(
			'',
			'page',
			array( $case['templates']['prioritySlug'] . '.php', 'index.php' )
		);

		global $_wp_current_template_content, $_wp_current_template_id;

		self::record_if_false(
			$failures,
			\current_theme_supports( 'block-templates' )
				&& $resolved instanceof \WP_Block_Template
				&& $case['templates']['prioritySlug'] === $resolved->slug
				&& str_contains( $resolved->content, $case['templates']['priorityChildMarker'] ),
			'resolve_block_template.orders-by-template-hierarchy-priority',
			array(
				'resolved' => self::template_summary( $resolved ),
				'expected' => $case['templates']['prioritySlug'],
			)
		);

		self::record_if_false(
			$failures,
			$child_php_wins instanceof \WP_Block_Template
				&& 'index' === $child_php_wins->slug
				&& str_contains( $child_php_wins->content, $case['templates']['indexMarker'] ),
			'resolve_block_template.child-php-fallback-outranks-parent-block-template',
			array(
				'resolved'     => self::template_summary( $child_php_wins ),
				'fallbackPath' => self::preview( $case['templates']['fallbackConflictPhpPath'] ),
			)
		);

		self::record_if_false(
			$failures,
			ABSPATH . WPINC . '/template-canvas.php' === $located
				&& $case['theme']['childSlug'] . '//' . $case['templates']['prioritySlug'] === $_wp_current_template_id
				&& is_string( $_wp_current_template_content )
				&& str_contains( $_wp_current_template_content, $case['templates']['priorityChildMarker'] ),
			'locate_block_template-sets-current-template-and-canvas',
			array(
				'located'        => self::preview( $located ),
				'currentId'      => $_wp_current_template_id ?? null,
				'contentPreview' => self::preview( $_wp_current_template_content ?? '' ),
			)
		);

		return self::row(
			$ctx,
			'block-templates.loader-resolution.priority-and-globals',
			$failures,
			array(
				'prioritySlug' => $case['templates']['prioritySlug'],
				'conflictSlug' => $case['templates']['fallbackConflictSlug'],
			)
		);
	}

	private static function check_malformed_and_traversal_guards( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$rows     = array();
		$files    = \_get_block_templates_files( 'wp_template' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$path = isset( $file['path'] ) ? realpath( $file['path'] ) : false;
			if ( false === $path || ! self::path_is_in_theme_template_dirs( $path, $case ) ) {
				self::record_failure(
					$failures,
					'_get_block_templates_files.path-stays-inside-active-theme-directories',
					array( 'file' => self::file_summary( $file ) )
				);
			}
		}

		$invalid_type     = \_get_block_templates_files( 'not_a_template_type' );
		$missing_direct  = \get_block_file_template( $case['theme']['childSlug'] . '//missing-' . $case['templates']['prioritySlug'], 'wp_template' );
		$bad_id          = \get_block_file_template( $case['theme']['childSlug'], 'wp_template' );
		$wrong_theme     = \get_block_file_template( 'not-' . $case['theme']['childSlug'] . '//' . $case['templates']['prioritySlug'], 'wp_template' );
		$malformed_slugs = array_intersect(
			array( $case['templates']['malformedPhpSlug'], $case['templates']['malformedBackupSlug'] ),
			self::file_slugs( $files )
		);

		self::record_if_false(
			$failures,
			null === $invalid_type
				&& null === $missing_direct
				&& null === $bad_id
				&& null === $wrong_theme
				&& array() === $malformed_slugs,
			'malformed-template-types-ids-and-non-html-files-fail-closed',
			array(
				'invalidType'    => $invalid_type,
				'missingDirect'  => self::template_summary( $missing_direct ),
				'badId'          => self::template_summary( $bad_id ),
				'wrongTheme'     => self::template_summary( $wrong_theme ),
				'malformedSlugs' => $malformed_slugs,
			)
		);

		$rows[] = self::row(
			$ctx,
			'block-templates.malformed-filenames.fail-closed-enumeration',
			$failures,
			array(
				'fileCount' => is_array( $files ) ? count( $files ) : null,
				'badFiles'  => array( $case['templates']['malformedPhpPath'], $case['templates']['malformedBackupPath'] ),
			)
		);

		$traversal_id     = $case['theme']['childSlug'] . '//' . $case['escape']['slug'];
		$traversal_file   = \get_block_file_template( $traversal_id, 'wp_template' );
		$traversal_public = \get_block_template( $traversal_id, 'wp_template' );
		if ( null !== $traversal_file || null !== $traversal_public ) {
			$rows[] = $ctx->fail(
				'block-templates.path-traversal.direct-id-guard',
				array(
					'id'             => $traversal_id,
					'fileResolved'   => self::template_summary( $traversal_file ),
					'publicResolved' => self::template_summary( $traversal_public ),
					'path'           => self::preview( $case['escape']['path'] ),
				)
			);
		} else {
			$rows[] = $ctx->pass(
				'block-templates.path-traversal.direct-id-guard',
				array(
					'id'             => $traversal_id,
					'fileResolved'   => self::template_summary( $traversal_file ),
					'publicResolved' => self::template_summary( $traversal_public ),
				)
			);
		}

		return $rows;
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$theme_ctx    = $ctx->fork( 'theme' );
		$template_ctx = $ctx->fork( 'templates' );
		$part_ctx     = $ctx->fork( 'parts' );
		$registry_ctx = $ctx->fork( 'registry' );

		$parent_slug = self::slug( $theme_ctx, 'parent' );
		$child_slug  = self::slug( $theme_ctx, 'child' );
		if ( $parent_slug === $child_slug ) {
			$child_slug .= '-child';
		}

		$use_deprecated_folders = $theme_ctx->bool( 35 );
		$folders                = $use_deprecated_folders
			? array(
				'wp_template'      => 'block-templates',
				'wp_template_part' => 'block-template-parts',
			)
			: array(
				'wp_template'      => 'templates',
				'wp_template_part' => 'parts',
			);

		$theme_root = $temp_root . '/themes';
		$parent_dir = $theme_root . '/' . $parent_slug;
		$child_dir  = $theme_root . '/' . $child_slug;

		$priority_slug   = self::slug( $template_ctx, 'priority' );
		$parent_only     = self::slug( $template_ctx, 'parent-only' );
		$custom_slug     = self::slug( $template_ctx, 'custom' );
		$conflict_slug   = self::slug( $template_ctx, 'conflict' );
		$weird_slug      = self::weird_slug( $template_ctx );
		$header_slug     = self::slug( $part_ctx, 'header' );
		$footer_slug     = self::slug( $part_ctx, 'footer' );
		$registry_slug   = self::slug( $registry_ctx, 'registered' );
		$registry_ns     = self::slug( $registry_ctx, 'plugin' );
		$escape_basename = 'escaped-' . self::slug( $template_ctx, 'outside' );

		$priority_child = self::block_markup( $template_ctx, 'priority-child' );
		$priority_parent = self::block_markup( $template_ctx, 'priority-parent' );
		$parent_markup = self::block_markup( $template_ctx, 'parent-only' );
		$custom_markup = self::block_markup( $template_ctx, 'custom' );
		$weird_markup  = self::block_markup( $template_ctx, 'weird' );
		$index_markup  = self::block_markup( $template_ctx, 'index' );
		$conflict_markup = self::block_markup( $template_ctx, 'conflict-parent' );
		$header_child = self::block_markup( $part_ctx, 'header-child' );
		$header_parent = self::block_markup( $part_ctx, 'header-parent' );
		$footer_markup = self::block_markup( $part_ctx, 'footer' );
		$registry_markup = self::block_markup( $registry_ctx, 'registry' );
		$escape_markup = self::block_markup( $template_ctx, 'escaped-outside-theme' );

		$template_dir = $folders['wp_template'];
		$part_dir     = $folders['wp_template_part'];

		return array(
			'tempRoot' => $temp_root,
			'theme'   => array(
				'root'       => $theme_root,
				'rootUri'    => 'http://example.test/wp-content/component-fuzz-block-themes/' . basename( $temp_root ),
				'parentSlug' => $parent_slug,
				'childSlug'  => $child_slug,
				'parentDir'  => $parent_dir,
				'childDir'   => $child_dir,
				'folders'    => $folders,
				'folderMode' => $use_deprecated_folders ? 'deprecated' : 'default',
			),
			'templates' => array(
				'prioritySlug'            => $priority_slug,
				'priorityTitle'           => 'Priority ' . $template_ctx->int( 100, 999 ),
				'priorityPostTypes'       => array( 'page', 'post' ),
				'priorityChildMarkup'     => $priority_child['content'],
				'priorityChildMarker'     => $priority_child['marker'],
				'priorityParentMarkup'    => $priority_parent['content'],
				'priorityParentMarker'    => $priority_parent['marker'],
				'priorityChildPath'       => $child_dir . '/' . $template_dir . '/' . $priority_slug . '.html',
				'priorityParentPath'      => $parent_dir . '/' . $template_dir . '/' . $priority_slug . '.html',
				'parentOnlySlug'          => $parent_only,
				'parentOnlyMarkup'        => $parent_markup['content'],
				'parentOnlyMarker'        => $parent_markup['marker'],
				'parentOnlyPath'          => $parent_dir . '/' . $template_dir . '/' . $parent_only . '.html',
				'customSlug'              => $custom_slug,
				'customTitle'             => 'Custom ' . $template_ctx->int( 100, 999 ),
				'customMarkup'            => $custom_markup['content'],
				'customMarker'            => $custom_markup['marker'],
				'customPath'              => $child_dir . '/' . $template_dir . '/' . $custom_slug . '.html',
				'weirdSlug'               => $weird_slug,
				'weirdMarkup'             => $weird_markup['content'],
				'weirdMarker'             => $weird_markup['marker'],
				'weirdPath'               => $child_dir . '/' . $template_dir . '/' . $weird_slug . '.html',
				'indexMarkup'             => $index_markup['content'],
				'indexMarker'             => $index_markup['marker'],
				'indexPath'               => $parent_dir . '/' . $template_dir . '/index.html',
				'fallbackConflictSlug'    => $conflict_slug,
				'fallbackConflictMarkup'  => $conflict_markup['content'],
				'fallbackConflictMarker'  => $conflict_markup['marker'],
				'fallbackConflictPath'    => $parent_dir . '/' . $template_dir . '/' . $conflict_slug . '.html',
				'fallbackConflictPhpPath' => $child_dir . '/' . $conflict_slug . '.php',
				'malformedPhpSlug'        => $custom_slug . '.php',
				'malformedPhpPath'        => $child_dir . '/' . $template_dir . '/' . $custom_slug . '.php',
				'malformedBackupSlug'     => $weird_slug . '.html.bak',
				'malformedBackupPath'     => $child_dir . '/' . $template_dir . '/' . $weird_slug . '.html.bak',
			),
			'parts' => array(
				'headerSlug'         => $header_slug,
				'headerTitle'        => 'Header ' . $part_ctx->int( 100, 999 ),
				'headerArea'         => $part_ctx->choice( array( 'header', 'navigation-overlay' ) ),
				'headerChildMarkup'  => $header_child['content'],
				'headerChildMarker'  => $header_child['marker'],
				'headerParentMarkup' => $header_parent['content'],
				'headerParentMarker' => $header_parent['marker'],
				'headerChildPath'    => $child_dir . '/' . $part_dir . '/' . $header_slug . '.html',
				'headerParentPath'   => $parent_dir . '/' . $part_dir . '/' . $header_slug . '.html',
				'footerSlug'         => $footer_slug,
				'footerTitle'        => 'Footer ' . $part_ctx->int( 100, 999 ),
				'footerArea'         => $part_ctx->choice( array( 'footer', 'uncategorized' ) ),
				'footerMarkup'       => $footer_markup['content'],
				'footerMarker'       => $footer_markup['marker'],
				'footerPath'         => $child_dir . '/' . $part_dir . '/' . $footer_slug . '.html',
			),
			'registry' => array(
				'namespace'   => $registry_ns,
				'slug'        => $registry_slug,
				'name'        => $registry_ns . '//' . $registry_slug,
				'title'       => 'Registered ' . $registry_ctx->int( 100, 999 ),
				'description' => 'Registered template ' . $registry_ctx->int( 1000, 9999 ),
				'content'     => $registry_markup['content'],
				'marker'      => $registry_markup['marker'],
				'postTypes'   => array( $registry_ctx->choice( array( 'page', 'post' ) ) ),
			),
			'escape' => array(
				'slug'   => '../../../' . $escape_basename,
				'path'   => $temp_root . '/' . $escape_basename . '.html',
				'markup' => $escape_markup['content'],
				'marker' => $escape_markup['marker'],
			),
		);
	}

	private static function write_case_files( array $case ): void {
		self::ensure_dir( $case['theme']['parentDir'] );
		self::ensure_dir( $case['theme']['childDir'] );
		self::ensure_dir( $case['theme']['parentDir'] . '/' . $case['theme']['folders']['wp_template'] );
		self::ensure_dir( $case['theme']['childDir'] . '/' . $case['theme']['folders']['wp_template'] );
		self::ensure_dir( $case['theme']['parentDir'] . '/' . $case['theme']['folders']['wp_template_part'] );
		self::ensure_dir( $case['theme']['childDir'] . '/' . $case['theme']['folders']['wp_template_part'] );

		self::write_file(
			$case['theme']['parentDir'] . '/style.css',
			"/*\nTheme Name: Parent " . $case['theme']['parentSlug'] . "\n*/\n"
		);
		self::write_file(
			$case['theme']['childDir'] . '/style.css',
			"/*\nTheme Name: Child " . $case['theme']['childSlug'] . "\nTemplate: " . $case['theme']['parentSlug'] . "\n*/\n"
		);
		self::write_file(
			$case['theme']['childDir'] . '/theme.json',
			self::json(
				array(
					'version'         => 3,
					'customTemplates' => array(
						array(
							'name'      => $case['templates']['prioritySlug'],
							'title'     => $case['templates']['priorityTitle'],
							'postTypes' => $case['templates']['priorityPostTypes'],
						),
						array(
							'name'      => $case['templates']['customSlug'],
							'title'     => $case['templates']['customTitle'],
							'postTypes' => array( 'page' ),
						),
					),
					'templateParts'   => array(
						array(
							'name'  => $case['parts']['headerSlug'],
							'title' => $case['parts']['headerTitle'],
							'area'  => $case['parts']['headerArea'],
						),
						array(
							'name'  => $case['parts']['footerSlug'],
							'title' => $case['parts']['footerTitle'],
							'area'  => $case['parts']['footerArea'],
						),
					),
				)
			)
		);

		self::write_file( $case['templates']['priorityChildPath'], $case['templates']['priorityChildMarkup'] );
		self::write_file( $case['templates']['priorityParentPath'], $case['templates']['priorityParentMarkup'] );
		self::write_file( $case['templates']['parentOnlyPath'], $case['templates']['parentOnlyMarkup'] );
		self::write_file( $case['templates']['customPath'], $case['templates']['customMarkup'] );
		self::write_file( $case['templates']['weirdPath'], $case['templates']['weirdMarkup'] );
		self::write_file( $case['templates']['indexPath'], $case['templates']['indexMarkup'] );
		self::write_file( $case['templates']['fallbackConflictPath'], $case['templates']['fallbackConflictMarkup'] );
		self::write_file( $case['templates']['fallbackConflictPhpPath'], "<?php\n// Child PHP fallback should outrank a parent block template with the same slug.\n" );
		self::write_file( $case['templates']['malformedPhpPath'], $case['templates']['customMarkup'] );
		self::write_file( $case['templates']['malformedBackupPath'], $case['templates']['weirdMarkup'] );

		self::write_file( $case['parts']['headerChildPath'], $case['parts']['headerChildMarkup'] );
		self::write_file( $case['parts']['headerParentPath'], $case['parts']['headerParentMarkup'] );
		self::write_file( $case['parts']['footerPath'], $case['parts']['footerMarkup'] );
		self::write_file( $case['escape']['path'], $case['escape']['markup'] );
	}

	private static function install_case_filters( array $case ): void {
		$stylesheet = static function () use ( $case ): string {
			return $case['theme']['childSlug'];
		};
		$template = static function () use ( $case ): string {
			return $case['theme']['parentSlug'];
		};
		$theme_root = static function () use ( $case ): string {
			return $case['theme']['root'];
		};
		$theme_root_uri = static function () use ( $case ): string {
			return $case['theme']['rootUri'];
		};
		$stylesheet_root = static function () use ( $case ): string {
			return $case['theme']['root'];
		};

		if ( ! isset( $GLOBALS['wp_theme_directories'] ) || ! is_array( $GLOBALS['wp_theme_directories'] ) ) {
			$GLOBALS['wp_theme_directories'] = array();
		}
		$GLOBALS['wp_theme_directories'] = array_values(
			array_unique(
				array_merge(
					$GLOBALS['wp_theme_directories'],
					array( WP_CONTENT_DIR . '/themes', $case['theme']['root'] )
				)
			)
		);

		\add_filter( 'stylesheet', $stylesheet );
		\add_filter( 'template', $template );
		\add_filter( 'theme_root', $theme_root );
		\add_filter( 'theme_root_uri', $theme_root_uri );
		\add_filter( 'pre_option_stylesheet', $stylesheet );
		\add_filter( 'pre_option_template', $template );
		\add_filter( 'pre_option_stylesheet_root', $stylesheet_root );
		\add_filter( 'pre_option_template_root', $stylesheet_root );
		\add_filter( 'posts_pre_query', array( self::class, 'filter_template_posts_pre_query' ), 10, 2 );
		\add_theme_support( 'block-templates' );
		self::set_static_property( 'WP_Block_Templates_Registry', 'instance', null );
	}

	private static function prepare_sandbox( string $temp_root ): void {
		self::ensure_dir( $temp_root );
		self::ensure_dir( $temp_root . '/themes' );
		self::reset_runtime_caches();
	}

	private static function reset_runtime_caches(): void {
		\wp_cache_delete( 'theme_roots', 'site-transient' );
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			\wp_clean_theme_json_cache();
		}
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): string {
		$base = \sys_get_temp_dir() . '/component-fuzz-block-templates-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . getmypid();
		if ( file_exists( $base ) && ! self::remove_dir_recursive( $base ) ) {
			throw new \RuntimeException( 'Could not clear stale temp root: ' . $base );
		}

		self::ensure_dir( $base );
		return $base;
	}

	private static function ensure_dir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create directory: ' . $dir );
		}
	}

	private static function write_file( string $path, string $contents ): void {
		self::ensure_dir( dirname( $path ) );
		if ( false === file_put_contents( $path, $contents ) ) {
			throw new \RuntimeException( 'Could not write file: ' . $path );
		}
	}

	private static function remove_dir_recursive( string $dir ): bool {
		if ( ! file_exists( $dir ) ) {
			return true;
		}
		if ( ! is_dir( $dir ) ) {
			return @unlink( $dir );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				if ( ! @rmdir( $path ) ) {
					return false;
				}
			} elseif ( ! @unlink( $path ) ) {
				return false;
			}
		}

		return @rmdir( $dir );
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'wp_filter',
					'wp_filters',
					'wp_actions',
					'wp_current_filter',
					'wp_theme_directories',
					'wp_stylesheet_path',
					'wp_template_path',
					'_wp_theme_features',
					'_wp_current_template_content',
					'_wp_current_template_id',
					'wp_object_cache',
					'wp_query',
					'wp_the_query',
				)
			),
			'statics' => self::snapshot_statics(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		foreach ( $snapshot['statics'] as $entry ) {
			self::set_static_property( $entry['class'], $entry['property'], $entry['value'] );
		}
	}

	private static function state_restored( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		foreach ( $snapshot['statics'] as $entry ) {
			if ( self::get_static_property( $entry['class'], $entry['property'] ) !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function snapshot_statics(): array {
		$statics = array();
		foreach (
			array(
				array( 'WP_Block_Templates_Registry', 'instance' ),
				array( 'WP_Theme', 'persistently_cache' ),
				array( 'WP_Theme', 'cache_expiration' ),
			) as $entry
		) {
			if ( ! class_exists( $entry[0] ) ) {
				continue;
			}
			$statics[ $entry[0] . '::' . $entry[1] ] = array(
				'class'    => $entry[0],
				'property' => $entry[1],
				'value'    => self::get_static_property( $entry[0], $entry[1] ),
			);
		}

		return $statics;
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		return self::clone_value( $reflection->getValue() );
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
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

	private static function call_silenced( callable $callback ): array {
		set_error_handler(
			static function (): bool {
				return true;
			}
		);

		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => $e,
			);
		} finally {
			restore_error_handler();
		}
	}

	private static function assert_template_object( array &$failures, string $label, $template, array $expected ): void {
		if ( ! ( $template instanceof \WP_Block_Template ) ) {
			self::record_failure(
				$failures,
				"{$label}.is-wp-block-template",
				array( 'actual' => self::template_summary( $template ) )
			);
			return;
		}

		foreach ( $expected as $property => $value ) {
			if ( 'contentContains' === $property ) {
				if ( ! is_string( $template->content ) || ! str_contains( $template->content, $value ) ) {
					self::record_failure(
						$failures,
						"{$label}.content-contains-marker",
						array(
							'expectedMarker' => $value,
							'actualContent'  => self::preview( $template->content ),
						)
					);
				}
				continue;
			}

			$actual = $template->$property ?? null;
			if ( $actual !== $value ) {
				self::record_failure(
					$failures,
					"{$label}.{$property}-matches",
					array(
						'expected' => $value,
						'actual'   => $actual,
						'template' => self::template_summary( $template ),
					)
				);
			}
		}
	}

	private static function path_is_in_theme_template_dirs( string $path, array $case ): bool {
		foreach ( array( 'childDir', 'parentDir' ) as $dir_key ) {
			foreach ( $case['theme']['folders'] as $folder ) {
				$base = realpath( $case['theme'][ $dir_key ] . '/' . $folder );
				if ( is_string( $base ) && str_starts_with( \wp_normalize_path( $path ), \wp_normalize_path( $base ) . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function find_template_file( $files, string $slug ): ?array {
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_array( $file ) && ( $file['slug'] ?? null ) === $slug ) {
				return $file;
			}
		}

		return null;
	}

	private static function templates_by_slug( array $templates ): array {
		$by_slug = array();
		foreach ( $templates as $template ) {
			if ( $template instanceof \WP_Block_Template ) {
				$by_slug[ $template->slug ] = $template;
			}
		}

		return $by_slug;
	}

	private static function file_slugs( $files ): array {
		$slugs = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_array( $file ) && isset( $file['slug'] ) ) {
				$slugs[] = $file['slug'];
			}
		}
		sort( $slugs );

		return $slugs;
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$slug = strtolower( $prefix . '-' . $ctx->identifier( 4, 10 ) . '-' . $ctx->int( 1, 999 ) );
		$slug = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = (string) preg_replace( '/-+/', '-', $slug );
		$slug = trim( $slug, '-' );

		return '' === $slug ? 'component-fuzz' : $slug;
	}

	private static function weird_slug( \ComponentFuzz\FuzzContext $ctx ): string {
		$byte_suffix = chr( 0xc3 ) . chr( 0xa9 );
		return $ctx->choice(
			array(
				'odd space ' . $ctx->int( 10, 99 ),
				'plus+semi;' . $ctx->int( 10, 99 ),
				'dots..' . $ctx->int( 10, 99 ),
				'byte-' . $byte_suffix . '-' . $ctx->int( 10, 99 ),
			)
		);
	}

	private static function block_markup( \ComponentFuzz\FuzzContext $ctx, string $label ): array {
		$marker = 'component-fuzz-' . self::slug( $ctx, $label );
		return array(
			'marker'  => $marker,
			'content' => '<!-- wp:paragraph --><p data-fuzz="' . $marker . '">' . $label . '</p><!-- /wp:paragraph -->',
		);
	}

	private static function json( array $data ): string {
		$json = \wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Could not encode block template fixture JSON.' );
		}

		return $json . "\n";
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failureCount' => count( $failures ),
				'failures'     => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function record_if_false( array &$failures, bool $ok, string $check, array $data = array() ): void {
		if ( ! $ok ) {
			self::record_failure( $failures, $check, $data );
		}
	}

	private static function record_failure( array &$failures, string $check, array $data = array() ): void {
		if ( count( $failures ) >= self::MAX_FAILURES ) {
			return;
		}

		$failures[] = array(
			'check' => $check,
			'data'  => self::preview_recursive( $data ),
		);
	}

	private static function describe_call( array $call ): array {
		if ( ! empty( $call['threw'] ) ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $call['throwable'] ),
			);
		}

		$value = $call['value'] ?? null;
		if ( \is_wp_error( $value ) ) {
			return array(
				'threw' => false,
				'error' => $value->get_error_codes(),
			);
		}

		return array(
			'threw' => false,
			'value' => self::template_summary( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => basename( $e->getFile() ),
			'line'    => $e->getLine(),
		);
	}

	private static function file_summary( $file ) {
		if ( ! is_array( $file ) ) {
			return $file;
		}

		return array(
			'slug'  => $file['slug'] ?? null,
			'theme' => $file['theme'] ?? null,
			'type'  => $file['type'] ?? null,
			'area'  => $file['area'] ?? null,
			'path'  => isset( $file['path'] ) ? self::preview( $file['path'] ) : null,
		);
	}

	private static function template_summary( $template ) {
		if ( ! ( $template instanceof \WP_Block_Template ) ) {
			if ( \is_wp_error( $template ) ) {
				return array( 'error' => $template->get_error_codes() );
			}

			return is_object( $template ) ? '[object ' . get_class( $template ) . ']' : $template;
		}

		return array(
			'id'             => $template->id ?? null,
			'theme'          => $template->theme ?? null,
			'slug'           => $template->slug ?? null,
			'type'           => $template->type ?? null,
			'source'         => $template->source ?? null,
			'origin'         => $template->origin ?? null,
			'plugin'         => $template->plugin ?? null,
			'area'           => $template->area ?? null,
			'has_theme_file' => $template->has_theme_file ?? null,
			'is_custom'      => $template->is_custom ?? null,
			'content'        => self::preview( $template->content ?? '' ),
		);
	}

	private static function preview_recursive( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::preview_recursive( $item );
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			return self::template_summary( $value );
		}

		return self::preview( $value );
	}

	private static function preview( $value ) {
		if ( is_string( $value ) ) {
			$printable = preg_replace_callback(
				'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
				static function ( array $m ): string {
					return sprintf( '\\x%02X', ord( $m[0] ) );
				},
				$value
			);
			if ( strlen( $printable ) > self::PREVIEW_BYTES ) {
				return substr( $printable, 0, self::PREVIEW_BYTES ) . '...';
			}
			return $printable;
		}

		return $value;
	}
}
