<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-live-DB Customizer changeset and Custom CSS persistence paths.
 */
final class CustomizerPersistenceSurface {
	public const NAME = 'customizer-persistence';

	private const CAPABILITY       = 'component_fuzz_customize_persistence';
	private const ACTIVE_STYLESHEET = 'component-fuzz-theme';
	private const OTHER_STYLESHEET  = 'component-fuzz-alt-theme';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_customizer_runtime();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'customizer-persistence.bootstrap-apis-available',
					'Required Customizer persistence APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_changeset_uuid_and_value_normalization( $ctx->fork( 'changeset-normalization' ) );
			$rows[] = self::check_changeset_post_content_parsing( $ctx->fork( 'changeset-parsing' ) );
			$rows[] = self::check_save_changeset_post_transactions( $ctx->fork( 'changeset-save' ) );
			$rows[] = self::check_custom_css_setting_validation_preview_update( $ctx->fork( 'custom-css-setting' ) );
			$rows[] = self::check_custom_css_post_filters_and_round_trips( $ctx->fork( 'custom-css-post' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'customizer-persistence.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = self::check_state_restored( $ctx, $snapshot );

		return $rows;
	}

	public static function grant_runtime_capabilities( array $allcaps ): array {
		$allcaps[ self::CAPABILITY ]   = true;
		$allcaps['customize']          = true;
		$allcaps['edit_css']           = true;
		$allcaps['edit_theme_options'] = true;
		$allcaps['unfiltered_html']    = true;
		return $allcaps;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Customize_Manager',
				'WP_Customize_Setting',
				'WP_Customize_Custom_CSS_Setting',
				'WP_Error',
				'WP_Post',
				'WP_Query',
				'WP_Rewrite',
				'Component_Fuzz_WPDB_Stub',
			) as $class
		) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'create_initial_post_types',
				'current_user_can',
				'get_post',
				'get_post_status',
				'get_post_type',
				'get_stylesheet',
				'get_theme_mod',
				'has_filter',
				'is_wp_error',
				'remove_filter',
				'sanitize_title',
				'set_theme_mod',
				'wp_cache_flush',
				'wp_get_custom_css',
				'wp_get_custom_css_post',
				'wp_insert_post',
				'wp_is_uuid',
				'wp_json_encode',
				'wp_slash',
				'wp_unslash',
				'wp_update_custom_css_post',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_changeset_uuid_and_value_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures       = array();
		$uuid           = self::uuid( $ctx );
		$option_id      = self::id( $ctx->fork( 'option-id' ), 'option' );
		$theme_mod_id   = self::id( $ctx->fork( 'theme-mod-id' ), 'theme_mod' );
		$custom_css_id  = self::custom_css_id( self::ACTIVE_STYLESHEET );
		$option_value   = self::json_value( $ctx->fork( 'option-value' ) );
		$theme_value    = self::json_value( $ctx->fork( 'theme-value' ) );
		$custom_css     = self::safe_css( $ctx->fork( 'custom-css' ) );
		$post_override  = self::json_value( $ctx->fork( 'post-override' ) );
		$programmatic   = self::json_value( $ctx->fork( 'programmatic' ) );
		$unknown_id     = self::id( $ctx->fork( 'unknown-id' ), 'unknown' );
		$theme_namespaced_id = self::ACTIVE_STYLESHEET . '::' . $theme_mod_id;
		$changeset_data = array(
			$option_id           => array(
				'value'             => $option_value,
				'type'              => 'option',
				'user_id'           => $ctx->int( 1, 99 ),
				'date_modified_gmt' => '2026-06-23 00:00:00',
			),
			$theme_namespaced_id => array(
				'value'             => $theme_value,
				'type'              => 'theme_mod',
				'user_id'           => $ctx->int( 100, 199 ),
				'date_modified_gmt' => '2026-06-23 00:01:00',
			),
			$custom_css_id       => array(
				'value'             => $custom_css,
				'type'              => 'custom_css',
				'user_id'           => $ctx->int( 200, 299 ),
				'date_modified_gmt' => '2026-06-23 00:02:00',
			),
			$unknown_id          => array(
				'type' => 'option',
			),
		);

		$post_id = self::insert_changeset_post( $uuid, $changeset_data, 'publish' );
		$manager = self::manager( $ctx, $uuid );
		$manager->add_setting(
			$option_id,
			array(
				'type'       => 'option',
				'capability' => self::CAPABILITY,
				'default'    => 'option-default',
			)
		);
		$manager->add_setting(
			$theme_mod_id,
			array(
				'type'       => 'theme_mod',
				'capability' => self::CAPABILITY,
				'default'    => 'theme-default',
			)
		);
		$css_setting = self::add_custom_css_setting( $manager, self::ACTIVE_STYLESHEET );

