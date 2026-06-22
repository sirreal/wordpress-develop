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
			$rows[] = self::check_multidimensional_values( $ctx->fork( 'multidimensional' ) );
			$rows[] = self::check_json_and_active_callbacks( $ctx->fork( 'json-active' ) );
			$rows[] = self::check_selective_refresh_partials( $ctx->fork( 'partials' ) );
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
				'add_filter',
				'apply_filters',
				'current_user_can',
				'esc_attr',
				'get_option',
				'has_filter',
				'is_wp_error',
				'remove_filter',
				'update_option',
				'wp_json_encode',
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

	private static function manager( \ComponentFuzz\FuzzContext $ctx ): \WP_Customize_Manager {
		$components_filter = static function (): array {
			return array();
		};
		\add_filter( 'customize_loaded_components', $components_filter, 1000 );
		try {
			$manager = new \WP_Customize_Manager(
				array(
					'changeset_uuid'     => self::uuid( $ctx ),
					'settings_previewed' => false,
					'branching'          => true,
					'autosaved'          => false,
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

	private static function reset_runtime(): void {
		unset( $_POST['customized'], $_POST['customize_changeset_data'] );
		unset( $_REQUEST['customized'], $_REQUEST['customize_changeset_data'] );

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
					'_POST',
					'_REQUEST',
					'current_user',
					'wp_actions',
					'wp_current_filter',
					'wp_customize',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
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
