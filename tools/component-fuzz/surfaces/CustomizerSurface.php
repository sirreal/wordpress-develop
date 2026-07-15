<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB Customizer API behavior.
 */
final class CustomizerSurface {
	public const NAME = 'customizer';

	private const CAPABILITY = 'component_fuzz_customize';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'customizer.bootstrap-apis-available',
					'Required Customizer APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_registry_lifecycle_and_ordering( $ctx->fork( 'registry' ) );
			$rows[] = self::check_setting_callbacks_and_post_values( $ctx->fork( 'settings' ) );
			$rows[] = self::check_manager_post_value_merging( $ctx->fork( 'post-values' ) );
			$rows[] = self::check_multidimensional_values( $ctx->fork( 'multidimensional' ) );
			$rows[] = self::check_json_and_active_callbacks( $ctx->fork( 'json-active' ) );
			$rows[] = self::check_control_rendering_contracts( $ctx->fork( 'control-rendering' ) );
			$rows[] = self::check_selective_refresh_partials( $ctx->fork( 'partials' ) );
			$rows[] = self::check_theme_preview_lifecycle( $ctx->fork( 'theme-preview' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'customizer.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	public static function grant_runtime_capabilities( array $allcaps ): array {
		$allcaps[ self::CAPABILITY ]   = true;
		$allcaps['customize']          = true;
		$allcaps['edit_theme_options'] = true;
		return $allcaps;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Customize_Manager',
				'WP_Customize_Setting',
				'WP_Customize_Control',
				'WP_Customize_Section',
				'WP_Customize_Panel',
				'WP_Customize_Selective_Refresh',
				'WP_Customize_Partial',
				'WP_Error',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'apply_filters',
				'checked',
				'current_user_can',
				'esc_attr',
				'esc_html',
				'esc_textarea',
				'get_option',
				'get_raw_theme_root',
				'get_stylesheet',
				'get_template',
				'has_action',
				'has_filter',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'selected',
				'update_option',
				'wp_json_encode',
				'wp_slash',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_registry_lifecycle_and_ordering( \ComponentFuzz\FuzzContext $ctx ): array {
		$manager  = self::manager( $ctx );
		$failures = array();

		$setting_id = self::id( $ctx, 'setting' );
		$setting    = $manager->add_setting(
			$setting_id,
			array(
				'type'       => 'component_fuzz_no_db',
				'capability' => self::CAPABILITY,
				'default'    => self::fuzz_value( $ctx->fork( 'default' ) ),
				'transport'  => $ctx->choice( array( 'refresh', 'postMessage' ) ),
				'dirty'      => true,
			)
		);

		$temp_setting_id = self::id( $ctx->fork( 'temp-setting' ), 'setting' );
		$temp_setting    = $manager->add_setting(
			$temp_setting_id,
			array(
				'type'       => 'component_fuzz_no_db',
				'capability' => self::CAPABILITY,
				'default'    => 'temporary',
			)
		);
		$manager->remove_setting( $temp_setting_id );

		self::collect_failure(
			$failures,
			$setting instanceof \WP_Customize_Setting
				&& $setting === $manager->get_setting( $setting_id )
				&& $setting_id === $setting->id
				&& true === $setting->json()['dirty']
				&& null === $manager->get_setting( $temp_setting_id )
				&& $temp_setting instanceof \WP_Customize_Setting,
			'add_setting/get_setting/remove_setting keep the registry consistent',
			array(
				'settingId'     => $setting_id,
				'tempSettingId' => $temp_setting_id,
				'json'          => $setting->json(),
			)
		);

		$panel_a = self::id( $ctx->fork( 'panel-a' ), 'panel' );
		$panel_b = self::id( $ctx->fork( 'panel-b' ), 'panel' );
		$panel_c = self::id( $ctx->fork( 'panel-c' ), 'panel' );
		$manager->add_panel(
			$panel_a,
			array(
				'priority'   => 5,
				'title'      => 'Panel A',
				'capability' => self::CAPABILITY,
			)
		);
		$manager->add_panel(
			$panel_b,
			array(
				'priority'   => 20,
				'title'      => 'Panel B',
				'capability' => self::CAPABILITY,
			)
		);
		$manager->add_panel(
			$panel_c,
			array(
				'priority'   => 5,
				'title'      => 'Panel C',
				'capability' => self::CAPABILITY,
			)
		);

		$temp_panel = self::id( $ctx->fork( 'temp-panel' ), 'panel' );
		$manager->add_panel( $temp_panel, array( 'capability' => self::CAPABILITY ) );
		$manager->remove_panel( $temp_panel );

		self::collect_failure(
			$failures,
			$manager->get_panel( $panel_a ) instanceof \WP_Customize_Panel
				&& $manager->get_panel( $panel_b ) instanceof \WP_Customize_Panel
				&& $manager->get_panel( $panel_c ) instanceof \WP_Customize_Panel
				&& null === $manager->get_panel( $temp_panel ),
			'add_panel/get_panel/remove_panel keep the registry consistent',
			array(
				'panels'    => array( $panel_a, $panel_b, $panel_c ),
				'tempPanel' => $temp_panel,
			)
		);

		$section_main = self::id( $ctx->fork( 'section-main' ), 'section' );
		$section_a    = self::id( $ctx->fork( 'section-a' ), 'section' );
		$section_b    = self::id( $ctx->fork( 'section-b' ), 'section' );
		$manager->add_section(
			$section_main,
			array(
				'priority'   => 15,
				'title'      => 'Main ' . self::unsafe_string( $ctx->fork( 'section-title' ) ),
				'capability' => self::CAPABILITY,
			)
		);
		$manager->add_section(
			$section_a,
			array(
				'priority'   => 5,
				'title'      => 'Section A',
				'capability' => self::CAPABILITY,
			)
		);
		$manager->add_section(
			$section_b,
			array(
				'priority'   => 5,
				'title'      => 'Section B',
				'capability' => self::CAPABILITY,
			)
		);

		$temp_section = self::id( $ctx->fork( 'temp-section' ), 'section' );
		$manager->add_section( $temp_section, array( 'capability' => self::CAPABILITY ) );
		$manager->remove_section( $temp_section );

		self::collect_failure(
			$failures,
			$manager->get_section( $section_main ) instanceof \WP_Customize_Section
				&& $manager->get_section( $section_a ) instanceof \WP_Customize_Section
				&& $manager->get_section( $section_b ) instanceof \WP_Customize_Section
				&& null === $manager->get_section( $temp_section ),
			'add_section/get_section/remove_section keep the registry consistent',
			array(
				'sections'    => array( $section_a, $section_b, $section_main ),
				'tempSection' => $temp_section,
			)
		);

		$control_a = self::id( $ctx->fork( 'control-a' ), 'control' );
		$control_b = self::id( $ctx->fork( 'control-b' ), 'control' );
		$control_c = self::id( $ctx->fork( 'control-c' ), 'control' );
		$manager->add_control(
			$control_a,
			array(
				'settings'   => $setting_id,
				'section'    => $section_main,
				'priority'   => 30,
				'label'      => 'Control A',
				'capability' => self::CAPABILITY,
			)
		);
		$manager->add_control(
			$control_b,
			array(
				'settings'   => $setting_id,
				'section'    => $section_main,
				'priority'   => 10,
				'label'      => 'Control B',
				'capability' => self::CAPABILITY,
			)
		);
		$manager->add_control(
			$control_c,
			array(
				'settings'   => $setting_id,
				'section'    => $section_main,
				'priority'   => 10,
				'label'      => 'Control C',
				'capability' => self::CAPABILITY,
			)
		);

		$temp_control = self::id( $ctx->fork( 'temp-control' ), 'control' );
		$manager->add_control(
			$temp_control,
			array(
				'settings'   => $setting_id,
				'section'    => $section_main,
				'capability' => self::CAPABILITY,
			)
		);
		$manager->remove_control( $temp_control );

		self::collect_failure(
			$failures,
			$manager->get_control( $control_a ) instanceof \WP_Customize_Control
				&& $manager->get_control( $control_b ) instanceof \WP_Customize_Control
				&& $manager->get_control( $control_c ) instanceof \WP_Customize_Control
				&& null === $manager->get_control( $temp_control ),
			'add_control/get_control/remove_control keep the registry consistent',
			array(
				'controls'    => array( $control_a, $control_b, $control_c ),
				'tempControl' => $temp_control,
			)
		);

		self::with_capabilities(
			static function () use ( $manager ): void {
				$manager->prepare_controls();
			}
		);

		$panels          = array_keys( $manager->panels() );
		$sections        = array_keys( $manager->sections() );
		$section_control = $manager->get_section( $section_main );
		$control_order   = $section_control instanceof \WP_Customize_Section
			? array_map(
				static function ( \WP_Customize_Control $control ): string {
					return $control->id;
				},
				$section_control->controls
			)
			: array();

		self::collect_failure(
			$failures,
			array( $panel_a, $panel_c, $panel_b ) === array_values(
				array_intersect( $panels, array( $panel_a, $panel_b, $panel_c ) )
			)
				&& array( $section_a, $section_b, $section_main ) === array_values(
					array_intersect( $sections, array( $section_a, $section_b, $section_main ) )
				)
				&& array( $control_b, $control_c, $control_a ) === $control_order,
			'prepare_controls sorts panels, sections, and controls by priority with stable instance order',
			array(
				'panelOrder'   => $panels,
				'sectionOrder' => $sections,
				'controlOrder' => $control_order,
			)
		);

		return self::row(
			$ctx,
			'customizer.registry.lifecycle-priority',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_setting_callbacks_and_post_values( \ComponentFuzz\FuzzContext $ctx ): array {
		$manager  = self::manager( $ctx );
		$failures = array();
		$id       = self::id( $ctx, 'option' );
		$raw      = self::non_null_value( $ctx->fork( 'raw' ) );
		$expected = array(
			'kind'  => gettype( $raw ),
			'value' => self::stringify_value( $raw ),
			'id'    => $id,
		);
		$calls    = array(
			'sanitize' => array(),
			'validate' => array(),
			'js'       => array(),
		);

		$sanitize_callback = static function ( $value, \WP_Customize_Setting $setting ) use ( &$calls, $raw, $expected, $id ) {
			$calls['sanitize'][] = array(
				'value'       => self::describe_value( $value ),
				'settingId'   => $setting->id,
				'sameSetting' => $id === $setting->id,
			);

			if ( $value === $raw ) {
				return $expected;
			}

			return $value;
		};
		$validate_callback = static function ( \WP_Error $validity, $value, \WP_Customize_Setting $setting ) use ( &$calls, $id ) {
			$calls['validate'][] = array(
				'value'       => self::describe_value( $value ),
				'settingId'   => $setting->id,
				'sameSetting' => $id === $setting->id,
			);
			return $validity;
		};
		$sanitize_js_callback = static function ( $value, \WP_Customize_Setting $setting ) use ( &$calls, $id ) {
			$calls['js'][] = array(
				'value'       => self::describe_value( $value ),
				'settingId'   => $setting->id,
				'sameSetting' => $id === $setting->id,
			);
			return array(
				'js'      => true,
				'setting' => $setting->id,
				'value'   => $value,
			);
		};

		$setting = $manager->add_setting(
			$id,
			array(
				'type'                 => 'option',
				'capability'           => self::CAPABILITY,
				'default'              => 'fallback',
				'transport'            => 'postMessage',
				'dirty'                => true,
				'sanitize_callback'    => $sanitize_callback,
				'validate_callback'    => $validate_callback,
				'sanitize_js_callback' => $sanitize_js_callback,
			)
		);

		$invalid_id    = self::id( $ctx->fork( 'invalid' ), 'option' );
		$invalid_calls = array(
			'validate' => 0,
			'sanitize' => 0,
		);
		$manager->add_setting(
			$invalid_id,
			array(
				'type'              => 'option',
				'capability'        => self::CAPABILITY,
				'sanitize_callback' => static function ( $value ) use ( &$invalid_calls ) {
					++$invalid_calls['sanitize'];
					return $value;
				},
				'validate_callback' => static function ( \WP_Error $validity ) use ( &$invalid_calls ) {
					++$invalid_calls['validate'];
					$validity->add( 'component_fuzz_invalid', 'Component fuzz invalid value.' );
					return $validity;
				},
			)
		);

		$null_id    = self::id( $ctx->fork( 'null' ), 'option' );
		$null_calls = array(
			'validate' => 0,
			'sanitize' => 0,
		);
		$manager->add_setting(
			$null_id,
			array(
				'type'              => 'option',
				'capability'        => self::CAPABILITY,
				'sanitize_callback' => static function ( $value ) use ( &$null_calls ) {
					++$null_calls['sanitize'];
					return $value;
				},
				'validate_callback' => static function ( \WP_Error $validity ) use ( &$null_calls ) {
					++$null_calls['validate'];
					return $validity;
				},
			)
		);

		$manager->set_post_value( $id, $raw );

		$post_value          = $setting->post_value( 'fallback-post' );
		$previewed           = $setting->preview();
		$value_after_preview = $setting->value();
		$js_value            = $setting->js_value();
		$unknown_id          = self::id( $ctx->fork( 'unknown' ), 'option' );
		$validities          = $manager->validate_setting_values(
			array(
				$id         => $raw,
				$invalid_id => 'invalid',
				$null_id    => null,
				$unknown_id => 'unknown',
			),
			array( 'validate_existence' => true )
		);

		self::collect_failure(
			$failures,
			$expected === $post_value
				&& true === $previewed
				&& $expected === $value_after_preview
				&& array(
					'js'      => true,
					'setting' => $id,
					'value'   => $expected,
				) === $js_value
				&& true === ( $validities[ $id ] ?? null ),
			'post values are sanitized, previewed, exported to JS, and validated deterministically',
			array(
				'raw'               => self::describe_value( $raw ),
				'postValue'         => $post_value,
				'valueAfterPreview' => $value_after_preview,
				'jsValue'           => $js_value,
				'validity'          => self::describe_value( $validities[ $id ] ?? null ),
			)
		);

		self::collect_failure(
			$failures,
			6 === count( $calls['validate'] )
				&& 5 === count( $calls['sanitize'] )
				&& 1 === count( $calls['js'] )
				&& self::all_call_settings_match( $calls['validate'] )
				&& self::all_call_settings_match( $calls['sanitize'] )
				&& self::all_call_settings_match( $calls['js'] ),
			'custom sanitize, validate, and JS callbacks are invoked exactly as expected',
			array( 'calls' => $calls )
		);

		self::collect_failure(
			$failures,
			isset( $validities[ $invalid_id ] )
				&& \is_wp_error( $validities[ $invalid_id ] )
				&& $validities[ $invalid_id ]->get_error_code() === 'component_fuzz_invalid'
				&& 1 === $invalid_calls['validate']
				&& 0 === $invalid_calls['sanitize']
				&& ! array_key_exists( $null_id, $validities )
				&& 0 === $null_calls['validate']
				&& 0 === $null_calls['sanitize']
				&& isset( $validities[ $unknown_id ] )
				&& \is_wp_error( $validities[ $unknown_id ] )
				&& 'unrecognized' === $validities[ $unknown_id ]->get_error_code(),
			'validate_setting_values marks invalid/unrecognized values and skips null without callbacks',
			array(
				'invalidValidity' => self::describe_value( $validities[ $invalid_id ] ?? null ),
				'invalidCalls'    => $invalid_calls,
				'nullCalls'       => $null_calls,
				'unknownValidity' => self::describe_value( $validities[ $unknown_id ] ?? null ),
			)
		);

		return self::row(
			$ctx,
			'customizer.settings.callbacks-post-values',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_manager_post_value_merging( \ComponentFuzz\FuzzContext $ctx ): array {
		$manager      = self::manager( $ctx );
		$failures     = array();
		$posted_id    = self::id( $ctx, 'posted' );
		$program_id   = self::id( $ctx->fork( 'program' ), 'posted' );
		$unknown_id   = self::id( $ctx->fork( 'unknown' ), 'posted' );
		$posted_value = array(
			'source' => 'post',
			'value'  => self::non_null_value( $ctx->fork( 'posted-value' ) ),
		);
		$program_from_post = array(
			'source' => 'post-before-programmatic',
			'value'  => self::non_null_value( $ctx->fork( 'program-posted' ) ),
		);
		$program_override = array(
			'source' => 'programmatic',
			'value'  => self::non_null_value( $ctx->fork( 'program-override' ) ),
		);
		$sanitize_calls   = array();
		$post_value_events = array();
		$expected_sanitized = static function ( string $setting_id, $value ): array {
			return array(
				'setting' => $setting_id,
				'value'   => $value,
				'encoded' => self::stringify_value( $value ),
			);
		};
		$sanitize_callback = static function ( $value, \WP_Customize_Setting $setting ) use ( &$sanitize_calls, $expected_sanitized ): array {
			$sanitize_calls[] = array(
				'setting' => $setting->id,
				'value'   => self::describe_value( $value ),
			);
			return $expected_sanitized( $setting->id, $value );
		};

		$posted_setting = $manager->add_setting(
			$posted_id,
			array(
				'type'              => 'option',
				'capability'        => self::CAPABILITY,
				'default'           => 'posted-default',
				'sanitize_callback' => $sanitize_callback,
			)
		);
		$program_setting = $manager->add_setting(
			$program_id,
			array(
				'type'              => 'option',
				'capability'        => self::CAPABILITY,
				'default'           => 'program-default',
				'sanitize_callback' => $sanitize_callback,
			)
		);

		$customized = \wp_json_encode(
			array(
				$posted_id  => $posted_value,
				$program_id => $program_from_post,
			)
		);
		if ( ! is_string( $customized ) ) {
			throw new \RuntimeException( 'Could not encode Customizer posted values.' );
		}
		$decoded_customized = json_decode( $customized, true );
		if ( ! is_array( $decoded_customized ) ) {
			throw new \RuntimeException( 'Could not decode Customizer posted values.' );
		}
		$posted_value_from_json       = $decoded_customized[ $posted_id ] ?? null;
		$program_from_post_from_json  = $decoded_customized[ $program_id ] ?? null;

		$_POST['customized']    = \wp_slash( $customized );
		$_REQUEST['customized'] = $_POST['customized'];
		self::set_object_property( $manager, '_post_values', null );

		$from_post = self::with_capabilities(
			static function () use ( $manager ): array {
				return $manager->unsanitized_post_values(
					array(
						'exclude_changeset' => true,
						'exclude_post_data' => false,
					)
				);
			}
		);

		$dynamic_action = static function ( $value, \WP_Customize_Manager $seen_manager ) use ( &$post_value_events, $manager ): void {
			$post_value_events[] = array(
				'hook'        => 'dynamic',
				'value'       => self::describe_value( $value ),
				'sameManager' => $seen_manager === $manager,
			);
		};
		$global_action  = static function ( string $setting_id, $value, \WP_Customize_Manager $seen_manager ) use ( &$post_value_events, $manager ): void {
			$post_value_events[] = array(
				'hook'        => 'global',
				'setting'     => $setting_id,
				'value'       => self::describe_value( $value ),
				'sameManager' => $seen_manager === $manager,
			);
		};

		\add_action( "customize_post_value_set_{$program_id}", $dynamic_action, 10, 2 );
		\add_action( 'customize_post_value_set', $global_action, 10, 3 );
		try {
			$manager->set_post_value( $program_id, $program_override );
		} finally {
			\remove_action( 'customize_post_value_set', $global_action, 10 );
			\remove_action( "customize_post_value_set_{$program_id}", $dynamic_action, 10 );
		}

		$merged = self::with_capabilities(
			static function () use ( $manager ): array {
				return $manager->unsanitized_post_values(
					array(
						'exclude_changeset' => true,
						'exclude_post_data' => false,
					)
				);
			}
		);
		$posted_post_value  = $manager->post_value( $posted_setting, 'posted-fallback' );
		$program_post_value = $manager->post_value( $program_setting, 'program-fallback' );
		$validities         = $manager->validate_setting_values(
			array(
				$posted_id  => $posted_value_from_json,
				$program_id => $program_override,
				$unknown_id => 'unknown',
			),
			array( 'validate_existence' => true )
		);

		self::collect_failure(
			$failures,
			array(
				$posted_id  => $posted_value_from_json,
				$program_id => $program_from_post_from_json,
			) === $from_post
				&& $posted_value_from_json === ( $merged[ $posted_id ] ?? null )
				&& $program_override === ( $merged[ $program_id ] ?? null ),
			'unsanitized_post_values parses slashed customized JSON and programmatic values override posted values',
			array(
				'fromPost' => $from_post,
				'merged'   => $merged,
			)
		);

		self::collect_failure(
			$failures,
			$expected_sanitized( $posted_id, $posted_value_from_json ) === $posted_post_value
				&& $expected_sanitized( $program_id, $program_override ) === $program_post_value
				&& true === ( $validities[ $posted_id ] ?? null )
				&& true === ( $validities[ $program_id ] ?? null )
				&& isset( $validities[ $unknown_id ] )
				&& \is_wp_error( $validities[ $unknown_id ] )
				&& 'unrecognized' === $validities[ $unknown_id ]->get_error_code(),
			'post_value sanitizes merged values and validate_setting_values reports unknown settings',
			array(
				'postedPostValue'  => $posted_post_value,
				'programPostValue' => $program_post_value,
				'validities'       => self::describe_value( $validities ),
				'sanitizeCalls'    => $sanitize_calls,
			)
		);

		self::collect_failure(
			$failures,
			array(
				array(
					'hook'        => 'dynamic',
					'value'       => self::describe_value( $program_override ),
					'sameManager' => true,
				),
				array(
					'hook'        => 'global',
					'setting'     => $program_id,
					'value'       => self::describe_value( $program_override ),
					'sameManager' => true,
				),
			) === $post_value_events
				&& false === \has_action( "customize_post_value_set_{$program_id}", $dynamic_action )
				&& false === \has_action( 'customize_post_value_set', $global_action ),
			'set_post_value fires scoped and global events and removes temporary hooks',
			array( 'events' => $post_value_events )
		);

		return self::row(
			$ctx,
			'customizer.manager.post-value-json-merge-events',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_multidimensional_values( \ComponentFuzz\FuzzContext $ctx ): array {
		$manager  = self::manager( $ctx );
		$failures = array();
		$root_id  = self::id( $ctx, 'root' );
		$key_a    = 'alpha_' . $ctx->identifier( 3, 8 );
		$key_b    = 'beta_' . $ctx->identifier( 3, 8 );
		$id       = $root_id . '[' . $key_a . '][' . $key_b . ']';
		$initial  = self::non_null_value( $ctx->fork( 'initial' ) );
		$posted   = self::non_null_value( $ctx->fork( 'posted' ) );
		$updated  = self::non_null_value( $ctx->fork( 'updated' ) );
		$root     = array(
			$key_a => array(
				$key_b  => $initial,
				'spare' => self::unsafe_string( $ctx->fork( 'spare' ) ),
			),
			'flat' => $ctx->int( -50, 50 ),
		);

		\update_option( $root_id, $root, false );

		$sanitize_calls = array();
		$setting        = $manager->add_setting(
			$id,
			array(
				'type'              => 'option',
				'capability'        => self::CAPABILITY,
				'default'           => 'fallback-dimensional',
				'sanitize_callback' => static function ( $value, \WP_Customize_Setting $setting ) use ( &$sanitize_calls ) {
					$sanitize_calls[] = array(
						'value'     => self::describe_value( $value ),
						'settingId' => $setting->id,
					);
					return $value;
				},
			)
		);

		$id_data = $setting->id_data();
		self::collect_failure(
			$failures,
			$root_id === $id_data['base']
				&& array( $key_a, $key_b ) === $id_data['keys']
				&& $initial === $setting->value(),
			'multidimensional setting ID is parsed and initial option leaf is read',
			array(
				'id'      => $id,
				'idData'  => $id_data,
				'initial' => self::describe_value( $initial ),
				'value'   => self::describe_value( $setting->value() ),
			)
		);

		$manager->set_post_value( $setting->id, $posted );
		$unsanitized = $manager->unsanitized_post_values(
			array(
				'exclude_changeset' => true,
				'exclude_post_data' => false,
			)
		);
		$post_value  = $setting->post_value( 'fallback-posted' );
		$previewed   = $setting->preview();
		$value       = $setting->value();
		$preview_root = \get_option( $root_id );

		self::collect_failure(
			$failures,
			array_key_exists( $setting->id, $unsanitized )
				&& $posted === $unsanitized[ $setting->id ]
				&& $posted === $post_value
				&& true === $previewed
				&& $posted === $value
				&& is_array( $preview_root )
				&& $posted === self::nested_get( $preview_root, array( $key_a, $key_b ), null )
				&& self::nested_get( $root, array( $key_a, 'spare' ), null ) === self::nested_get( $preview_root, array( $key_a, 'spare' ), null ),
			'set_post_value and preview round-trip multidimensional values without clobbering siblings',
			array(
				'unsanitized' => $unsanitized,
				'postValue'   => self::describe_value( $post_value ),
				'value'       => self::describe_value( $value ),
				'previewRoot' => self::describe_value( $preview_root ),
			)
		);

		$manager->set_post_value( $setting->id, $updated );
		$value_after_second_post = $setting->value();
		$preview_root_updated    = \get_option( $root_id );

		self::collect_failure(
			$failures,
			$updated === $value_after_second_post
				&& is_array( $preview_root_updated )
				&& $updated === self::nested_get( $preview_root_updated, array( $key_a, $key_b ), null )
				&& count( $sanitize_calls ) >= 3,
			'multidimensional dirty state is cleared when a new post value is set',
			array(
				'updated'        => self::describe_value( $updated ),
				'valueAfterPost' => self::describe_value( $value_after_second_post ),
				'previewRoot'    => self::describe_value( $preview_root_updated ),
				'sanitizeCalls'  => $sanitize_calls,
			)
		);

		return self::row(
			$ctx,
			'customizer.settings.multidimensional-post-values',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_json_and_active_callbacks( \ComponentFuzz\FuzzContext $ctx ): array {
		$manager       = self::manager( $ctx );
		$failures      = array();
		$setting_id    = self::id( $ctx, 'json-setting' );
		$panel_id      = self::id( $ctx->fork( 'panel' ), 'json-panel' );
		$section_id    = self::id( $ctx->fork( 'section' ), 'json-section' );
		$control_id    = self::id( $ctx->fork( 'control' ), 'json-control' );
		$unsafe_label  = self::unsafe_string( $ctx->fork( 'label' ) );
		$unsafe_desc   = self::unsafe_string( $ctx->fork( 'description' ) );
		$active_calls  = array();
		$panel_active  = $ctx->bool();
		$section_active = $ctx->bool();
		$control_active = $ctx->bool();

		$manager->add_setting(
			$setting_id,
			array(
				'type'       => 'component_fuzz_json',
				'capability' => self::CAPABILITY,
				'default'    => self::unsafe_string( $ctx->fork( 'setting-default' ) ),
			)
		);

		$panel = $manager->add_panel(
			$panel_id,
			array(
				'title'           => 'Panel &amp; ' . $unsafe_label,
				'description'     => $unsafe_desc,
				'priority'        => 15,
				'capability'      => self::CAPABILITY,
				'active_callback' => static function ( \WP_Customize_Panel $panel ) use ( &$active_calls, $panel_active ): bool {
					$active_calls[] = array(
						'kind' => 'panel',
						'id'   => $panel->id,
					);
					return $panel_active;
				},
			)
		);
		$section = $manager->add_section(
			$section_id,
			array(
				'title'              => 'Section &amp; ' . $unsafe_label,
				'description'        => $unsafe_desc,
				'panel'              => $panel_id,
				'priority'           => 10,
				'capability'         => self::CAPABILITY,
				'description_hidden' => $ctx->bool(),
				'active_callback'    => static function ( \WP_Customize_Section $section ) use ( &$active_calls, $section_active ): bool {
					$active_calls[] = array(
						'kind' => 'section',
						'id'   => $section->id,
					);
					return $section_active;
				},
			)
		);
		$control = $manager->add_control(
			$control_id,
			array(
				'settings'        => $setting_id,
				'section'         => $section_id,
				'type'            => 'text',
				'label'           => $unsafe_label,
				'description'     => $unsafe_desc,
				'priority'        => 7,
				'capability'      => self::CAPABILITY,
				'input_attrs'     => array(
					'data-component-fuzz' => self::unsafe_string( $ctx->fork( 'input-attr' ) ),
				),
				'active_callback' => static function ( \WP_Customize_Control $control ) use ( &$active_calls, $control_active ): bool {
					$active_calls[] = array(
						'kind' => 'control',
						'id'   => $control->id,
					);
					return $control_active;
				},
			)
		);

		$json = self::with_capabilities(
			static function () use ( $panel, $section, $control ): array {
				return array(
					'panel'   => $panel->json(),
					'section' => $section->json(),
					'control' => $control->json(),
					'link'    => $control->get_link(),
				);
			}
		);
		$encoded = \wp_json_encode( $json );
		$decoded = is_string( $encoded ) ? json_decode( $encoded, true ) : null;

		self::collect_failure(
			$failures,
			$panel_active === $json['panel']['active']
				&& $section_active === $json['section']['active']
				&& $control_active === $json['control']['active']
				&& array(
					array(
						'kind' => 'panel',
						'id'   => $panel_id,
					),
					array(
						'kind' => 'section',
						'id'   => $section_id,
					),
					array(
						'kind' => 'control',
						'id'   => $control_id,
					),
				) === $active_calls,
			'panel, section, and control active callbacks are scoped to their own instances',
			array(
				'expected' => array( $panel_active, $section_active, $control_active ),
				'json'     => $json,
				'calls'    => $active_calls,
			)
		);

		self::collect_failure(
			$failures,
			$panel_id === $json['panel']['id']
				&& 'Panel & ' . $unsafe_label === $json['panel']['title']
				&& $section_id === $json['section']['id']
				&& $panel_id === $json['section']['panel']
				&& 'Section & ' . $unsafe_label === $json['section']['title']
				&& $control_id === $control->id
				&& $unsafe_label === $json['control']['label']
				&& $unsafe_desc === $json['control']['description']
				&& array( 'default' => $setting_id ) === $json['control']['settings']
				&& str_contains( $json['link'], 'data-customize-setting-link="' )
				&& str_contains( $json['link'], esc_attr( $setting_id ) )
				&& is_string( $encoded )
				&& is_array( $decoded ),
			'JSON exports preserve expected fields and encode deterministically',
			array(
				'json'    => $json,
				'encoded' => $encoded,
				'decoded' => $decoded,
			)
		);

		return self::row(
			$ctx,
			'customizer.controls-containers.json-active-callbacks',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_control_rendering_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$manager  = self::manager( $ctx );
		$failures = array();

		$section_id = self::id( $ctx, 'render-section' );
		$manager->add_section(
			$section_id,
			array(
				'title'      => 'Render Section',
				'capability' => self::CAPABILITY,
			)
		);

		$description = '<strong>Component fuzz description</strong> &amp; details';
		$controls    = array();
		$events      = array();
		$specifics   = array();

		$text_setting_id = self::id( $ctx->fork( 'text-setting' ), 'render-text-setting' );
		$text_value      = self::unsafe_string( $ctx->fork( 'text-value' ) );
		$text_label      = 'Text label ' . self::unsafe_string( $ctx->fork( 'text-label' ) );
		$text_attr       = '" onmouseover="componentFuzz() <b>';
		$manager->add_setting(
			$text_setting_id,
			array(
				'type'       => 'component_fuzz_render',
				'capability' => self::CAPABILITY,
				'default'    => $text_value,
			)
		);
		$text_control_id              = self::id( $ctx->fork( 'text-control' ), 'render-text-control' );
		$controls['text']             = $manager->add_control(
			$text_control_id,
			array(
				'settings'    => $text_setting_id,
				'section'     => $section_id,
				'type'        => 'text',
				'label'       => $text_label,
				'description' => $description,
				'capability'  => self::CAPABILITY,
				'input_attrs' => array(
					'placeholder'         => $text_attr,
					'data-component-fuzz' => self::unsafe_string( $ctx->fork( 'text-attr' ) ),
				),
			)
		);

		$textarea_setting_id = self::id( $ctx->fork( 'textarea-setting' ), 'render-textarea-setting' );
		$textarea_value      = self::unsafe_string( $ctx->fork( 'textarea-value' ) ) . "\nline two";
		$textarea_label      = 'Textarea ' . self::unsafe_string( $ctx->fork( 'textarea-label' ) );
		$manager->add_setting(
			$textarea_setting_id,
			array(
				'type'       => 'component_fuzz_render',
				'capability' => self::CAPABILITY,
				'default'    => $textarea_value,
			)
		);
		$textarea_control_id          = self::id( $ctx->fork( 'textarea-control' ), 'render-textarea-control' );
		$controls['textarea']         = $manager->add_control(
			$textarea_control_id,
			array(
				'settings'    => $textarea_setting_id,
				'section'     => $section_id,
				'type'        => 'textarea',
				'label'       => $textarea_label,
				'description' => $description,
				'capability'  => self::CAPABILITY,
				'input_attrs' => array(
					'data-component-fuzz' => self::unsafe_string( $ctx->fork( 'textarea-attr' ) ),
				),
			)
		);

		$checkbox_setting_id = self::id( $ctx->fork( 'checkbox-setting' ), 'render-checkbox-setting' );
		$checkbox_label      = 'Checkbox ' . self::unsafe_string( $ctx->fork( 'checkbox-label' ) );
		$manager->add_setting(
			$checkbox_setting_id,
			array(
				'type'       => 'component_fuzz_render',
				'capability' => self::CAPABILITY,
				'default'    => '1',
			)
		);
		$checkbox_control_id          = self::id( $ctx->fork( 'checkbox-control' ), 'render-checkbox-control' );
		$controls['checkbox']         = $manager->add_control(
			$checkbox_control_id,
			array(
				'settings'    => $checkbox_setting_id,
				'section'     => $section_id,
				'type'        => 'checkbox',
				'label'       => $checkbox_label,
				'description' => $description,
				'capability'  => self::CAPABILITY,
			)
		);

		$radio_setting_id = self::id( $ctx->fork( 'radio-setting' ), 'render-radio-setting' );
		$radio_value      = 'beta-' . $ctx->identifier( 3, 8 );
		$radio_other      = 'alpha-' . $ctx->identifier( 3, 8 );
		$radio_label      = 'Radio ' . self::unsafe_string( $ctx->fork( 'radio-label' ) );
		$radio_choices    = array(
			$radio_other => 'Alpha ' . self::unsafe_string( $ctx->fork( 'radio-alpha-label' ) ),
			$radio_value => 'Beta ' . self::unsafe_string( $ctx->fork( 'radio-beta-label' ) ),
		);
		$manager->add_setting(
			$radio_setting_id,
			array(
				'type'       => 'component_fuzz_render',
				'capability' => self::CAPABILITY,
				'default'    => $radio_value,
			)
		);
		$radio_control_id             = self::id( $ctx->fork( 'radio-control' ), 'render-radio-control' );
		$controls['radio']            = $manager->add_control(
			$radio_control_id,
			array(
				'settings'    => $radio_setting_id,
				'section'     => $section_id,
				'type'        => 'radio',
				'label'       => $radio_label,
				'description' => $description,
				'capability'  => self::CAPABILITY,
				'choices'     => $radio_choices,
			)
		);

		$select_setting_id = self::id( $ctx->fork( 'select-setting' ), 'render-select-setting' );
		$select_value      = 'two-' . $ctx->identifier( 3, 8 );
		$select_other      = 'one-' . $ctx->identifier( 3, 8 );
		$select_label      = 'Select ' . self::unsafe_string( $ctx->fork( 'select-label' ) );
		$select_choices    = array(
			$select_other => 'One ' . self::unsafe_string( $ctx->fork( 'select-one-label' ) ),
			$select_value => 'Two ' . self::unsafe_string( $ctx->fork( 'select-two-label' ) ),
		);
		$manager->add_setting(
			$select_setting_id,
			array(
				'type'       => 'component_fuzz_render',
				'capability' => self::CAPABILITY,
				'default'    => $select_value,
			)
		);
		$select_control_id            = self::id( $ctx->fork( 'select-control' ), 'render-select-control' );
		$controls['select']           = $manager->add_control(
			$select_control_id,
			array(
				'settings'    => $select_setting_id,
				'section'     => $section_id,
				'type'        => 'select',
				'label'       => $select_label,
				'description' => $description,
				'capability'  => self::CAPABILITY,
				'choices'     => $select_choices,
			)
		);

		$denied_setting_id = self::id( $ctx->fork( 'denied-setting' ), 'render-denied-setting' );
		$manager->add_setting(
			$denied_setting_id,
			array(
				'type'       => 'component_fuzz_render',
				'capability' => 'component_fuzz_denied_cap',
				'default'    => 'denied',
			)
		);
		$denied_control_id = self::id( $ctx->fork( 'denied-control' ), 'render-denied-control' );
		$denied_control    = $manager->add_control(
			$denied_control_id,
			array(
				'settings'   => $denied_setting_id,
				'section'    => $section_id,
				'type'       => 'text',
				'label'      => 'Denied',
				'capability' => 'component_fuzz_denied_cap',
			)
		);

		$global_action = static function ( \WP_Customize_Control $control ) use ( &$events ): void {
			$events[] = array(
				'hook' => 'global',
				'id'   => $control->id,
				'type' => $control->type,
			);
		};
		foreach ( array_merge( $controls, array( 'denied' => $denied_control ) ) as $kind => $control ) {
			$specifics[ $control->id ] = static function ( \WP_Customize_Control $seen ) use ( &$events, $kind ): void {
				$events[] = array(
					'hook' => 'specific',
					'kind' => $kind,
					'id'   => $seen->id,
					'type' => $seen->type,
				);
			};
		}

		\add_action( 'customize_render_control', $global_action, 10, 1 );
		foreach ( $specifics as $control_id => $callback ) {
			\add_action( "customize_render_control_{$control_id}", $callback, 10, 1 );
		}

		try {
			$outputs = self::with_capabilities(
				static function () use ( $controls, $denied_control ): array {
					$rendered = array();
					foreach ( $controls as $kind => $control ) {
						$rendered[ $kind ] = $control instanceof \WP_Customize_Control ? $control->get_content() : '';
					}
					$rendered['denied'] = $denied_control instanceof \WP_Customize_Control ? $denied_control->get_content() : '';
					return $rendered;
				}
			);
		} finally {
			\remove_action( 'customize_render_control', $global_action, 10 );
			foreach ( $specifics as $control_id => $callback ) {
				\remove_action( "customize_render_control_{$control_id}", $callback, 10 );
			}
		}

		$rendered_ids      = array( $text_control_id, $textarea_control_id, $checkbox_control_id, $radio_control_id, $select_control_id );
		$global_event_ids  = array_values(
			array_map(
				static fn ( array $event ): string => (string) $event['id'],
				array_filter(
					$events,
					static fn ( array $event ): bool => 'global' === $event['hook']
				)
			)
		);
		$specific_event_ids = array_values(
			array_map(
				static fn ( array $event ): string => (string) $event['id'],
				array_filter(
					$events,
					static fn ( array $event ): bool => 'specific' === $event['hook']
				)
			)
		);

		self::collect_failure(
			$failures,
			$rendered_ids === $global_event_ids
				&& $rendered_ids === $specific_event_ids
				&& '' === ( $outputs['denied'] ?? null )
				&& ! in_array( $denied_control_id, $global_event_ids, true )
				&& ! in_array( $denied_control_id, $specific_event_ids, true )
				&& false === \has_action( 'customize_render_control', $global_action )
				&& self::all_specific_render_hooks_removed( $specifics ),
			'maybe_render fires global and specific hooks for capable controls only and restores temporary hooks',
			array(
				'events'      => $events,
				'globalIds'   => $global_event_ids,
				'specificIds' => $specific_event_ids,
				'deniedHtml'  => self::describe_value( $outputs['denied'] ?? null ),
			)
		);

		$text_html = (string) ( $outputs['text'] ?? '' );
		self::collect_failure(
			$failures,
			str_contains( $text_html, 'id="customize-control-' . esc_attr( str_replace( array( '[', ']' ), array( '-', '' ), $text_control_id ) ) . '"' )
				&& str_contains( $text_html, 'class="customize-control customize-control-text"' )
				&& str_contains( $text_html, esc_html( $text_label ) )
				&& str_contains( $text_html, 'value="' . esc_attr( $text_value ) . '"' )
				&& str_contains( $text_html, 'data-customize-setting-link="' . esc_attr( $text_setting_id ) . '"' )
				&& str_contains( $text_html, 'placeholder="' . esc_attr( $text_attr ) . '"' )
				&& str_contains( $text_html, $description )
				&& ! str_contains( $text_html, $text_label )
				&& ! str_contains( $text_html, $text_value ),
			'text control rendering escapes labels, values, input attributes, and includes setting links',
			array( 'html' => self::describe_string( $text_html ) )
		);

		$textarea_html = (string) ( $outputs['textarea'] ?? '' );
		self::collect_failure(
			$failures,
			str_contains( $textarea_html, 'class="customize-control customize-control-textarea"' )
				&& str_contains( $textarea_html, esc_html( $textarea_label ) )
				&& str_contains( $textarea_html, 'rows="5"' )
				&& str_contains( $textarea_html, 'data-customize-setting-link="' . esc_attr( $textarea_setting_id ) . '"' )
				&& str_contains( $textarea_html, '>' . esc_textarea( $textarea_value ) . '</textarea>' )
				&& ! str_contains( $textarea_html, $textarea_label )
				&& ! str_contains( $textarea_html, $textarea_value ),
			'textarea control rendering escapes label/value content and adds the default row count',
			array( 'html' => self::describe_string( $textarea_html ) )
		);

		$checkbox_html = (string) ( $outputs['checkbox'] ?? '' );
		self::collect_failure(
			$failures,
			str_contains( $checkbox_html, 'class="customize-control customize-control-checkbox"' )
				&& str_contains( $checkbox_html, 'type="checkbox"' )
				&& str_contains( $checkbox_html, "checked='checked'" )
				&& str_contains( $checkbox_html, 'value="1"' )
				&& str_contains( $checkbox_html, esc_html( $checkbox_label ) )
				&& str_contains( $checkbox_html, 'data-customize-setting-link="' . esc_attr( $checkbox_setting_id ) . '"' )
				&& ! str_contains( $checkbox_html, $checkbox_label ),
			'checkbox control rendering marks truthy values checked and escapes labels',
			array( 'html' => self::describe_string( $checkbox_html ) )
		);

		$radio_html = (string) ( $outputs['radio'] ?? '' );
		self::collect_failure(
			$failures,
			str_contains( $radio_html, 'class="customize-control customize-control-radio"' )
				&& 2 === substr_count( $radio_html, 'type="radio"' )
				&& 1 === substr_count( $radio_html, "checked='checked'" )
				&& str_contains( $radio_html, 'value="' . esc_attr( $radio_value ) . '"' )
				&& str_contains( $radio_html, esc_html( $radio_choices[ $radio_other ] ) )
				&& str_contains( $radio_html, esc_html( $radio_choices[ $radio_value ] ) )
				&& str_contains( $radio_html, 'data-customize-setting-link="' . esc_attr( $radio_setting_id ) . '"' )
				&& ! str_contains( $radio_html, $radio_choices[ $radio_other ] )
				&& ! str_contains( $radio_html, $radio_choices[ $radio_value ] ),
			'radio control rendering escapes choices and checks exactly the selected value',
			array( 'html' => self::describe_string( $radio_html ) )
		);

		$select_html = (string) ( $outputs['select'] ?? '' );
		self::collect_failure(
			$failures,
			str_contains( $select_html, 'class="customize-control customize-control-select"' )
				&& str_contains( $select_html, '<select' )
				&& 2 === substr_count( $select_html, '<option ' )
				&& 1 === substr_count( $select_html, "selected='selected'" )
				&& str_contains( $select_html, '<option value="' . esc_attr( $select_value ) . '" selected=' )
				&& str_contains( $select_html, esc_html( $select_choices[ $select_other ] ) )
				&& str_contains( $select_html, esc_html( $select_choices[ $select_value ] ) )
				&& str_contains( $select_html, 'data-customize-setting-link="' . esc_attr( $select_setting_id ) . '"' )
				&& ! str_contains( $select_html, $select_choices[ $select_other ] )
				&& ! str_contains( $select_html, $select_choices[ $select_value ] ),
			'select control rendering escapes option labels and selects exactly the matching value',
			array( 'html' => self::describe_string( $select_html ) )
		);

		return self::row(
			$ctx,
			'customizer.controls.rendering-hooks-escaping-selection',
			array() === $failures,
			array(
				'controls' => array_keys( $controls ),
				'events'   => count( $events ),
				'failures' => $failures,
			)
		);
	}

	private static function check_selective_refresh_partials( \ComponentFuzz\FuzzContext $ctx ): array {
		$manager    = self::manager( $ctx );
		$failures   = array();
		$setting_id = self::id( $ctx, 'partial-setting' );
		$partial_id = $setting_id . '[' . $ctx->identifier( 3, 8 ) . ']';
		$unsafe     = self::unsafe_string( $ctx->fork( 'partial' ) );
		$calls      = array();

		$manager->add_setting(
			$setting_id,
			array(
				'type'       => 'component_fuzz_partial',
				'capability' => self::CAPABILITY,
				'default'    => 'partial-default',
			)
		);

		$partial = $manager->selective_refresh->add_partial(
			$partial_id,
			array(
				'selector'            => '#component-fuzz-' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $partial_id ),
				'settings'            => array( $setting_id ),
				'primary_setting'     => $setting_id,
				'capability'          => self::CAPABILITY,
				'container_inclusive' => $ctx->bool(),
				'fallback_refresh'    => $ctx->bool(),
				'render_callback'     => static function ( \WP_Customize_Partial $partial, array $context ) use ( &$calls, $unsafe ) {
					$calls[] = array(
						'id'      => $partial->id,
						'context' => $context,
					);
					return '<span data-component-fuzz="' . esc_attr( $partial->id ) . '">' . $unsafe . '</span>';
				},
			)
		);

		$context  = array(
			'number' => $ctx->int( -100, 100 ),
			'text'   => self::unsafe_string( $ctx->fork( 'context' ) ),
		);
		$json     = $partial->json();
		$rendered = $partial->render( $context );
		$can      = self::with_capabilities(
			static function () use ( $partial ): bool {
				return $partial->check_capabilities();
			}
		);
		$all_partials = $manager->selective_refresh->partials();

		self::collect_failure(
			$failures,
			$partial instanceof \WP_Customize_Partial
				&& $partial === $manager->selective_refresh->get_partial( $partial_id )
				&& isset( $all_partials[ $partial_id ] )
				&& $partial === $all_partials[ $partial_id ],
			'selective refresh partial add/get/aggregate registry is consistent',
			array(
				'partialId' => $partial_id,
				'partials'  => array_keys( $all_partials ),
			)
		);

		self::collect_failure(
			$failures,
			array( $setting_id ) === $json['settings']
				&& $setting_id === $json['primarySetting']
				&& $partial->selector === $json['selector']
				&& $partial->container_inclusive === $json['containerInclusive']
				&& $partial->fallback_refresh === $json['fallbackRefresh']
				&& true === $can,
			'partial JSON and capability checks reflect configured fields',
			array(
				'json' => $json,
				'can'  => $can,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $rendered )
				&& str_contains( $rendered, esc_attr( $partial_id ) )
				&& str_contains( $rendered, $unsafe )
				&& array(
					array(
						'id'      => $partial_id,
						'context' => $context,
					),
				) === $calls,
			'partial render callback receives scoped context and returns deterministic markup',
			array(
				'rendered' => $rendered,
				'calls'    => $calls,
			)
		);

		$manager->selective_refresh->remove_partial( $partial_id );
		self::collect_failure(
			$failures,
			null === $manager->selective_refresh->get_partial( $partial_id ),
			'remove_partial removes the registered partial',
			array( 'partialId' => $partial_id )
		);

		return self::row(
			$ctx,
			'customizer.selective-refresh.partials',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_theme_preview_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$fixture         = self::create_theme_fixture( $ctx );
		$option_snapshot = isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
			? $GLOBALS['wpdb']->component_fuzz_get_options()
			: null;
		$start_events    = array();
		$stop_events     = array();
		$preview_manager = null;
		$active_manager  = null;

		$theme_filters = array(
			'template'                   => 'get_template',
			'stylesheet'                 => 'get_stylesheet',
			'pre_option_current_theme'   => 'current_theme',
			'pre_option_stylesheet'      => 'get_stylesheet',
			'pre_option_template'        => 'get_template',
			'pre_option_stylesheet_root' => 'get_stylesheet_root',
			'pre_option_template_root'   => 'get_template_root',
		);

		$start_action = static function ( \WP_Customize_Manager $manager ) use ( &$start_events, &$preview_manager, &$active_manager ): void {
			$start_events[] = array(
				'samePreviewManager' => null !== $preview_manager && $manager === $preview_manager,
				'sameActiveManager'  => null !== $active_manager && $manager === $active_manager,
				'isPreview'          => $manager->is_preview(),
				'isThemeActive'      => $manager->is_theme_active(),
				'stylesheet'         => $manager->get_stylesheet(),
				'template'           => $manager->get_template(),
			);
		};
		$stop_action  = static function ( \WP_Customize_Manager $manager ) use ( &$stop_events, &$preview_manager, &$active_manager ): void {
			$stop_events[] = array(
				'samePreviewManager' => null !== $preview_manager && $manager === $preview_manager,
				'sameActiveManager'  => null !== $active_manager && $manager === $active_manager,
				'isPreview'          => $manager->is_preview(),
				'isThemeActive'      => $manager->is_theme_active(),
				'stylesheet'         => $manager->get_stylesheet(),
				'template'           => $manager->get_template(),
			);
		};

		\add_action( 'start_previewing_theme', $start_action );
		\add_action( 'stop_previewing_theme', $stop_action );

		try {
			self::seed_active_theme_options( $fixture );

			$preview_manager = self::manager( $ctx, array( 'theme' => $fixture['previewSlug'] ) );
			$before_preview_filters = self::manager_theme_filters( $preview_manager, $theme_filters );

			$preview_manager->start_previewing_theme();
			$after_start_values  = self::theme_preview_values();
			$after_start_filters = self::manager_theme_filters( $preview_manager, $theme_filters );
			$preview_manager->start_previewing_theme();
			$after_second_start_events = count( $start_events );

			$preview_manager->stop_previewing_theme();
			$after_stop_values  = self::theme_preview_values();
			$after_stop_filters = self::manager_theme_filters( $preview_manager, $theme_filters );
			$preview_manager->stop_previewing_theme();
			$after_second_stop_events = count( $stop_events );

			self::collect_failure(
				$failures,
				! $preview_manager->is_theme_active()
					&& false === $before_preview_filters['any']
					&& true === $after_start_filters['all']
					&& false === $after_stop_filters['any']
					&& $fixture['previewSlug'] === $after_start_values['stylesheet']
					&& $fixture['previewTemplate'] === $after_start_values['template']
					&& $fixture['previewName'] === $after_start_values['currentTheme']
					&& $fixture['rawRoot'] === $after_start_values['stylesheetRoot']
					&& $fixture['rawRoot'] === $after_start_values['templateRoot']
					&& $fixture['activeSlug'] === $after_stop_values['stylesheet']
					&& $fixture['activeSlug'] === $after_stop_values['template']
					&& $fixture['activeName'] === $after_stop_values['currentTheme']
					&& $fixture['rawRoot'] === $after_stop_values['stylesheetRoot']
					&& $fixture['rawRoot'] === $after_stop_values['templateRoot']
					&& array(
						array(
							'samePreviewManager' => true,
							'sameActiveManager'  => false,
							'isPreview'          => true,
							'isThemeActive'      => false,
							'stylesheet'         => $fixture['previewSlug'],
							'template'           => $fixture['previewTemplate'],
						),
					) === $start_events
					&& array(
						array(
							'samePreviewManager' => true,
							'sameActiveManager'  => false,
							'isPreview'          => false,
							'isThemeActive'      => false,
							'stylesheet'         => $fixture['previewSlug'],
							'template'           => $fixture['previewTemplate'],
						),
					) === $stop_events
					&& 1 === $after_second_start_events
					&& 1 === $after_second_stop_events,
				'inactive theme preview installs theme-switching filters, exposes preview values, fires once, and cleans up on stop',
				array(
					'fixture'                => $fixture,
					'beforeFilters'          => $before_preview_filters,
					'afterStartFilters'      => $after_start_filters,
					'afterStopFilters'       => $after_stop_filters,
					'afterStartValues'       => $after_start_values,
					'afterStopValues'        => $after_stop_values,
					'startEvents'            => $start_events,
					'stopEvents'             => $stop_events,
					'afterSecondStartEvents' => $after_second_start_events,
					'afterSecondStopEvents'  => $after_second_stop_events,
				)
			);

			$start_events   = array();
			$stop_events    = array();
			$active_manager = self::manager( $ctx->fork( 'active' ), array( 'theme' => $fixture['activeSlug'] ) );
			$before_active_filters = self::manager_theme_filters( $active_manager, $theme_filters );
			$active_manager->start_previewing_theme();
			$active_start_filters = self::manager_theme_filters( $active_manager, $theme_filters );
			$active_manager->stop_previewing_theme();
			$active_stop_filters = self::manager_theme_filters( $active_manager, $theme_filters );

			self::collect_failure(
				$failures,
				$active_manager->is_theme_active()
					&& false === $before_active_filters['any']
					&& false === $active_start_filters['any']
					&& false === $active_stop_filters['any']
					&& array(
						array(
							'samePreviewManager' => false,
							'sameActiveManager'  => true,
							'isPreview'          => true,
							'isThemeActive'      => true,
							'stylesheet'         => $fixture['activeSlug'],
							'template'           => $fixture['activeSlug'],
						),
					) === $start_events
					&& array(
						array(
							'samePreviewManager' => false,
							'sameActiveManager'  => true,
							'isPreview'          => false,
							'isThemeActive'      => true,
							'stylesheet'         => $fixture['activeSlug'],
							'template'           => $fixture['activeSlug'],
						),
					) === $stop_events,
				'active theme preview toggles preview state and actions without installing theme-switching filters',
				array(
					'beforeFilters'      => $before_active_filters,
					'activeStartFilters' => $active_start_filters,
					'activeStopFilters'  => $active_stop_filters,
					'startEvents'        => $start_events,
					'stopEvents'         => $stop_events,
				)
			);
		} finally {
			\remove_action( 'start_previewing_theme', $start_action );
			\remove_action( 'stop_previewing_theme', $stop_action );
			if ( null !== $option_snapshot && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
				$GLOBALS['wpdb']->component_fuzz_reset_options( $option_snapshot );
			}
			self::remove_theme_fixture( $fixture );
		}

		self::collect_failure(
			$failures,
			false === \has_action( 'start_previewing_theme', $start_action )
				&& false === \has_action( 'stop_previewing_theme', $stop_action ),
			'theme preview lifecycle actions are removed after the check',
			array(
				'startAction' => \has_action( 'start_previewing_theme', $start_action ),
				'stopAction'  => \has_action( 'stop_previewing_theme', $stop_action ),
			)
		);

		return self::row(
			$ctx,
			'customizer.manager.theme-preview-filter-lifecycle',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function manager( \ComponentFuzz\FuzzContext $ctx, array $args = array() ): \WP_Customize_Manager {
		$components_filter = static function (): array {
			return array();
		};
		\add_filter( 'customize_loaded_components', $components_filter, 1000 );
		try {
			$manager = new \WP_Customize_Manager(
				array_merge(
					array(
						'changeset_uuid'     => self::uuid( $ctx ),
						'settings_previewed' => false,
						'branching'          => true,
						'autosaved'          => false,
					),
					$args
				)
			);
		} finally {
			\remove_filter( 'customize_loaded_components', $components_filter, 1000 );
		}

		self::set_object_property( $manager, '_changeset_data', array() );
		self::set_object_property( $manager, '_post_values', array() );
		if ( false === \has_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ) ) ) {
			\add_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ), 10, 4 );
		}
		return $manager;
	}

	private static function create_theme_fixture( \ComponentFuzz\FuzzContext $ctx ): array {
		$theme_root = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/themes' : sys_get_temp_dir() . '/component-fuzz-themes';
		if ( ! is_dir( $theme_root ) ) {
			@mkdir( $theme_root, 0777, true );
		}

		$suffix          = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $ctx->identifier( 6, 14 ) ) );
		$suffix          = trim( $suffix, '-' );
		$suffix          = '' === $suffix ? substr( md5( (string) $ctx->seed() ), 0, 8 ) : $suffix;
		$active_slug     = 'cfz-active-' . $suffix;
		$preview_parent  = 'cfz-parent-' . $suffix;
		$preview_slug    = 'cfz-child-' . $suffix;
		$active_name     = 'CFZ Active ' . $suffix;
		$parent_name     = 'CFZ Parent ' . $suffix;
		$preview_name    = 'CFZ Preview ' . $suffix;
		$created_paths   = array(
			$theme_root . '/' . $active_slug,
			$theme_root . '/' . $preview_parent,
			$theme_root . '/' . $preview_slug,
		);

		self::write_theme_files( $created_paths[0], $active_name );
		self::write_theme_files( $created_paths[1], $parent_name );
		self::write_theme_files( $created_paths[2], $preview_name, $preview_parent );
		self::refresh_theme_discovery();

		return array(
			'themeRoot'       => $theme_root,
			'rawRoot'         => \get_raw_theme_root( $preview_slug, true ),
			'activeSlug'      => $active_slug,
			'activeName'      => $active_name,
			'previewSlug'     => $preview_slug,
			'previewTemplate' => $preview_parent,
			'previewName'     => $preview_name,
			'createdPaths'    => $created_paths,
		);
	}

	private static function write_theme_files( string $path, string $name, ?string $template = null ): void {
		if ( ! is_dir( $path ) ) {
			@mkdir( $path, 0777, true );
		}

		$headers = "/*\nTheme Name: {$name}\n";
		if ( null !== $template ) {
			$headers .= "Template: {$template}\n";
		}
		$headers .= "*/\n";

		file_put_contents( $path . '/style.css', $headers );
		file_put_contents( $path . '/index.php', "<?php\n" );
	}

	private static function refresh_theme_discovery(): void {
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			\wp_clean_themes_cache( true );
		}
		if ( function_exists( 'delete_site_transient' ) ) {
			\delete_site_transient( 'theme_roots' );
		}
		if ( function_exists( 'search_theme_directories' ) ) {
			\search_theme_directories( true );
		}
	}

	private static function seed_active_theme_options( array $fixture ): void {
		\update_option( 'stylesheet', $fixture['activeSlug'] );
		\update_option( 'template', $fixture['activeSlug'] );
		\update_option( 'current_theme', $fixture['activeName'] );
		\update_option( 'stylesheet_root', $fixture['rawRoot'] );
		\update_option( 'template_root', $fixture['rawRoot'] );
	}

	private static function remove_theme_fixture( array $fixture ): void {
		foreach ( array_reverse( $fixture['createdPaths'] ?? array() ) as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			@unlink( $path . '/index.php' );
			@unlink( $path . '/style.css' );
			@rmdir( $path );
		}
		self::refresh_theme_discovery();
	}

	private static function manager_theme_filters( \WP_Customize_Manager $manager, array $filters ): array {
		$states = array();
		foreach ( $filters as $hook => $method ) {
			$states[ $hook ] = \has_filter( $hook, array( $manager, $method ) );
		}

		$truthy = array_filter(
			$states,
			static function ( $priority ): bool {
				return false !== $priority;
			}
		);

		return array(
			'states' => $states,
			'all'    => count( $states ) === count( $truthy ) && array( 10 ) === array_values( array_unique( array_values( $truthy ) ) ),
			'any'    => array() !== $truthy,
		);
	}

	private static function theme_preview_values(): array {
		return array(
			'stylesheet'     => \get_stylesheet(),
			'template'       => \get_template(),
			'currentTheme'   => \get_option( 'current_theme' ),
			'stylesheetRoot' => \get_option( 'stylesheet_root' ),
			'templateRoot'   => \get_option( 'template_root' ),
		);
	}

	private static function with_capabilities( callable $callback ) {
		$cap_filter = static function ( array $allcaps ): array {
			$allcaps[ self::CAPABILITY ]       = true;
			$allcaps['customize']              = true;
			$allcaps['edit_theme_options']     = true;
			$allcaps['unfiltered_html']        = true;
			$allcaps['edit_css']               = true;
			return $allcaps;
		};

		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		try {
			return $callback();
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}
	}

	private static function id( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$pieces = array(
			$prefix,
			(string) $ctx->iteration(),
			substr( md5( (string) $ctx->seed() . ':' . $prefix ), 0, 8 ),
			$ctx->identifier( 3, 10 ),
		);

		return 'cfz_' . implode( '_', $pieces );
	}

	private static function uuid( \ComponentFuzz\FuzzContext $ctx ): string {
		$hex = md5( 'customizer:' . $ctx->seed() . ':' . $ctx->iteration() );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	private static function unsafe_string( \ComponentFuzz\FuzzContext $ctx ): string {
		return '<script>alert("' . $ctx->identifier( 3, 8 ) . '")</script>'
			. '<img src=x onerror=alert(1)>'
			. '& "\' '
			. $ctx->text( 0, 32 );
	}

	private static function fuzz_value( \ComponentFuzz\FuzzContext $ctx, bool $allow_null = true ) {
		$choices = array( 'unsafe-string', 'utf8-text', 'int', 'bool', 'array' );
		if ( $allow_null ) {
			$choices[] = 'null';
		}

		switch ( $ctx->choice( $choices ) ) {
			case 'unsafe-string':
				return self::unsafe_string( $ctx->fork( 'unsafe' ) );
			case 'utf8-text':
				return "utf8-\xE2\x98\x83-" . $ctx->text( 1, 48 );
			case 'int':
				return $ctx->int( -100000, 100000 );
			case 'bool':
				return $ctx->bool();
			case 'array':
				return array(
					'html'   => self::unsafe_string( $ctx->fork( 'array-html' ) ),
					'number' => $ctx->int( -1000, 1000 ),
					'flag'   => $ctx->bool(),
				);
			default:
				return null;
		}
	}

	private static function non_null_value( \ComponentFuzz\FuzzContext $ctx ) {
		return self::fuzz_value( $ctx, false );
	}

	private static function stringify_value( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $encoded ? '[unencodable]' : $encoded;
	}

	private static function nested_get( $root, array $keys, $default ) {
		$node = $root;
		foreach ( $keys as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return $default;
			}
			$node = $node[ $key ];
		}
		return $node;
	}

	private static function all_call_settings_match( array $calls ): bool {
		foreach ( $calls as $call ) {
			if ( empty( $call['sameSetting'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function all_specific_render_hooks_removed( array $callbacks ): bool {
		foreach ( $callbacks as $control_id => $callback ) {
			if ( false !== \has_action( "customize_render_control_{$control_id}", $callback ) ) {
				return false;
			}
		}
		return true;
	}

	private static function reset_runtime(): void {
		unset( $_POST['customized'], $_POST['customize_changeset_data'] );
		unset( $_REQUEST['customized'], $_REQUEST['customize_changeset_data'] );
		unset( $_GET['customize_theme'], $_GET['theme'] );
		unset( $_POST['customize_theme'], $_POST['theme'] );
		unset( $_REQUEST['customize_theme'], $_REQUEST['theme'] );

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array_merge(
					$options,
					array(
						'home'                                  => 'http://example.test',
						'siteurl'                               => 'http://example.test',
						'stylesheet'                            => 'component-fuzz-theme',
						'template'                              => 'component-fuzz-theme',
						'current_theme'                         => 'Component Fuzz Theme',
						'theme_mods_component-fuzz-theme'       => array(),
						'customize_stashed_theme_mods'          => array(),
						'can_compress_scripts'                  => '0',
						'page_for_posts'                        => '0',
						'page_on_front'                         => '0',
						'show_on_front'                         => 'posts',
						'fresh_site'                            => '0',
						'widget_block'                          => array(),
						'sidebars_widgets'                      => array(),
						'nav_menu_options'                      => array(),
						'theme_switched_via_customizer'         => false,
						'dismissed_update_core'                 => array(),
						'auto_update_core_major'                => 'unset',
						'auto_update_core_minor'                => 'unset',
						'auto_update_core_dev'                  => 'unset',
						'wp_force_deactivated_plugins'          => array(),
						'wp_force_deactivated_plugins_changed'  => false,
						'wp_force_deactivated_plugins_baseline' => array(),
					)
				)
			);
		}

		if ( method_exists( 'WP_Customize_Setting', 'reset_aggregated_multidimensionals' ) ) {
			\WP_Customize_Setting::reset_aggregated_multidimensionals();
		}
	}

	private static function snapshot_state(): array {
		return array(
			'globals'             => self::snapshot_globals(
				array(
					'_GET',
					'_POST',
					'_REQUEST',
					'current_user',
					'wp_actions',
					'wp_current_filter',
					'wp_customize',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_stylesheet_path',
					'wp_template_path',
					'wp_theme_directories',
				)
			),
			'options'             => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
			'aggregatedSettings'  => self::get_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals' ),
			'controlCount'        => self::get_static_property( 'WP_Customize_Control', 'instance_count' ),
			'sectionCount'        => self::get_static_property( 'WP_Customize_Section', 'instance_count' ),
			'panelCount'          => self::get_static_property( 'WP_Customize_Panel', 'instance_count' ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		self::set_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals', $snapshot['aggregatedSettings'] );
		self::set_static_property( 'WP_Customize_Control', 'instance_count', $snapshot['controlCount'] );
		self::set_static_property( 'WP_Customize_Section', 'instance_count', $snapshot['sectionCount'] );
		self::set_static_property( 'WP_Customize_Panel', 'instance_count', $snapshot['panelCount'] );
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

	private static function set_object_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		self::make_reflection_accessible( $reflection );
		$reflection->setValue( $object, $value );
	}

	private static function make_reflection_accessible( \ReflectionProperty $reflection ): void {
		if ( PHP_VERSION_ID < 80100 && method_exists( $reflection, 'setAccessible' ) ) {
			$reflection->setAccessible( true );
		}
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

	private static function describe_throwable( \Throwable $throwable ): array {
		return array(
			'class'   => get_class( $throwable ),
			'message' => $throwable->getMessage(),
			'file'    => $throwable->getFile(),
			'line'    => $throwable->getLine(),
		);
	}

	private static function describe_string( string $value ): string {
		return \ComponentFuzz\preview_value( $value );
	}

	private static function describe_value( $value ) {
		if ( $value instanceof \WP_Error ) {
			return array(
				'wpErrorCodes' => $value->get_error_codes(),
				'wpErrorData'  => $value->get_all_error_data(),
			);
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
			return \ComponentFuzz\preview_value( $value );
		}

		return $value;
	}
}