		$loaded_data       = $manager->changeset_data();
		$changeset_values  = $manager->unsanitized_post_values(
			array(
				'exclude_changeset' => false,
				'exclude_post_data' => true,
			)
		);
		$customized        = array(
			$option_id => $post_override,
			$unknown_id => 'posted unknown',
		);
		$_POST['customized'] = \wp_slash( \wp_json_encode( $customized ) );
		$merged_values       = $manager->unsanitized_post_values(
			array(
				'exclude_changeset' => false,
				'exclude_post_data' => false,
			)
		);
		$manager->set_post_value( $option_id, $programmatic );
		$after_set_values = $manager->unsanitized_post_values(
			array(
				'exclude_changeset' => false,
				'exclude_post_data' => false,
			)
		);

		self::collect_failure(
			$failures,
			is_int( $post_id )
				&& $post_id > 0
				&& $uuid === $manager->changeset_uuid()
				&& \wp_is_uuid( $manager->changeset_uuid() )
				&& $post_id === $manager->changeset_post_id()
				&& $changeset_data === $loaded_data,
			'manager resolves the supplied UUID and decodes matching changeset post data',
			array(
				'uuid'      => $uuid,
				'postId'    => $post_id,
				'loaded'    => $loaded_data,
				'expected'  => $changeset_data,
				'postFound' => $manager->changeset_post_id(),
			)
		);

		self::collect_failure(
			$failures,
			array_key_exists( $option_id, $changeset_values )
				&& self::same_value( $option_value, $changeset_values[ $option_id ] )
				&& array_key_exists( $theme_mod_id, $changeset_values )
				&& self::same_value( $theme_value, $changeset_values[ $theme_mod_id ] )
				&& array_key_exists( $custom_css_id, $changeset_values )
				&& $custom_css === $changeset_values[ $custom_css_id ]
				&& ! array_key_exists( $unknown_id, $changeset_values ),
			'unsanitized_post_values normalizes namespaced theme mods and ignores changeset entries without value',
			array(
				'changesetValues' => $changeset_values,
				'themeKey'        => $theme_namespaced_id,
			)
		);

		self::collect_failure(
			$failures,
			array_key_exists( $option_id, $merged_values )
				&& self::same_value( $post_override, $merged_values[ $option_id ] )
				&& array_key_exists( $unknown_id, $merged_values )
				&& 'posted unknown' === $merged_values[ $unknown_id ]
				&& array_key_exists( $option_id, $after_set_values )
				&& self::same_value( $programmatic, $after_set_values[ $option_id ] )
				&& $custom_css === $css_setting->post_value( 'fallback-css' ),
			'post data overrides changeset values and set_post_value overrides the cached post value map',
			array(
				'merged'      => $merged_values,
				'afterSet'    => $after_set_values,
				'cssPostValue' => $css_setting->post_value( 'fallback-css' ),
			)
		);

