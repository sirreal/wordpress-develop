<?php
namespace ComponentFuzz\Surfaces;

final class PluginThemeSurface {
	public const NAME = 'plugin-theme';

	private const MAX_FAILURES = 10;
	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'plugin-theme.bootstrap-apis-available',
					'Required WordPress plugin/theme APIs are unavailable.',
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
			self::prepare_plugin_path_mapping( $case );

			$rows[] = self::check_plugin_headers( $ctx, $case );
			$rows[] = self::check_plugin_path_helpers( $ctx, $case );
			$rows[] = self::check_plugin_dependency_metadata( $ctx, $case );
			$rows[] = self::check_theme_headers_and_relationships( $ctx, $case );
			$rows[] = self::check_active_theme_file_helpers( $ctx, $case );
			$rows[] = self::check_theme_error_paths( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'plugin-theme.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $temp_root ) {
				self::clear_runtime_caches();
				$cleanup = self::remove_dir_recursive( $temp_root );
			}
			self::restore_state( $snapshot );
		}

		if ( null !== $temp_root ) {
			$rows[] = $ctx->result(
				'plugin-theme.temp-sandbox-cleaned',
				true === $cleanup && ! file_exists( $temp_root ),
				array(
					'root'    => self::preview( $temp_root ),
					'cleaned' => true === $cleanup,
					'exists'  => file_exists( $temp_root ),
				)
			);
		}

		$rows[] = $ctx->result(
			'plugin-theme.global-static-state-restored',
			self::state_restored( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedStatics' => array_keys( $snapshot['statics'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'get_file_data',
				'get_plugin_data',
				'get_plugins',
				'validate_file',
				'validate_plugin',
				'plugin_basename',
				'plugin_dir_path',
				'plugin_dir_url',
				'plugins_url',
				'wp_get_theme',
				'get_theme_root',
				'get_theme_root_uri',
				'get_stylesheet_directory',
				'get_template_directory',
				'get_theme_file_path',
				'get_parent_theme_file_path',
				'get_theme_file_uri',
				'get_parent_theme_file_uri',
				'wp_cache_get',
				'wp_cache_set',
				'wp_cache_delete',
				'is_wp_error',
				'add_filter',
				'wp_normalize_path',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'WP_Theme', 'WP_Plugin_Dependencies', 'WP_Error' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_plugin_headers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$plugin_file = $case['plugin']['mainFile'];
		$headers     = array(
			'Name'            => 'Plugin Name',
			'PluginURI'       => 'Plugin URI',
			'Version'         => 'Version',
			'Description'     => 'Description',
			'Author'          => 'Author',
			'AuthorURI'       => 'Author URI',
			'TextDomain'      => 'Text Domain',
			'DomainPath'      => 'Domain Path',
			'Network'         => 'Network',
			'RequiresWP'      => 'Requires at least',
			'RequiresPHP'     => 'Requires PHP',
			'UpdateURI'       => 'Update URI',
			'RequiresPlugins' => 'Requires Plugins',
		);

		$raw_call    = self::call(
			static function () use ( $plugin_file, $headers ) {
				return \get_file_data( $plugin_file, $headers, 'plugin' );
			}
		);
		$plugin_call = self::call(
			static function () use ( $plugin_file ) {
				return \get_plugin_data( $plugin_file, false, false );
			}
		);

		$failures = array();
		if ( $raw_call['threw'] || ! is_array( $raw_call['value'] ) ) {
			self::record_failure(
				$failures,
				'get_file_data.no-throw-return-array',
				array( 'call' => self::describe_call( $raw_call ) )
			);
		}
		if ( $plugin_call['threw'] || ! is_array( $plugin_call['value'] ) ) {
			self::record_failure(
				$failures,
				'get_plugin_data.no-throw-return-array',
				array( 'call' => self::describe_call( $plugin_call ) )
			);
		}

		if ( ! $raw_call['threw'] && is_array( $raw_call['value'] ) ) {
			foreach ( $case['plugin']['expectedHeaders'] as $field => $expected ) {
				if ( ( $raw_call['value'][ $field ] ?? null ) !== $expected ) {
					self::record_failure(
						$failures,
						"get_file_data.{$field}.cleanup-oracle",
						array(
							'expected' => $expected,
							'actual'   => $raw_call['value'][ $field ] ?? null,
						)
					);
				}

				if ( is_string( $raw_call['value'][ $field ] ?? null ) && self::contains_header_comment_tail( $raw_call['value'][ $field ] ) ) {
					self::record_failure(
						$failures,
						"get_file_data.{$field}.strips-comment-tail",
						array( 'actual' => $raw_call['value'][ $field ] )
					);
				}
			}
		}

		if ( ! $plugin_call['threw'] && is_array( $plugin_call['value'] ) ) {
			$plugin_data = $plugin_call['value'];
			foreach (
				array(
					'Name',
					'PluginURI',
					'Version',
					'Description',
					'Author',
					'AuthorURI',
					'TextDomain',
					'DomainPath',
					'RequiresWP',
					'RequiresPHP',
					'UpdateURI',
					'RequiresPlugins',
				) as $field
			) {
				$expected = $case['plugin']['expectedHeaders'][ $field ];
				if ( ( $plugin_data[ $field ] ?? null ) !== $expected ) {
					self::record_failure(
						$failures,
						"get_plugin_data.{$field}.raw-header-preserved",
						array(
							'expected' => $expected,
							'actual'   => $plugin_data[ $field ] ?? null,
						)
					);
				}
			}

			if ( ( $plugin_data['Network'] ?? null ) !== $case['plugin']['expectedNetwork'] ) {
				self::record_failure(
					$failures,
					'get_plugin_data.Network.boolean-normalized',
					array(
						'expected' => $case['plugin']['expectedNetwork'],
						'actual'   => $plugin_data['Network'] ?? null,
					)
				);
			}

			$title_matches       = ( $plugin_data['Title'] ?? null ) === ( $plugin_data['Name'] ?? null );
			$author_name_matches = ( $plugin_data['AuthorName'] ?? null ) === ( $plugin_data['Author'] ?? null );
			if ( ! $title_matches || ! $author_name_matches ) {
				self::record_failure(
					$failures,
					'get_plugin_data.raw-mode-title-authorname-stable',
					array(
						'title'      => $plugin_data['Title'] ?? null,
						'name'       => $plugin_data['Name'] ?? null,
						'authorName' => $plugin_data['AuthorName'] ?? null,
						'author'     => $plugin_data['Author'] ?? null,
					)
				);
			}
		}

		return self::row(
			$ctx,
			'plugin-theme.plugin.headers-parse-cleanly',
			$failures,
			array(
				'plugin'      => $case['plugin']['basename'],
				'name'        => $case['plugin']['expectedHeaders']['Name'],
				'networkRaw'  => $case['plugin']['rawHeaders']['Network'],
				'requiresRaw' => $case['plugin']['rawHeaders']['RequiresPlugins'],
			)
		);
	}

	private static function check_plugin_path_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$plugin_file = $case['plugin']['mainFile'];
		$basename    = \plugin_basename( $plugin_file );
		$dir_path    = \plugin_dir_path( $plugin_file );
		$dir_url     = \plugin_dir_url( $plugin_file );
		$asset_url   = \plugins_url( 'assets/app.js', $plugin_file );
		$failures    = array();

		if ( $basename !== $case['plugin']['basename'] ) {
			self::record_failure(
				$failures,
				'plugin_basename.maps-temp-realpath-to-plugin-relative-path',
				array(
					'expected' => $case['plugin']['basename'],
					'actual'   => $basename,
				)
			);
		}

		if ( $dir_path !== \trailingslashit( $case['plugin']['dir'] ) ) {
			self::record_failure(
				$failures,
				'plugin_dir_path.returns-real-temp-directory',
				array(
					'expected' => \trailingslashit( $case['plugin']['dir'] ),
					'actual'   => $dir_path,
				)
			);
		}

		$expected_dir_url   = \trailingslashit( WP_PLUGIN_URL . '/' . $case['plugin']['slug'] );
		$expected_asset_url = WP_PLUGIN_URL . '/' . $case['plugin']['slug'] . '/assets/app.js';
		if ( $dir_url !== $expected_dir_url || $asset_url !== $expected_asset_url ) {
			self::record_failure(
				$failures,
				'plugin-url-helpers.use-plugin-basename-folder',
				array(
					'expectedDirUrl'   => $expected_dir_url,
					'actualDirUrl'     => $dir_url,
					'expectedAssetUrl' => $expected_asset_url,
					'actualAssetUrl'   => $asset_url,
				)
			);
		}

		if ( 0 !== \validate_file( $basename, array( $basename ) ) ) {
			self::record_failure(
				$failures,
				'validate_file.accepts-plugin-basename-when-allowed',
				array( 'basename' => $basename )
			);
		}

		foreach ( $case['invalidPluginPaths'] as $invalid ) {
			$validate_file_code = \validate_file( $invalid );
			$validate_plugin    = \validate_plugin( $invalid );
			if ( 0 === $validate_file_code || ! \is_wp_error( $validate_plugin ) || 'plugin_invalid' !== $validate_plugin->get_error_code() ) {
				self::record_failure(
					$failures,
					'validate_plugin.rejects-invalid-relative-paths',
					array(
						'path'              => $invalid,
						'validateFileCode'  => $validate_file_code,
						'validatePlugin'    => self::describe_wp_error( $validate_plugin ),
						'validatePluginRaw' => self::describe_value( $validate_plugin ),
					)
				);
			}
		}

		$missing_plugin = \validate_plugin( $basename );
		if ( ! \is_wp_error( $missing_plugin ) || 'plugin_not_found' !== $missing_plugin->get_error_code() ) {
			self::record_failure(
				$failures,
				'validate_plugin.valid-relative-temp-plugin-is-not-found-under-real-plugin-dir',
				array(
					'basename' => $basename,
					'actual'   => self::describe_wp_error( $missing_plugin ),
				)
			);
		}

		return self::row(
			$ctx,
			'plugin-theme.plugin.path-helper-round-trips',
			$failures,
			array(
				'basename'       => $basename,
				'dirPath'        => self::preview( $dir_path ),
				'dirUrl'         => $dir_url,
				'invalidSamples' => $case['invalidPluginPaths'],
			)
		);
	}

	private static function check_plugin_dependency_metadata( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_plugin_dependency_state();
		\wp_cache_set( 'plugins', array( '' => $case['dependencyPlugins'] ), 'plugins' );

		$initialize = self::call(
			static function () {
				\WP_Plugin_Dependencies::initialize();
				return true;
			}
		);

		$failures = array();
		if ( $initialize['threw'] ) {
			self::record_failure(
				$failures,
				'WP_Plugin_Dependencies.initialize.no-throw',
				array( 'call' => self::describe_call( $initialize ) )
			);
		} else {
			$main_file       = $case['dependencies']['mainFile'];
			$dependency_file = $case['dependencies']['dependencyFile'];
			$main_slug       = $case['dependencies']['mainSlug'];
			$dependency_slug = $case['dependencies']['dependencySlug'];
			$missing_slug    = $case['dependencies']['missingSlug'];
			$expected        = array( $dependency_slug, $missing_slug );
			sort( $expected );

			$dependencies = \WP_Plugin_Dependencies::get_dependencies( $main_file );
			if ( $dependencies !== $expected ) {
				self::record_failure(
					$failures,
					'WP_Plugin_Dependencies.sanitizes-unique-sorted-slugs',
					array(
						'expected' => $expected,
						'actual'   => $dependencies,
						'raw'      => $case['dependencyPlugins'][ $main_file ]['RequiresPlugins'],
					)
				);
			}

			if ( ! \WP_Plugin_Dependencies::has_dependencies( $main_file ) ) {
				self::record_failure( $failures, 'WP_Plugin_Dependencies.has-dependencies-for-dependent', array( 'plugin' => $main_file ) );
			}

			if ( ! \WP_Plugin_Dependencies::has_dependents( $dependency_file ) ) {
				self::record_failure(
					$failures,
					'WP_Plugin_Dependencies.has-dependents-for-installed-dependency',
					array( 'plugin' => $dependency_file )
				);
			}

			$dependents = \WP_Plugin_Dependencies::get_dependents( $dependency_slug );
			if ( ! in_array( $main_file, $dependents, true ) ) {
				self::record_failure(
					$failures,
					'WP_Plugin_Dependencies.get-dependents-includes-dependent-file',
					array(
						'slug'       => $dependency_slug,
						'expectedIn' => $main_file,
						'actual'     => $dependents,
					)
				);
			}

			if ( \WP_Plugin_Dependencies::get_dependency_filepath( $dependency_slug ) !== $dependency_file ) {
				self::record_failure(
					$failures,
					'WP_Plugin_Dependencies.installed-dependency-filepath-resolves',
					array(
						'slug'     => $dependency_slug,
						'expected' => $dependency_file,
						'actual'   => \WP_Plugin_Dependencies::get_dependency_filepath( $dependency_slug ),
					)
				);
			}

			if ( false !== \WP_Plugin_Dependencies::get_dependency_filepath( $missing_slug ) ) {
				self::record_failure(
					$failures,
					'WP_Plugin_Dependencies.missing-dependency-filepath-is-false',
					array(
						'slug'   => $missing_slug,
						'actual' => \WP_Plugin_Dependencies::get_dependency_filepath( $missing_slug ),
					)
				);
			}

			if ( \WP_Plugin_Dependencies::get_dependent_filepath( $main_slug ) !== $main_file ) {
				self::record_failure(
					$failures,
					'WP_Plugin_Dependencies.dependent-filepath-round-trips-from-slug',
					array(
						'slug'     => $main_slug,
						'expected' => $main_file,
						'actual'   => \WP_Plugin_Dependencies::get_dependent_filepath( $main_slug ),
					)
				);
			}

			$main_circular       = \WP_Plugin_Dependencies::has_circular_dependency( $main_file );
			$dependency_circular = \WP_Plugin_Dependencies::has_circular_dependency( $dependency_file );
			if ( ! $main_circular || ! $dependency_circular ) {
				self::record_failure(
					$failures,
					'WP_Plugin_Dependencies.circular-dependency-detected-from-metadata',
					array(
						'mainFile'           => $main_file,
						'dependencyFile'     => $dependency_file,
						'mainCircular'       => $main_circular,
						'dependencyCircular' => $dependency_circular,
					)
				);
			}
		}

		return self::row(
			$ctx,
			'plugin-theme.plugin.dependency-metadata-no-db',
			$failures,
			array(
				'plugins'    => array_keys( $case['dependencyPlugins'] ),
				'mainSlug'   => $case['dependencies']['mainSlug'],
				'dependency' => $case['dependencies']['dependencySlug'],
			)
		);
	}

	private static function check_theme_headers_and_relationships( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$theme = self::call(
			static function () use ( $case ) {
				return \wp_get_theme( $case['theme']['childSlug'], $case['theme']['root'] );
			}
		);

		$failures = array();
		if ( $theme['threw'] || ! $theme['value'] instanceof \WP_Theme ) {
			self::record_failure(
				$failures,
				'wp_get_theme.no-throw-return-theme',
				array( 'call' => self::describe_call( $theme ) )
			);
		} else {
			$theme_object = $theme['value'];
			$parent       = $theme_object->parent();

			if ( ! $theme_object->exists() || false !== $theme_object->errors() ) {
				self::record_failure(
					$failures,
					'WP_Theme.valid-child-exists-without-errors',
					array(
						'exists' => $theme_object->exists(),
						'errors' => self::describe_wp_error( $theme_object->errors() ),
					)
				);
			}

			foreach (
				array(
					'Name',
					'ThemeURI',
					'Description',
					'Author',
					'AuthorURI',
					'Version',
					'Status',
					'TextDomain',
					'DomainPath',
					'RequiresWP',
					'RequiresPHP',
					'UpdateURI',
				) as $field
			) {
				$expected = $case['theme']['expectedChildHeaders'][ $field ];
				$actual   = $theme_object->get( $field );
				if ( $actual !== $expected ) {
					self::record_failure(
						$failures,
						"WP_Theme.get.{$field}.sanitized-header-oracle",
						array(
							'expected' => $expected,
							'actual'   => $actual,
						)
					);
				}
			}

			$template_matches   = $theme_object->get_template() === $case['theme']['parentSlug'];
			$stylesheet_matches = $theme_object->get_stylesheet() === $case['theme']['childSlug'];
			if ( ! $template_matches || ! $stylesheet_matches ) {
				self::record_failure(
					$failures,
					'WP_Theme.child-template-stylesheet-stable',
					array(
						'expectedTemplate'   => $case['theme']['parentSlug'],
						'actualTemplate'     => $theme_object->get_template(),
						'expectedStylesheet' => $case['theme']['childSlug'],
						'actualStylesheet'   => $theme_object->get_stylesheet(),
					)
				);
			}

			$parent_slug_matches = $parent instanceof \WP_Theme && $parent->get_stylesheet() === $case['theme']['parentSlug'];
			$parent_name_matches = $parent instanceof \WP_Theme && $parent->get( 'Name' ) === $case['theme']['expectedParentHeaders']['Name'];
			if ( ! $parent_slug_matches || ! $parent_name_matches ) {
				self::record_failure(
					$failures,
					'WP_Theme.parent-theme-resolves-from-template-header',
					array(
						'parentClass'      => is_object( $parent ) ? get_class( $parent ) : gettype( $parent ),
						'expectedSlug'     => $case['theme']['parentSlug'],
						'actualSlug'       => $parent instanceof \WP_Theme ? $parent->get_stylesheet() : null,
						'expectedName'     => $case['theme']['expectedParentHeaders']['Name'],
						'actualParentName' => $parent instanceof \WP_Theme ? $parent->get( 'Name' ) : null,
					)
				);
			}

			$root_matches          = $theme_object->get_theme_root() === $case['theme']['root'];
			$stylesheet_dir_match = $theme_object->get_stylesheet_directory() === $case['theme']['childDir'];
			$template_dir_match   = $theme_object->get_template_directory() === $case['theme']['parentDir'];
			if ( ! $root_matches || ! $stylesheet_dir_match || ! $template_dir_match ) {
				self::record_failure(
					$failures,
					'WP_Theme.directory-helpers-use-explicit-temp-root',
					array(
						'themeRoot'           => $theme_object->get_theme_root(),
						'expectedThemeRoot'   => $case['theme']['root'],
						'stylesheetDirectory' => $theme_object->get_stylesheet_directory(),
						'expectedStylesheet'  => $case['theme']['childDir'],
						'templateDirectory'   => $theme_object->get_template_directory(),
						'expectedTemplate'    => $case['theme']['parentDir'],
					)
				);
			}

			$relative_screenshot = $theme_object->get_screenshot( 'relative' );
			$uri_screenshot      = $theme_object->get_screenshot( 'uri' );
			$expected_screenshot_suffix = '/' . $case['theme']['childSlug'] . '/' . $case['theme']['screenshot'];
			if (
				$relative_screenshot !== $case['theme']['screenshot']
				|| ! is_string( $uri_screenshot )
				|| ! str_ends_with( $uri_screenshot, $expected_screenshot_suffix )
			) {
				self::record_failure(
					$failures,
					'WP_Theme.screenshot-resolves-from-child-directory',
					array(
						'expectedRelative' => $case['theme']['screenshot'],
						'actualRelative'   => $relative_screenshot,
						'actualUri'        => $uri_screenshot,
					)
				);
			}

			$tags = $theme_object->get( 'Tags' );
			if ( $tags !== $case['theme']['expectedTags'] ) {
				self::record_failure(
					$failures,
					'WP_Theme.tags-split-trim-strip-tags',
					array(
						'expected' => $case['theme']['expectedTags'],
						'actual'   => $tags,
					)
				);
			}

			$display_description = $theme_object->display( 'Description', true, false );
			if (
				! is_string( $display_description )
				|| false !== stripos( $display_description, '<script' )
				|| false !== stripos( $display_description, 'javascript:' )
			) {
				self::record_failure(
					$failures,
					'WP_Theme.display-description-escapes-disallowed-markup',
					array( 'description' => $display_description )
				);
			}
		}

		return self::row(
			$ctx,
			'plugin-theme.theme.headers-and-parent-child-stable',
			$failures,
			array(
				'child'  => $case['theme']['childSlug'],
				'parent' => $case['theme']['parentSlug'],
				'root'   => self::preview( $case['theme']['root'] ),
			)
		);
	}

	private static function check_active_theme_file_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::install_active_theme_filters( $case );

		$failures          = array();
		$stylesheet_dir    = \get_stylesheet_directory();
		$template_dir      = \get_template_directory();
		$stylesheet_uri    = \get_stylesheet_directory_uri();
		$template_uri      = \get_template_directory_uri();
		$child_file_path   = \get_theme_file_path( 'override.php' );
		$parent_file_path  = \get_theme_file_path( 'fallback.php' );
		$direct_parent     = \get_parent_theme_file_path( 'fallback.php' );
		$child_file_uri    = \get_theme_file_uri( 'override.php' );
		$parent_file_uri   = \get_theme_file_uri( 'fallback.php' );
		$direct_parent_uri = \get_parent_theme_file_uri( 'fallback.php' );

		$expectations = array(
			'stylesheetDir'   => array( $case['theme']['childDir'], $stylesheet_dir ),
			'templateDir'     => array( $case['theme']['parentDir'], $template_dir ),
			'stylesheetUri'   => array( $case['theme']['rootUri'] . '/' . $case['theme']['childSlug'], $stylesheet_uri ),
			'templateUri'     => array( $case['theme']['rootUri'] . '/' . $case['theme']['parentSlug'], $template_uri ),
			'childFilePath'   => array( $case['theme']['childDir'] . '/override.php', $child_file_path ),
			'parentFilePath'  => array( $case['theme']['parentDir'] . '/fallback.php', $parent_file_path ),
			'directParent'    => array( $case['theme']['parentDir'] . '/fallback.php', $direct_parent ),
			'childFileUri'    => array( $case['theme']['rootUri'] . '/' . $case['theme']['childSlug'] . '/override.php', $child_file_uri ),
			'parentFileUri'   => array( $case['theme']['rootUri'] . '/' . $case['theme']['parentSlug'] . '/fallback.php', $parent_file_uri ),
			'directParentUri' => array( $case['theme']['rootUri'] . '/' . $case['theme']['parentSlug'] . '/fallback.php', $direct_parent_uri ),
		);

		foreach ( $expectations as $name => $pair ) {
			if ( $pair[0] !== $pair[1] ) {
				self::record_failure(
					$failures,
					"theme-file-helper.{$name}.matches-oracle",
					array(
						'expected' => $pair[0],
						'actual'   => $pair[1],
					)
				);
			}
		}

		return self::row(
			$ctx,
			'plugin-theme.theme.active-file-helpers-child-before-parent',
			$failures,
			array(
				'stylesheetDir'  => self::preview( $stylesheet_dir ),
				'templateDir'    => self::preview( $template_dir ),
				'childFilePath'  => self::preview( $child_file_path ),
				'parentFilePath' => self::preview( $parent_file_path ),
			)
		);
	}

	private static function check_theme_error_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$missing = new \WP_Theme( $case['theme']['missingSlug'], $case['theme']['root'] );
		$broken  = new \WP_Theme( $case['theme']['brokenSlug'], $case['theme']['root'] );
		$self    = new \WP_Theme( $case['theme']['selfChildSlug'], $case['theme']['root'] );

		$failures = array();

		self::expect_theme_error(
			$failures,
			'missing-theme',
			$missing,
			array( 'theme_not_found' ),
			false
		);
		self::expect_theme_error(
			$failures,
			'broken-theme-without-style',
			$broken,
			array( 'theme_no_stylesheet' ),
			true
		);
		self::expect_theme_error(
			$failures,
			'self-parent-child-theme',
			$self,
			array( 'theme_child_invalid' ),
			true
		);

		return self::row(
			$ctx,
			'plugin-theme.theme.missing-broken-themes-report-errors',
			$failures,
			array(
				'missing' => $case['theme']['missingSlug'],
				'broken'  => $case['theme']['brokenSlug'],
				'self'    => $case['theme']['selfChildSlug'],
			)
		);
	}

	private static function expect_theme_error(
		array &$failures,
		string $label,
		\WP_Theme $theme,
		array $expected_codes,
		bool $expected_exists
	): void {
		$error = $theme->errors();
		$codes = \is_wp_error( $error ) ? $error->get_error_codes() : array();

		foreach ( $expected_codes as $code ) {
			if ( ! in_array( $code, $codes, true ) ) {
				self::record_failure(
					$failures,
					"WP_Theme.errors.{$label}.expected-code",
					array(
						'expectedCode' => $code,
						'actualCodes'  => $codes,
						'stylesheet'   => $theme->get_stylesheet(),
					)
				);
			}
		}

		if ( $theme->exists() !== $expected_exists ) {
			self::record_failure(
				$failures,
				"WP_Theme.exists.{$label}.matches-error-contract",
				array(
					'expected' => $expected_exists,
					'actual'   => $theme->exists(),
					'codes'    => $codes,
				)
			);
		}
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$plugin_ctx = $ctx->fork( 'plugin' );
		$theme_ctx  = $ctx->fork( 'theme' );

		$plugin_slug = self::slug( $plugin_ctx, 'plugin' );
		$plugin_dir  = $temp_root . '/plugins/' . $plugin_slug;
		$plugin_file = $plugin_dir . '/' . $plugin_slug . '.php';

		$plugin_raw_headers = array(
			'Name'            => 'Component Fuzz ' . self::label( $plugin_ctx, 'Plugin' ) . ' */ hidden',
			'PluginURI'       => 'https://example.test/plugins/' . $plugin_slug . '?q=' . $plugin_ctx->int( 1, 999 ) . ' ?> hidden',
			'Version'         => $plugin_ctx->int( 0, 9 ) . '.' . $plugin_ctx->int( 0, 20 ) . '.' . $plugin_ctx->int( 0, 50 ) . ' */ old',
			'Description'     => 'Description ' . self::label( $plugin_ctx, 'Plugin' ) . ' <strong>ok</strong> */ unsafe',
			'Author'          => 'Author ' . self::label( $plugin_ctx, 'Plugin' ) . ' ?> unsafe',
			'AuthorURI'       => 'https://authors.example.test/' . $plugin_slug . ' */ tail',
			'TextDomain'      => $plugin_slug . ' ?> tail',
			'DomainPath'      => '/languages-' . $plugin_ctx->int( 1, 9 ) . ' */ tail',
			'Network'         => $plugin_ctx->choice( array( 'true', 'TRUE', 'false', '1', '' ) ) . ' */ tail',
			'RequiresWP'      => '6.' . $plugin_ctx->int( 0, 9 ) . ' */ tail',
			'RequiresPHP'     => '7.' . $plugin_ctx->int( 4, 9 ) . ' ?> tail',
			'UpdateURI'       => 'https://updates.example.test/' . $plugin_slug . ' */ tail',
			'RequiresPlugins' => 'dep-' . $plugin_slug . ', missing-' . $plugin_slug . ', bad_slug, dep-' . $plugin_slug . ' */ tail',
		);

		$plugin_expected_headers = array();
		foreach ( $plugin_raw_headers as $field => $value ) {
			$plugin_expected_headers[ $field ] = self::cleanup_header_comment( $value );
		}

		$parent_slug = self::slug( $theme_ctx, 'parent' );
		$child_slug  = self::slug( $theme_ctx, 'child' );
		if ( $parent_slug === $child_slug ) {
			$child_slug .= '-child';
		}
		$theme_root = $temp_root . '/themes';
		$parent_dir = $theme_root . '/' . $parent_slug;
		$child_dir  = $theme_root . '/' . $child_slug;

		$theme_child_raw_headers = array(
			'Name'        => 'Child ' . self::label( $theme_ctx, 'Theme' ) . ' */ hidden',
			'ThemeURI'    => 'https://themes.example.test/' . $child_slug . ' ?> hidden',
			'Description' => 'Child <strong>description</strong> <script>alert(1)</script><a href="javascript:alert(1)">bad</a>',
			'Author'      => 'Theme Author <script>alert(1)</script>',
			'AuthorURI'   => 'https://authors.example.test/' . $child_slug,
			'Version'     => '1.' . $theme_ctx->int( 0, 9 ) . '.' . $theme_ctx->int( 0, 99 ) . ' */ hidden',
			'Template'    => $parent_slug,
			'Status'      => $theme_ctx->choice( array( '', 'publish', 'private' ) ),
			'Tags'        => 'wide-blocks, custom-logo, <b>tagged</b>, fixed-width',
			'TextDomain'  => $child_slug,
			'DomainPath'  => '/languages',
			'RequiresWP'  => '6.' . $theme_ctx->int( 0, 9 ),
			'RequiresPHP' => '7.' . $theme_ctx->int( 4, 9 ),
			'UpdateURI'   => 'https://updates.example.test/themes/' . $child_slug,
		);

		$theme_parent_raw_headers = array(
			'Name'        => 'Parent ' . self::label( $theme_ctx, 'Theme' ),
			'ThemeURI'    => 'https://themes.example.test/' . $parent_slug,
			'Description' => 'Parent description',
			'Author'      => 'Parent Author',
			'AuthorURI'   => 'https://authors.example.test/' . $parent_slug,
			'Version'     => '2.' . $theme_ctx->int( 0, 9 ) . '.0',
			'Template'    => '',
			'Status'      => 'publish',
			'Tags'        => 'block-patterns',
			'TextDomain'  => $parent_slug,
			'DomainPath'  => '/languages',
			'RequiresWP'  => '6.0',
			'RequiresPHP' => '7.4',
			'UpdateURI'   => 'https://updates.example.test/themes/' . $parent_slug,
		);

		$dependency_main_slug = self::dependency_slug( $plugin_slug . '-main' );
		$dependency_slug      = self::dependency_slug( 'dep-' . $plugin_slug );
		$missing_slug         = self::dependency_slug( 'missing-' . $plugin_slug );
		$dependency_main_file = $dependency_main_slug . '/' . $dependency_main_slug . '.php';
		$dependency_file      = $dependency_slug . '/' . $dependency_slug . '.php';

		return array(
			'tempRoot'           => $temp_root,
			'invalidPluginPaths' => self::invalid_plugin_paths( $plugin_ctx ),
			'plugin'             => array(
				'slug'             => $plugin_slug,
				'dir'              => $plugin_dir,
				'mainFile'         => $plugin_file,
				'basename'         => $plugin_slug . '/' . $plugin_slug . '.php',
				'fakePluginDir'    => WP_PLUGIN_DIR . '/' . $plugin_slug,
				'rawHeaders'       => $plugin_raw_headers,
				'expectedHeaders'  => $plugin_expected_headers,
				'expectedNetwork'  => ( 'true' === strtolower( $plugin_expected_headers['Network'] ) ),
			),
			'dependencyPlugins'  => array(
				$dependency_main_file => self::plugin_data_for_dependency_case(
					'Main ' . $dependency_main_slug,
					$dependency_slug . ', ' . $missing_slug . ', bad_slug, ' . $dependency_slug,
					'https://example.test/plugins/' . $dependency_main_slug
				),
				$dependency_file      => self::plugin_data_for_dependency_case(
					'Dependency ' . $dependency_slug,
					$dependency_main_slug,
					'https://example.test/plugins/' . $dependency_slug
				),
			),
			'dependencies'       => array(
				'mainSlug'       => $dependency_main_slug,
				'mainFile'       => $dependency_main_file,
				'dependencySlug' => $dependency_slug,
				'dependencyFile' => $dependency_file,
				'missingSlug'    => $missing_slug,
			),
			'theme'              => array(
				'root'                   => $theme_root,
				'rootUri'                => 'http://example.test/wp-content/component-fuzz-themes/' . basename( $temp_root ),
				'parentSlug'             => $parent_slug,
				'childSlug'              => $child_slug,
				'parentDir'              => $parent_dir,
				'childDir'               => $child_dir,
				'brokenSlug'             => 'broken-' . $child_slug,
				'missingSlug'            => 'missing-' . $child_slug,
				'selfChildSlug'          => 'self-' . $child_slug,
				'screenshot'             => 'screenshot.webp',
				'childRawHeaders'        => $theme_child_raw_headers,
				'parentRawHeaders'       => $theme_parent_raw_headers,
				'expectedChildHeaders'   => self::expected_theme_headers( $theme_child_raw_headers ),
				'expectedParentHeaders'  => self::expected_theme_headers( $theme_parent_raw_headers ),
				'expectedTags'           => array( 'wide-blocks', 'custom-logo', 'tagged', 'fixed-width' ),
			),
		);
	}

	private static function write_case_files( array $case ): void {
		self::ensure_dir( $case['plugin']['dir'] );
		self::ensure_dir( $case['plugin']['dir'] . '/assets' );
		self::write_file(
			$case['plugin']['mainFile'],
			"<?php\n" . self::header_block( $case['plugin']['rawHeaders'], self::plugin_header_labels() ) . "\n"
		);
		self::write_file( $case['plugin']['dir'] . '/assets/app.js', "window.componentFuzzPluginTheme = true;\n" );

		self::ensure_dir( $case['theme']['parentDir'] );
		self::ensure_dir( $case['theme']['childDir'] );
		self::ensure_dir( $case['theme']['root'] . '/' . $case['theme']['brokenSlug'] );
		self::ensure_dir( $case['theme']['root'] . '/' . $case['theme']['selfChildSlug'] );

		self::write_file(
			$case['theme']['parentDir'] . '/style.css',
			self::header_block( $case['theme']['parentRawHeaders'], self::theme_header_labels() )
		);
		self::write_file( $case['theme']['parentDir'] . '/index.php', "<?php\n// Parent index.\n" );
		self::write_file( $case['theme']['parentDir'] . '/fallback.php', "<?php\n// Parent fallback.\n" );

		self::write_file(
			$case['theme']['childDir'] . '/style.css',
			self::header_block( $case['theme']['childRawHeaders'], self::theme_header_labels() )
		);
		self::write_file( $case['theme']['childDir'] . '/override.php', "<?php\n// Child override.\n" );
		self::write_file( $case['theme']['childDir'] . '/' . $case['theme']['screenshot'], "RIFF\x00\x00\x00\x00WEBPVP8 " );

		self::write_file(
			$case['theme']['root'] . '/' . $case['theme']['selfChildSlug'] . '/style.css',
			self::header_block(
				array(
					'Name'        => 'Self Parent',
					'ThemeURI'    => '',
					'Description' => 'Invalid child theme.',
					'Author'      => '',
					'AuthorURI'   => '',
					'Version'     => '1.0',
					'Template'    => $case['theme']['selfChildSlug'],
					'Status'      => 'publish',
					'Tags'        => '',
					'TextDomain'  => '',
					'DomainPath'  => '',
					'RequiresWP'  => '',
					'RequiresPHP' => '',
					'UpdateURI'   => '',
				),
				self::theme_header_labels()
			)
		);
	}

	private static function prepare_sandbox( string $temp_root ): void {
		self::ensure_dir( $temp_root );
		self::ensure_dir( $temp_root . '/plugins' );
		self::ensure_dir( $temp_root . '/themes' );
		self::clear_runtime_caches();
	}

	private static function prepare_plugin_path_mapping( array $case ): void {
		if ( ! isset( $GLOBALS['wp_plugin_paths'] ) || ! is_array( $GLOBALS['wp_plugin_paths'] ) ) {
			$GLOBALS['wp_plugin_paths'] = array();
		}

		$GLOBALS['wp_plugin_paths'][ \wp_normalize_path( $case['plugin']['fakePluginDir'] ) ] = \wp_normalize_path( $case['plugin']['dir'] );
	}

	private static function install_active_theme_filters( array $case ): void {
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

		\add_filter( 'stylesheet', $stylesheet );
		\add_filter( 'template', $template );
		\add_filter( 'theme_root', $theme_root );
		\add_filter( 'theme_root_uri', $theme_root_uri );
	}

	private static function plugin_header_labels(): array {
		return array(
			'Name'            => 'Plugin Name',
			'PluginURI'       => 'Plugin URI',
			'Version'         => 'Version',
			'Description'     => 'Description',
			'Author'          => 'Author',
			'AuthorURI'       => 'Author URI',
			'TextDomain'      => 'Text Domain',
			'DomainPath'      => 'Domain Path',
			'Network'         => 'Network',
			'RequiresWP'      => 'Requires at least',
			'RequiresPHP'     => 'Requires PHP',
			'UpdateURI'       => 'Update URI',
			'RequiresPlugins' => 'Requires Plugins',
		);
	}

	private static function theme_header_labels(): array {
		return array(
			'Name'        => 'Theme Name',
			'ThemeURI'    => 'Theme URI',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Version'     => 'Version',
			'Template'    => 'Template',
			'Status'      => 'Status',
			'Tags'        => 'Tags',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
			'UpdateURI'   => 'Update URI',
		);
	}

	private static function header_block( array $headers, array $labels ): string {
		$lines = array( '/*' );
		foreach ( $labels as $field => $label ) {
			$lines[] = $label . ': ' . ( $headers[ $field ] ?? '' );
		}
		$lines[] = '*/';

		return implode( "\n", $lines ) . "\n";
	}

	private static function expected_theme_headers( array $raw_headers ): array {
		$headers = array();
		foreach ( $raw_headers as $field => $value ) {
			$headers[ $field ] = self::cleanup_header_comment( $value );
		}

		foreach ( array( 'Name', 'Status' ) as $field ) {
			if ( 'Status' === $field && '' === $headers[ $field ] ) {
				$headers[ $field ] = 'publish';
			}
			$headers[ $field ] = \wp_kses(
				$headers[ $field ],
				array(
					'abbr'    => array( 'title' => true ),
					'acronym' => array( 'title' => true ),
					'code'    => true,
					'em'      => true,
					'strong'  => true,
				)
			);
		}

		foreach ( array( 'Author', 'Description' ) as $field ) {
			$headers[ $field ] = \wp_kses(
				$headers[ $field ],
				array(
					'a'       => array(
						'href'  => true,
						'title' => true,
					),
					'abbr'    => array( 'title' => true ),
					'acronym' => array( 'title' => true ),
					'code'    => true,
					'em'      => true,
					'strong'  => true,
				)
			);
		}

		foreach ( array( 'ThemeURI', 'AuthorURI' ) as $field ) {
			$headers[ $field ] = \sanitize_url( $headers[ $field ] );
		}

		foreach ( array( 'Version', 'RequiresWP', 'RequiresPHP', 'UpdateURI' ) as $field ) {
			$headers[ $field ] = strip_tags( $headers[ $field ] );
		}

		return $headers;
	}

	private static function plugin_data_for_dependency_case( string $name, string $requires_plugins, string $uri ): array {
		return array(
			'Name'            => $name,
			'PluginURI'       => $uri,
			'Version'         => '1.0.0',
			'Description'     => 'Dependency metadata fixture.',
			'Author'          => 'Component Fuzz',
			'AuthorURI'       => 'https://example.test/',
			'TextDomain'      => '',
			'DomainPath'      => '',
			'Network'         => false,
			'RequiresWP'      => '',
			'RequiresPHP'     => '',
			'UpdateURI'       => '',
			'RequiresPlugins' => $requires_plugins,
			'Title'           => $name,
			'AuthorName'      => 'Component Fuzz',
		);
	}

	private static function invalid_plugin_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$paths = array(
			'../escape.php',
			'../../double/escape.php',
			'folder/../../escape.php',
			'C:/absolute/plugin.php',
		);

		$generated = $ctx->choice(
			array(
				'bad/../mid/plugin.php',
				'../',
				'plugin/../../file.php',
				'Z:\\absolute\\plugin.php',
			)
		);
		$paths[] = $generated;

		return array_values( array_unique( $paths ) );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return self::dependency_slug( $prefix . '-' . strtolower( $ctx->identifier( 4, 10 ) ) . '-' . $ctx->int( 1, 999 ) );
	}

	private static function dependency_slug( string $slug ): string {
		$slug = strtolower( $slug );
		$slug = (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug );
		$slug = (string) preg_replace( '/-+/', '-', $slug );
		$slug = trim( $slug, '-' );

		return '' === $slug ? 'component-fuzz' : $slug;
	}

	private static function label( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return $prefix . ' ' . $ctx->int( 100, 999 ) . ' ' . $ctx->identifier( 3, 8 );
	}

	private static function cleanup_header_comment( string $value ): string {
		return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $value ) );
	}

	private static function contains_header_comment_tail( string $value ): bool {
		return false !== strpos( $value, '*/' ) || false !== strpos( $value, '?>' );
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): string {
		$base = \sys_get_temp_dir() . '/component-fuzz-plugin-theme-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . getmypid();
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

	private static function clear_runtime_caches(): void {
		\wp_cache_delete( 'plugins', 'plugins' );
		\wp_cache_delete( 'theme_roots', 'site-transient' );
		self::reset_plugin_dependency_state();
	}

	private static function reset_plugin_dependency_state(): void {
		if ( ! class_exists( 'WP_Plugin_Dependencies' ) ) {
			return;
		}

		foreach ( self::plugin_dependency_static_properties() as $property ) {
			self::set_static_property( 'WP_Plugin_Dependencies', $property, null );
		}
		self::set_static_property( 'WP_Plugin_Dependencies', 'initialized', false );
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'wp_filter',
					'wp_filters',
					'wp_actions',
					'wp_current_filter',
					'wp_plugin_paths',
					'wp_theme_directories',
					'wp_object_cache',
					'pagenow',
				)
			),
			'statics' => self::snapshot_statics(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		foreach ( $snapshot['statics'] as $key => $entry ) {
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
		foreach ( self::plugin_dependency_static_properties() as $property ) {
			$statics[ 'WP_Plugin_Dependencies::' . $property ] = array(
				'class'    => 'WP_Plugin_Dependencies',
				'property' => $property,
				'value'    => self::get_static_property( 'WP_Plugin_Dependencies', $property ),
			);
		}

		foreach ( array( 'persistently_cache', 'cache_expiration' ) as $property ) {
			$statics[ 'WP_Theme::' . $property ] = array(
				'class'    => 'WP_Theme',
				'property' => $property,
				'value'    => self::get_static_property( 'WP_Theme', $property ),
			);
		}

		return $statics;
	}

	private static function plugin_dependency_static_properties(): array {
		return array(
			'plugins',
			'plugin_dirnames',
			'dependencies',
			'dependency_slugs',
			'dependent_slugs',
			'dependency_api_data',
			'dependency_filepaths',
			'circular_dependencies_pairs',
			'circular_dependencies_slugs',
			'initialized',
		);
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

	private static function record_failure( array &$failures, string $check, array $data = array() ): void {
		if ( count( $failures ) >= self::MAX_FAILURES ) {
			return;
		}

		$failures[] = array(
			'check' => $check,
			'data'  => self::compact_data( $data ),
		);
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
			);
		}
	}

	private static function describe_call( array $call ): array {
		return array(
			'threw'     => (bool) ( $call['threw'] ?? false ),
			'value'     => self::describe_value( $call['value'] ?? null ),
			'throwable' => $call['throwable'] ?? null,
		);
	}

	private static function describe_value( $value ) {
		if ( is_array( $value ) ) {
			return array(
				'type'  => 'array',
				'count' => count( $value ),
				'value' => self::compact_data( $value ),
			);
		}
		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}
		if ( is_string( $value ) ) {
			return self::preview( $value );
		}

		return $value;
	}

	private static function describe_wp_error( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return self::describe_value( $value );
		}

		return array(
			'codes'    => $value->get_error_codes(),
			'messages' => $value->get_error_messages(),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function compact_data( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::compact_data( $value );
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = self::preview( $value );
			} elseif ( is_object( $value ) ) {
				$data[ $key ] = self::describe_value( $value );
			}
		}

		return $data;
	}

	private static function preview( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$printable = preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
			static function ( array $m ): string {
				return sprintf( '\\x%02X', ord( $m[0] ) );
			},
			$value
		);

		if ( strlen( $printable ) > $limit ) {
			return substr( $printable, 0, $limit ) . '...';
		}

		return $printable;
	}
}