		return self::row(
			$ctx,
			'customizer-persistence.changeset-uuid-data-normalization',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_changeset_post_content_parsing( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures       = array();
		$valid_uuid     = self::uuid( $ctx->fork( 'valid' ) );
		$invalid_uuid   = self::uuid( $ctx->fork( 'invalid-json' ) );
		$scalar_uuid    = self::uuid( $ctx->fork( 'scalar-json' ) );
		$valid_setting  = self::id( $ctx->fork( 'valid-setting' ), 'option' );
		$valid_data     = array(
			$valid_setting => array(
				'value' => self::json_value( $ctx->fork( 'valid-value' ) ),
				'type'  => 'option',
			),
		);
		$valid_post_id  = self::insert_changeset_post( $valid_uuid, $valid_data, 'publish' );
		$invalid_post_id = self::insert_raw_changeset_post( $invalid_uuid, '{"broken":', 'publish' );
		$scalar_post_id = self::insert_raw_changeset_post( $scalar_uuid, \wp_json_encode( 'not an array' ), 'publish' );
		$wrong_post_id  = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => 'Not a changeset',
					'post_content' => \wp_json_encode( $valid_data ),
				)
			),
			true
		);

		$manager        = self::manager( $ctx, $valid_uuid );
		$valid_result   = self::get_changeset_post_data( $manager, $valid_post_id );
		$empty_result   = self::get_changeset_post_data( $manager, 0 );
		$missing_result = self::get_changeset_post_data( $manager, 999999 );
		$invalid_result = self::get_changeset_post_data( $manager, $invalid_post_id );
		$scalar_result  = self::get_changeset_post_data( $manager, $scalar_post_id );
		$wrong_result   = self::get_changeset_post_data( $manager, $wrong_post_id );
		$invalid_manager = self::manager( $ctx->fork( 'invalid-manager' ), $invalid_uuid );
		$scalar_manager = self::manager( $ctx->fork( 'scalar-manager' ), $scalar_uuid );

		self::collect_failure(
			$failures,
			$valid_data === $valid_result
				&& self::wp_error_code_is( $empty_result, 'empty_post_id' )
				&& self::wp_error_code_is( $missing_result, 'missing_post' )
				&& self::wp_error_code_is( $invalid_result, 'json_parse_error' )
				&& self::wp_error_code_is( $scalar_result, 'expected_array' )
				&& self::wp_error_code_is( $wrong_result, 'wrong_post_type' ),
			'get_changeset_post_data returns data only for array JSON in customize_changeset posts',
			array(
				'valid'   => self::describe_value( $valid_result ),
				'empty'   => self::describe_value( $empty_result ),
				'missing' => self::describe_value( $missing_result ),
				'invalid' => self::describe_value( $invalid_result ),
				'scalar'  => self::describe_value( $scalar_result ),
				'wrong'   => self::describe_value( $wrong_result ),
			)
		);

		self::collect_failure(
			$failures,
			array() === $invalid_manager->changeset_data()
				&& array() === $scalar_manager->changeset_data(),
			'changeset_data fails closed to an empty array for malformed or non-array post content',
			array(
				'invalidData' => $invalid_manager->changeset_data(),
				'scalarData'  => $scalar_manager->changeset_data(),
			)
		);

		return self::row(
			$ctx,
			'customizer-persistence.changeset-post-content-parsing',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_save_changeset_post_transactions( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures  = array();
		$uuid      = self::uuid( $ctx->fork( 'save-valid' ) );
		$manager   = self::manager( $ctx, $uuid );
		$option_id = self::id( $ctx->fork( 'saved-option' ), 'option' );
		$css       = self::safe_css( $ctx->fork( 'saved-css' ) );
		$title     = 'Component fuzz changeset ' . $ctx->identifier( 4, 10 );
		$option    = self::non_null_json_value( $ctx->fork( 'saved-option-value' ) );
		$css_id    = self::custom_css_id( self::ACTIVE_STYLESHEET );
		$calls     = array();

		$manager->add_setting(
			$option_id,
			array(
				'type'       => 'option',
				'capability' => self::CAPABILITY,
				'default'    => 'save-default',
			)
		);
		self::add_custom_css_setting( $manager, self::ACTIVE_STYLESHEET );
		$manager->set_post_value( $option_id, $option );
		$manager->set_post_value( $css_id, $css );

		$save_filter = static function ( array $data, array $context ) use ( &$calls, $option_id ) {
			$calls[] = array(
				'uuid'     => $context['uuid'] ?? null,
				'status'   => $context['status'] ?? null,
				'post_id'  => $context['post_id'] ?? null,
				'settingCount' => count( $data ),
			);

			if ( isset( $data[ $option_id ] ) ) {
				$data[ $option_id ]['component_fuzz_marker'] = true;
			}

			return $data;
		};

		\add_filter( 'customize_changeset_save_data', $save_filter, 10, 2 );
		try {
			$response = self::with_capabilities(
				static function () use ( $manager, $title ) {
					return $manager->save_changeset_post(
						array(
							'status' => 'draft',
							'title'  => $title,
							'data'   => array(),
						)
					);
				}
			);
		} finally {
			\remove_filter( 'customize_changeset_save_data', $save_filter, 10 );
		}

		$post_id      = $manager->changeset_post_id();
		$post         = $post_id ? \get_post( $post_id ) : null;
		$decoded      = $post instanceof \WP_Post ? json_decode( $post->post_content, true ) : null;
		$validities   = is_array( $response ) ? ( $response['setting_validities'] ?? array() ) : array();
		$invalid_uuid = self::uuid( $ctx->fork( 'save-invalid' ) );
		$invalid      = self::manager( $ctx->fork( 'invalid-manager' ), $invalid_uuid );
		$invalid_css  = self::invalid_css( $ctx->fork( 'invalid-css' ) );
		$invalid_id   = self::custom_css_id( self::ACTIVE_STYLESHEET );
		self::add_custom_css_setting( $invalid, self::ACTIVE_STYLESHEET );
		$invalid->set_post_value( $invalid_id, $invalid_css );
		$invalid_response = self::with_capabilities(
			static function () use ( $invalid ) {
				return $invalid->save_changeset_post(
					array(
						'status' => 'draft',
						'title'  => 'Invalid custom CSS',
						'data'   => array(),
					)
				);
			}
		);
		$error_data       = \is_wp_error( $invalid_response ) ? $invalid_response->get_error_data( 'transaction_fail' ) : null;
		$invalid_validity = is_array( $error_data ) && isset( $error_data['setting_validities'][ $invalid_id ] )
			? $error_data['setting_validities'][ $invalid_id ]
			: null;

		self::collect_failure(
			$failures,
			is_array( $response )
				&& true === ( $validities[ $option_id ] ?? null )
				&& true === ( $validities[ $css_id ] ?? null )
				&& $post instanceof \WP_Post
				&& 'customize_changeset' === $post->post_type
				&& 'draft' === $post->post_status
				&& $uuid === $post->post_name
				&& $title === $post->post_title
				&& is_array( $decoded )
				&& isset( $decoded[ $option_id ], $decoded[ $css_id ] )
				&& self::same_value( $option, $decoded[ $option_id ]['value'] ?? null )
				&& $css === ( $decoded[ $css_id ]['value'] ?? null )
				&& 'option' === ( $decoded[ $option_id ]['type'] ?? null )
				&& 'custom_css' === ( $decoded[ $css_id ]['type'] ?? null )
				&& true === ( $decoded[ $option_id ]['component_fuzz_marker'] ?? null )
				&& 1 === count( $calls )
				&& false === \has_filter( 'customize_changeset_save_data', $save_filter ),
			'save_changeset_post persists normalized setting data through a scoped save-data filter',
			array(
				'response' => self::describe_value( $response ),
				'post'     => self::describe_post( $post ),
				'decoded'  => self::describe_value( $decoded ),
				'calls'    => $calls,
				'filter'   => \has_filter( 'customize_changeset_save_data', $save_filter ),
			)
		);

		self::collect_failure(
			$failures,
			\is_wp_error( $invalid_response )
				&& 'transaction_fail' === $invalid_response->get_error_code()
				&& null === $invalid->changeset_post_id()
				&& self::wp_error_code_is( $invalid_validity, 'illegal_markup' ),
			'invalid Custom CSS causes transactional changeset saves to fail without creating a changeset post',
			array(
				'response'       => self::describe_value( $invalid_response ),
				'postId'         => $invalid->changeset_post_id(),
				'invalidCss'     => self::preview( $invalid_css ),
				'invalidityCode' => $invalid_validity instanceof \WP_Error ? $invalid_validity->get_error_code() : null,
			)
		);

		return self::row(
			$ctx,
			'customizer-persistence.save-changeset-post-transactions',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_custom_css_setting_validation_preview_update( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures     = array();
		$manager      = self::manager( $ctx, self::uuid( $ctx ) );
		$stylesheet   = 'cfz-preview-' . self::slug_part( $ctx->identifier( 4, 10 ) );
		$valid_css    = ' ' . self::safe_css( $ctx->fork( 'valid-css' ) ) . ' ';
		$sanitized    = trim( $valid_css );
		$invalid_css  = self::invalid_css( $ctx->fork( 'invalid-css' ) );
		$setting      = self::add_custom_css_setting( $manager, $stylesheet );
		$sanitize_calls = array();

		$sanitize_filter = static function ( $value, \WP_Customize_Setting $filtered_setting ) use ( &$sanitize_calls, $setting ) {
			$sanitize_calls[] = array(
				'setting' => $filtered_setting->id,
				'matches' => $filtered_setting === $setting,
				'value'   => self::preview( (string) $value ),
			);

			return trim( (string) $value );
		};

		\add_filter( "customize_sanitize_{$setting->id}", $sanitize_filter, 10, 2 );
		try {
			$validity      = $setting->validate( $sanitized );
			$invalidity    = $setting->validate( $invalid_css );
			$manager->set_post_value( $setting->id, $valid_css );
			$post_value    = $setting->post_value( 'fallback-css' );
			$previewed     = $setting->preview();
			$preview_css   = \wp_get_custom_css( $stylesheet );
			$other_css     = \wp_get_custom_css( self::OTHER_STYLESHEET );
			$preview_again = $setting->preview();
			$manager_validities = $manager->validate_setting_values(
				array( $setting->id => $invalid_css ),
				array(
					'validate_existence'  => true,
					'validate_capability' => false,
				)
			);
		} finally {
			\remove_filter( "customize_sanitize_{$setting->id}", $sanitize_filter, 10 );
			\remove_filter( 'wp_get_custom_css', array( $setting, 'filter_previewed_wp_get_custom_css' ), 9 );
		}

		$active_setting = self::add_custom_css_setting( $manager, self::ACTIVE_STYLESHEET );
		$updated_css    = self::safe_css( $ctx->fork( 'updated-css' ) );
		$post_id        = $active_setting->update( $sanitized );
		$post           = is_int( $post_id ) ? \get_post( $post_id ) : null;
		$stored_css     = \wp_get_custom_css( self::ACTIVE_STYLESHEET );
		$post_id_again  = $active_setting->update( $updated_css );
		$post_again     = is_int( $post_id_again ) ? \get_post( $post_id_again ) : null;
		$stored_again   = \wp_get_custom_css( self::ACTIVE_STYLESHEET );

		self::collect_failure(
			$failures,
			true === $validity
				&& self::wp_error_code_is( $invalidity, 'illegal_markup' )
				&& $sanitized === $post_value
				&& true === $previewed
				&& false === $preview_again
				&& $sanitized === $preview_css
				&& '' === $other_css
				&& self::wp_error_code_is( $manager_validities[ $setting->id ] ?? null, 'illegal_markup' )
				&& 2 === count( $sanitize_calls )
				&& self::all_sanitize_calls_match( $sanitize_calls )
				&& false === \has_filter( "customize_sanitize_{$setting->id}", $sanitize_filter )
				&& false === \has_filter( 'wp_get_custom_css', array( $setting, 'filter_previewed_wp_get_custom_css' ) ),
			'Custom CSS setting validates illegal STYLE breakouts, sanitizes post values, and previews only its stylesheet',
			array(
				'validity'        => self::describe_value( $validity ),
				'invalidity'      => self::describe_value( $invalidity ),
				'postValue'       => self::preview( $post_value ),
				'previewCss'      => self::preview( $preview_css ),
				'otherCss'        => self::preview( $other_css ),
				'managerValidity' => self::describe_value( $manager_validities[ $setting->id ] ?? null ),
				'sanitizeCalls'   => $sanitize_calls,
			)
		);

		self::collect_failure(
			$failures,
			is_int( $post_id )
				&& $post instanceof \WP_Post
				&& $post_id === $post_id_again
				&& $post_again instanceof \WP_Post
				&& 'custom_css' === $post_again->post_type
				&& self::ACTIVE_STYLESHEET === $post_again->post_title
				&& \sanitize_title( self::ACTIVE_STYLESHEET ) === $post_again->post_name
				&& $updated_css === $post_again->post_content
				&& $sanitized === $stored_css
				&& $updated_css === $stored_again
				&& $post_id === \get_theme_mod( 'custom_css_post_id' ),
			'Custom CSS setting update inserts then updates the active stylesheet post and theme-mod cache',
			array(
				'firstPostId'    => $post_id,
				'secondPostId'   => $post_id_again,
				'post'           => self::describe_post( $post_again ),
				'storedCss'      => self::preview( $stored_css ),
				'storedAgain'    => self::preview( $stored_again ),
				'themeModPostId' => \get_theme_mod( 'custom_css_post_id' ),
			)
		);

		return self::row(
			$ctx,
			'customizer-persistence.custom-css-setting-validation-preview-update',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_custom_css_post_filters_and_round_trips( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures      = array();
		$stylesheet    = 'cfz-css-' . self::slug_part( $ctx->identifier( 4, 10 ) );
		$raw_css       = self::safe_css( $ctx->fork( 'raw-css' ) );
		$raw_css_2     = self::safe_css( $ctx->fork( 'raw-css-2' ) );
		$preprocessed  = 'pre:' . self::safe_css( $ctx->fork( 'preprocessed' ) );
		$preprocessed_2 = 'pre2:' . self::safe_css( $ctx->fork( 'preprocessed-2' ) );
		$stored_css    = "/* filtered {$stylesheet} */\n" . $raw_css;
		$stored_css_2  = "/* filtered second {$stylesheet} */\n" . $raw_css_2;
		$stored_pre    = "source:\n" . $preprocessed;
		$stored_pre_2  = "source2:\n" . $preprocessed_2;
		$get_marker    = "\n/* get-filter */";
		$update_calls  = array();
		$get_calls     = array();

		$update_filter = static function ( array $data, array $args ) use ( &$update_calls, $stylesheet, $stored_css, $stored_css_2, $stored_pre, $stored_pre_2 ) {
			$update_calls[] = array(
				'stylesheet'   => $args['stylesheet'] ?? null,
				'css'          => self::preview( $args['css'] ?? '' ),
				'preprocessed' => self::preview( $args['preprocessed'] ?? '' ),
			);

			if ( $stylesheet !== ( $args['stylesheet'] ?? null ) ) {
				return $data;
			}

			if ( 1 === count( $update_calls ) ) {
				$data['css']          = $stored_css;
				$data['preprocessed'] = $stored_pre;
			} else {
				$data['css']          = $stored_css_2;
				$data['preprocessed'] = $stored_pre_2;
			}

			return $data;
		};
		$get_filter = static function ( string $css, string $current_stylesheet ) use ( &$get_calls, $stylesheet, $get_marker ): string {
			$get_calls[] = array(
				'stylesheet' => $current_stylesheet,
				'css'        => self::preview( $css ),
			);

			if ( $stylesheet !== $current_stylesheet ) {
				return $css;
			}

			return $css . $get_marker;
		};

		\add_filter( 'update_custom_css_data', $update_filter, 10, 2 );
		\add_filter( 'wp_get_custom_css', $get_filter, 10, 2 );
		try {
			$first          = \wp_update_custom_css_post(
				$raw_css,
				array(
					'stylesheet'   => $stylesheet,
					'preprocessed' => $preprocessed,
				)
			);
			$filtered_get   = \wp_get_custom_css( $stylesheet );
			$other_get      = \wp_get_custom_css( self::OTHER_STYLESHEET );
			$second         = \wp_update_custom_css_post(
				$raw_css_2,
				array(
					'stylesheet'   => $stylesheet,
					'preprocessed' => $preprocessed_2,
				)
			);
		} finally {
			\remove_filter( 'update_custom_css_data', $update_filter, 10 );
			\remove_filter( 'wp_get_custom_css', $get_filter, 10 );
		}

		$first_post    = $first instanceof \WP_Post ? $first : null;
		$second_post   = $second instanceof \WP_Post ? $second : null;
		$looked_up     = \wp_get_custom_css_post( $stylesheet );
		$unfiltered_get = \wp_get_custom_css( $stylesheet );

		self::collect_failure(
			$failures,
			$first_post instanceof \WP_Post
				&& $second_post instanceof \WP_Post
				&& $first_post->ID === $second_post->ID
				&& $looked_up instanceof \WP_Post
				&& $looked_up->ID === $second_post->ID
				&& 'custom_css' === $second_post->post_type
				&& 'publish' === $second_post->post_status
				&& $stylesheet === $second_post->post_title
				&& \sanitize_title( $stylesheet ) === $second_post->post_name
				&& $stored_css_2 === $second_post->post_content
				&& $stored_pre_2 === $second_post->post_content_filtered
				&& $stored_css . $get_marker === $filtered_get
				&& '' === $other_get
				&& $stored_css_2 === $unfiltered_get
				&& 2 === count( $update_calls )
				&& 2 === count( $get_calls )
				&& false === \has_filter( 'update_custom_css_data', $update_filter )
				&& false === \has_filter( 'wp_get_custom_css', $get_filter ),
			'wp_update_custom_css_post and wp_get_custom_css round-trip through scoped filters without leaking to other stylesheets',
			array(
				'first'         => self::describe_post( $first_post ),
				'second'        => self::describe_post( $second_post ),
				'lookedUp'      => self::describe_post( $looked_up ),
				'filteredGet'   => self::preview( $filtered_get ),
				'otherGet'      => self::preview( $other_get ),
				'unfilteredGet' => self::preview( $unfiltered_get ),
				'updateCalls'   => $update_calls,
				'getCalls'      => $get_calls,
			)
		);

		return self::row(
			$ctx,
			'customizer-persistence.custom-css-post-filter-round-trips',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function load_customizer_runtime(): void {
		if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		$file = ABSPATH . WPINC . '/customize/class-wp-customize-custom-css-setting.php';
		if ( ! class_exists( 'WP_Customize_Custom_CSS_Setting', false ) && file_exists( $file ) ) {
			require_once $file;
		}
	}

	private static function manager( \ComponentFuzz\FuzzContext $ctx, ?string $uuid = null ): \WP_Customize_Manager {
		$components_filter = static function (): array {
			return array();
		};
		\add_filter( 'customize_loaded_components', $components_filter, 1000 );
		try {
			$manager = new \WP_Customize_Manager(
				array(
					'changeset_uuid'     => $uuid ?? self::uuid( $ctx ),
					'settings_previewed' => false,
					'branching'          => true,
					'autosaved'          => false,
				)
			);
		} finally {
			\remove_filter( 'customize_loaded_components', $components_filter, 1000 );
		}

		$GLOBALS['wp_customize'] = $manager;
		if ( false === \has_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ) ) ) {
			\add_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ), 10, 4 );
		}
		return $manager;
	}

	private static function add_custom_css_setting( \WP_Customize_Manager $manager, string $stylesheet ): \WP_Customize_Custom_CSS_Setting {
		$setting = new \WP_Customize_Custom_CSS_Setting( $manager, self::custom_css_id( $stylesheet ) );
		$manager->add_setting( $setting );
		return $setting;
	}

	private static function with_capabilities( callable $callback ) {
		if ( false === \has_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ) ) ) {
			\add_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ), 10, 4 );
			$remove = true;
		} else {
			$remove = false;
		}

		try {
			return $callback();
		} finally {
			if ( $remove ) {
				\remove_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ), 10 );
			}
		}
	}

	private static function insert_changeset_post( string $uuid, array $data, string $status ) {
		return self::insert_raw_changeset_post( $uuid, \wp_json_encode( $data ), $status );
	}

	private static function insert_raw_changeset_post( string $uuid, string $content, string $status ) {
		return \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => 'customize_changeset',
					'post_name'    => $uuid,
					'post_status'  => $status,
					'post_title'   => 'Component fuzz changeset',
					'post_content' => $content,
				)
			),
			true
		);
	}

	private static function get_changeset_post_data( \WP_Customize_Manager $manager, $post_id ) {
		$method = new \ReflectionMethod( $manager, 'get_changeset_post_data' );
		if ( PHP_VERSION_ID < 80100 && method_exists( $method, 'setAccessible' ) ) {
			$method->setAccessible( true );
		}

		return $method->invoke( $manager, $post_id );
	}

	private static function reset_runtime(): void {
		foreach (
			array(
				'customized',
				'customize_changeset_data',
				'customize_changeset_uuid',
				'customize_theme',
				'theme',
			) as $key
		) {
			unset( $_GET[ $key ], $_POST[ $key ], $_REQUEST[ $key ] );
		}

		if ( function_exists( 'create_initial_post_types' ) ) {
			\create_initial_post_types();
		}

		if ( class_exists( 'WP_Rewrite', false ) ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'home'                            => 'http://example.test',
					'siteurl'                         => 'http://example.test',
					'stylesheet'                      => self::ACTIVE_STYLESHEET,
					'template'                        => self::ACTIVE_STYLESHEET,
					'current_theme'                   => 'Component Fuzz Theme',
					'theme_mods_' . self::ACTIVE_STYLESHEET => array(),
					'customize_stashed_theme_mods'    => array(),
					'page_for_posts'                  => '0',
					'page_on_front'                   => '0',
					'show_on_front'                   => 'posts',
					'fresh_site'                      => '0',
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		if ( method_exists( 'WP_Customize_Setting', 'reset_aggregated_multidimensionals' ) ) {
			\WP_Customize_Setting::reset_aggregated_multidimensionals();
		}
	}

	private static function snapshot_state(): array {
		return array(
			'globals'            => self::snapshot_globals(
				array(
					'_GET',
					'_POST',
					'_REQUEST',
					'current_user',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_customize',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_post_types',
					'wp_query',
					'wp_rewrite',
					'wp_the_query',
				)
			),
			'options'            => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
			'contentCounts'      => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: null,
			'aggregatedSettings' => self::get_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals' ),
			'controlCount'       => self::get_static_property( 'WP_Customize_Control', 'instance_count' ),
			'sectionCount'       => self::get_static_property( 'WP_Customize_Section', 'instance_count' ),
			'panelCount'         => self::get_static_property( 'WP_Customize_Panel', 'instance_count' ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			if ( null !== $snapshot['options'] ) {
				$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
			}
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		self::set_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals', $snapshot['aggregatedSettings'] );
		self::set_static_property( 'WP_Customize_Control', 'instance_count', $snapshot['controlCount'] );
		self::set_static_property( 'WP_Customize_Section', 'instance_count', $snapshot['sectionCount'] );
		self::set_static_property( 'WP_Customize_Panel', 'instance_count', $snapshot['panelCount'] );
	}

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$options = isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
			? $GLOBALS['wpdb']->component_fuzz_get_options()
			: null;
		$content_counts = isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
			? $GLOBALS['wpdb']->component_fuzz_content_counts()
			: null;

		$ok = self::global_value_matches( '_GET', $snapshot['globals']['_GET'] )
			&& self::global_value_matches( '_POST', $snapshot['globals']['_POST'] )
			&& self::global_value_matches( '_REQUEST', $snapshot['globals']['_REQUEST'] )
			&& $options === $snapshot['options']
			&& $content_counts === $snapshot['contentCounts']
			&& self::get_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals' ) === $snapshot['aggregatedSettings']
			&& self::get_static_property( 'WP_Customize_Control', 'instance_count' ) === $snapshot['controlCount']
			&& self::get_static_property( 'WP_Customize_Section', 'instance_count' ) === $snapshot['sectionCount']
			&& self::get_static_property( 'WP_Customize_Panel', 'instance_count' ) === $snapshot['panelCount'];

		return $ctx->result(
			'customizer-persistence.state-restored',
			$ok,
			array(
				'superglobals'  => array( '_GET', '_POST', '_REQUEST' ),
				'optionsEqual'  => $options === $snapshot['options'],
				'contentCounts' => $content_counts,
			)
		);
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

	private static function global_value_matches( string $name, array $snapshot_entry ): bool {
		if ( ! $snapshot_entry['exists'] ) {
			return ! array_key_exists( $name, $GLOBALS );
		}

		return array_key_exists( $name, $GLOBALS ) && $GLOBALS[ $name ] === $snapshot_entry['value'];
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

	private static function get_static_property( string $class_name, string $property ) {
		$reflection = new \ReflectionProperty( $class_name, $property );
		self::make_reflection_accessible( $reflection );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class_name, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $class_name, $property );
		self::make_reflection_accessible( $reflection );
		$reflection->setValue( null, $value );
	}

	private static function make_reflection_accessible( \ReflectionProperty $reflection ): void {
		if ( PHP_VERSION_ID < 80100 && method_exists( $reflection, 'setAccessible' ) ) {
			$reflection->setAccessible( true );
		}
	}

	private static function id( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return 'cfz_' . $prefix . '_' . $ctx->iteration() . '_' . substr( md5( (string) $ctx->seed() . ':' . $prefix ), 0, 8 ) . '_' . $ctx->identifier( 3, 10 );
	}

	private static function custom_css_id( string $stylesheet ): string {
		return 'custom_css[' . $stylesheet . ']';
	}

	private static function uuid( \ComponentFuzz\FuzzContext $ctx ): string {
		$hex = md5( 'customizer-persistence:' . $ctx->seed() . ':' . $ctx->iteration() );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			'4' . substr( $hex, 13, 3 ),
			'8' . substr( $hex, 17, 3 ),
			substr( $hex, 20, 12 )
		);
	}

	private static function json_value( \ComponentFuzz\FuzzContext $ctx ) {
		$value = $ctx->jsonValue();
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return 'non-finite-fallback';
		}

		$encoded = \wp_json_encode( $value );
		if ( ! is_string( $encoded ) ) {
			return 'json-encoding-fallback';
		}

		return json_decode( $encoded, true );
	}

	private static function non_null_json_value( \ComponentFuzz\FuzzContext $ctx ) {
		$value = self::json_value( $ctx );
		if ( null === $value ) {
			return 'component-fuzz-non-null-' . $ctx->identifier( 4, 10 );
		}

		return $value;
	}

	private static function safe_css( \ComponentFuzz\FuzzContext $ctx ): string {
		$selector = '.cfz-' . self::slug_part( $ctx->identifier( 4, 12 ) );
		$color    = sprintf( '#%06x', $ctx->int( 0, 0xffffff ) );
		$margin   = $ctx->int( 0, 48 );
		$width    = $ctx->int( 10, 90 );
		$comment  = preg_replace( '/[^A-Za-z0-9 _.-]/', '_', $ctx->text( 0, 32 ) );

		return $selector . " {\n"
			. "\tcolor: " . $color . ";\n"
			. "\tmargin-inline-start: " . $margin . "px;\n"
			. "}\n"
			. '@media (min-width: ' . ( 320 + $ctx->int( 0, 640 ) ) . "px) {\n"
			. "\t" . $selector . ' { max-width: ' . $width . "%; }\n"
			. "}\n"
			. '/* ' . $comment . ' */';
	}

	private static function invalid_css( \ComponentFuzz\FuzzContext $ctx ): string {
		return self::safe_css( $ctx->fork( 'base' ) ) . "\n" . $ctx->choice(
			array(
				'</style>',
				'</STYLE ',
				'</style',
				'</sty',
			)
		);
	}

	private static function slug_part( string $value ): string {
		$slug = strtolower( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $value ) );
		$slug = trim( $slug, '-' );
		return '' === $slug ? 'x' : $slug;
	}

	private static function all_sanitize_calls_match( array $calls ): bool {
		foreach ( $calls as $call ) {
			if ( empty( $call['matches'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function same_value( $expected, $actual ): bool {
		return $expected === $actual;
	}

	private static function wp_error_code_is( $value, string $code ): bool {
		return $value instanceof \WP_Error && $code === $value->get_error_code();
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function describe_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return self::describe_value( $post );
		}

		return array(
			'ID'                    => $post->ID,
			'post_type'             => $post->post_type,
			'post_status'           => $post->post_status,
			'post_name'             => $post->post_name,
			'post_title'            => $post->post_title,
			'post_content'          => self::preview( $post->post_content ),
			'post_content_filtered' => self::preview( $post->post_content_filtered ),
		);
	}

	private static function describe_throwable( \Throwable $throwable ): array {
		return array(
			'class'   => get_class( $throwable ),
			'message' => $throwable->getMessage(),
			'file'    => $throwable->getFile(),
			'line'    => $throwable->getLine(),
		);
	}

	private static function describe_value( $value ) {
		if ( $value instanceof \WP_Error ) {
			return array(
				'wpErrorCodes' => $value->get_error_codes(),
				'wpErrorData'  => $value->get_all_error_data(),
			);
		}

		if ( $value instanceof \WP_Post ) {
			return self::describe_post( $value );
		}

		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return self::preview( $value );
		}

		return $value;
	}

	private static function preview( string $value ) {
		return \ComponentFuzz\preview_value( $value );
	}
}
