<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes bounded WP_Debug_Data formatting and database-size helpers.
 */
final class SiteHealthDebugSurface {
	public const NAME = 'site-health-debug';

	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::maybe_load_debug_data();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'site-health-debug.bootstrap-apis-available',
					'Required WP_Debug_Data APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = $ctx->pass(
				'site-health-debug.bootstrap-wp-debug-data-loaded',
				array( 'class' => \WP_Debug_Data::class )
			);
			$rows[] = self::check_format( $ctx->fork( 'format' ) );
			$rows[] = self::check_debug_information_filter_locality( $ctx->fork( 'debug-information' ) );
			$rows[] = self::check_database_size( $ctx->fork( 'database-size' ) );
			$rows[] = self::check_database_size_malformed_rows( $ctx->fork( 'database-size-malformed' ) );
			$rows[] = self::check_sizes( $ctx->fork( 'sizes' ) );
			$rows[] = self::check_mysql_var( $ctx->fork( 'mysql-var' ) );
			$rows[] = self::check_debug_data_full_scan_child( $ctx->fork( 'debug-data-full-scan' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'site-health-debug.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'site-health-debug.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'obLevel'        => ob_get_level(),
				'options'        => self::wpdb_options(),
				'superglobals'   => array_keys( $snapshot['superglobals'] ),
			)
		);

		return $rows;
	}

	private static function maybe_load_debug_data(): void {
		if ( class_exists( 'WP_Debug_Data', false ) ) {
			return;
		}

		$path = null;
		if ( defined( 'ABSPATH' ) ) {
			$path = ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}

		if ( null === $path || ! file_exists( $path ) ) {
			$path = \ComponentFuzz\repo_root()
				. DIRECTORY_SEPARATOR . 'src'
				. DIRECTORY_SEPARATOR . 'wp-admin'
				. DIRECTORY_SEPARATOR . 'includes'
				. DIRECTORY_SEPARATOR . 'class-wp-debug-data.php';
		}

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Debug_Data', 'WP_Hook' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach ( array( 'add_filter', 'apply_filters', 'get_theme_root', 'has_filter', 'remove_filter', 'size_format', 'untrailingslashit', 'wp_cache_delete', 'wp_get_font_dir', 'wp_get_upload_dir', 'wp_upload_dir', 'wp_using_ext_object_cache' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'ARRAY_A' ) ) {
			$missing[] = 'constant ARRAY_A';
		}
		if ( ! defined( 'WP_START_TIMESTAMP' ) ) {
			$missing[] = 'constant WP_START_TIMESTAMP';
		}

		return $missing;
	}

	private static function check_format( \ComponentFuzz\FuzzContext $ctx ): array {
		$case           = self::format_case( $ctx );
		$info_array     = $case['info'];
		$actual_debug   = \WP_Debug_Data::format( $info_array, 'debug' );
		$actual_info    = \WP_Debug_Data::format( $info_array, 'info' );
		$expected_debug = self::expected_format( $info_array, 'debug' );
		$expected_info  = self::expected_format( $info_array, 'info' );
		$failures       = array();

		self::collect_failure(
			$failures,
			$expected_debug === $actual_debug,
			'debug output matches the expected section-id/field-id formatting oracle',
			array(
				'expected' => self::describe_string( $expected_debug ),
				'actual'   => self::describe_string( $actual_debug ),
			)
		);
		self::collect_failure(
			$failures,
			$expected_info === $actual_info,
			'info output matches the expected section-label/field-label formatting oracle',
			array(
				'expected' => self::describe_string( $expected_info ),
				'actual'   => self::describe_string( $actual_info ),
			)
		);
		self::collect_failure(
			$failures,
			self::is_backtick_wrapped( $actual_debug ) && self::is_backtick_wrapped( $actual_info ),
			'formatted output is wrapped in a single outer backtick block',
			array(
				'debugStart' => substr( $actual_debug, 0, 2 ),
				'debugEnd'   => substr( $actual_debug, -1 ),
				'infoStart'  => substr( $actual_info, 0, 2 ),
				'infoEnd'    => substr( $actual_info, -1 ),
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $actual_debug, '### ' . $case['mainSectionId'] . ' (' . $case['mainFieldCount'] . ") ###\n\n" )
				&& str_contains( $actual_info, '### ' . $case['mainSectionLabel'] . ' (' . $case['mainFieldCount'] . ") ###\n\n" ),
			'show_count uses the section field count in both debug and info formats',
			array(
				'mainFieldCount' => $case['mainFieldCount'],
				'debugPreview'   => self::describe_string( $actual_debug ),
				'infoPreview'    => self::describe_string( $actual_info ),
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $actual_debug, $case['mainFieldId'] . ': ' . $case['mainDebugValue'] . "\n" )
				&& ! str_contains( $actual_debug, $case['mainFieldLabel'] . ':' )
				&& str_contains( $actual_info, $case['mainFieldLabel'] . ': ' . $case['mainInfoValue'] . "\n" ),
			'debug output uses field IDs while info output uses field labels',
			array(
				'fieldId'    => $case['mainFieldId'],
				'fieldLabel' => $case['mainFieldLabel'],
			)
		);
		self::collect_failure(
			$failures,
			! str_contains( $actual_debug, $case['privateSentinel'] )
				&& ! str_contains( $actual_info, $case['privateSentinel'] ),
			'private sections and fields do not leak into formatted output',
			array( 'privateSentinelSha1' => sha1( $case['privateSentinel'] ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $actual_debug, "cfz_bool_true: true\n" )
				&& str_contains( $actual_debug, "cfz_bool_false: false\n" )
				&& str_contains( $actual_debug, "cfz_empty_string: undefined\n" )
				&& str_contains( $actual_debug, "cfz_zero_string: 0\n" )
				&& str_contains( $actual_debug, "cfz_array: \n\talpha: one\n\tzero: 0\n\tcount: 3\n" ),
			'booleans, arrays, empty strings, and string zero have stable debug formatting',
			array( 'debug' => self::describe_string( $actual_debug ) )
		);

		return $ctx->result(
			'site-health-debug.format.generated-section-field-tree',
			array() === $failures,
			array(
				'failures'       => array_slice( $failures, 0, 8 ),
				'debugSha1'      => sha1( $actual_debug ),
				'infoSha1'       => sha1( $actual_info ),
				'sections'       => count( $info_array ),
				'mainFieldCount' => $case['mainFieldCount'],
			)
		);
	}

	private static function check_debug_information_filter_locality( \ComponentFuzz\FuzzContext $ctx ): array {
		$base_section_id = 'cfz-filter-base-' . self::slug( $ctx->fork( 'base' ), 'base' );
		$custom_id       = 'cfz-filter-added-' . self::slug( $ctx->fork( 'custom' ), 'custom' );
		$custom_label    = 'Filtered Debug Section ' . self::safe_label( $ctx->fork( 'custom-label' ) );
		$private_value   = 'cfz-filter-private-' . $ctx->seed();
		$base            = array(
			$base_section_id => array(
				'label'  => 'Base Debug Section',
				'fields' => array(
					'alpha' => array(
						'label' => 'Alpha Label',
						'value' => 'before-value',
						'debug' => 'before-debug',
					),
				),
			),
		);

		$add_section = static function ( array $info ) use ( $base_section_id, $custom_id, $custom_label, $private_value ): array {
			$info[ $base_section_id ]['fields']['alpha']['value'] = 'after-value';
			$info[ $base_section_id ]['fields']['alpha']['debug'] = 'after-debug';
			$info[ $custom_id ]                                   = array(
				'label'      => $custom_label,
				'show_count' => true,
				'fields'     => array(
					'custom_field' => array(
						'label' => 'Custom Field Label',
						'value' => 'custom visible value',
						'debug' => 'custom-debug-value',
					),
					'private_field' => array(
						'label'   => 'Private Custom Field',
						'value'   => $private_value,
						'debug'   => $private_value,
						'private' => true,
					),
				),
			);

			return $info;
		};
		$modify_section = static function ( array $info ) use ( $custom_id ): array {
			if ( isset( $info[ $custom_id ] ) ) {
				$info[ $custom_id ]['fields']['second_field'] = array(
					'label' => 'Second Field Label',
					'value' => 'second-info-value',
					'debug' => 'second-debug-value',
				);
			}

			return $info;
		};

		$before_hook = self::snapshot_filter_hook( 'debug_information' );
		$failures    = array();
		$debug       = '';
		$info_text   = '';
		$filtered    = array();
		$removed     = array(
			'add'    => false,
			'modify' => false,
		);

		try {
			\add_filter( 'debug_information', $add_section, 10, 1 );
			\add_filter( 'debug_information', $modify_section, 11, 1 );

			$filtered  = \apply_filters( 'debug_information', $base );
			$debug     = \WP_Debug_Data::format( $filtered, 'debug' );
			$info_text = \WP_Debug_Data::format( $filtered, 'info' );
		} finally {
			$removed['modify'] = \remove_filter( 'debug_information', $modify_section, 11 );
			$removed['add']    = \remove_filter( 'debug_information', $add_section, 10 );
		}

		$after_hook = self::snapshot_filter_hook( 'debug_information' );

		self::collect_failure(
			$failures,
			isset( $filtered[ $custom_id ], $filtered[ $base_section_id ] )
				&& 'after-debug' === ( $filtered[ $base_section_id ]['fields']['alpha']['debug'] ?? null )
				&& isset( $filtered[ $custom_id ]['fields']['second_field'] ),
			'debug_information filters can modify existing sections and add generated custom sections',
			array( 'filtered' => self::describe_value( $filtered ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $debug, '### ' . $custom_id . " (3) ###\n\n" )
				&& str_contains( $debug, "custom_field: custom-debug-value\n" )
				&& str_contains( $debug, "second_field: second-debug-value\n" )
				&& str_contains( $info_text, '### ' . $custom_label . " (3) ###\n\n" )
				&& str_contains( $info_text, "Custom Field Label: custom visible value\n" )
				&& str_contains( $info_text, "Second Field Label: second-info-value\n" ),
			'filtered custom sections format with debug IDs and info labels',
			array(
				'debug' => self::describe_string( $debug ),
				'info'  => self::describe_string( $info_text ),
			)
		);
		self::collect_failure(
			$failures,
			! str_contains( $debug, $private_value ) && ! str_contains( $info_text, $private_value ),
			'private fields added by debug_information filters do not leak',
			array( 'privateSha1' => sha1( $private_value ) )
		);
		self::collect_failure(
			$failures,
			$removed['add']
				&& $removed['modify']
				&& false === \has_filter( 'debug_information', $add_section )
				&& false === \has_filter( 'debug_information', $modify_section )
				&& self::filter_hooks_match( $before_hook, $after_hook ),
			'debug_information filter callbacks are removed and the hook is restored',
			array(
				'removed'          => $removed,
				'hadBeforeHook'    => null !== $before_hook,
				'hasAfterHook'     => null !== $after_hook,
				'afterHasAdd'      => \has_filter( 'debug_information', $add_section ),
				'afterHasModify'   => \has_filter( 'debug_information', $modify_section ),
				'hookStateMatches' => self::filter_hooks_match( $before_hook, $after_hook ),
			)
		);

		return $ctx->result(
			'site-health-debug.debug-information.filter-locality',
			array() === $failures,
			array(
				'failures'     => array_slice( $failures, 0, 6 ),
				'customId'     => $custom_id,
				'debugSha1'    => sha1( $debug ),
				'infoSha1'     => sha1( $info_text ),
				'removedHooks' => $removed,
			)
		);
	}

	private static function check_database_size( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$zero_db  = new SiteHealthDebugWpdbDouble( array() );
		$zero     = self::with_wpdb(
			$zero_db,
			static function (): int {
				return \WP_Debug_Data::get_database_size();
			}
		);

		self::collect_failure(
			$failures,
			0 === $zero
				&& 0 === $zero_db->num_rows
				&& 'SHOW TABLE STATUS' === $zero_db->last_query
				&& ARRAY_A === $zero_db->last_output
				&& $zero_db->restored,
			'zero SHOW TABLE STATUS rows produce a zero-byte database size and restore $wpdb',
			array(
				'result'     => $zero,
				'numRows'    => $zero_db->num_rows,
				'lastQuery'  => $zero_db->last_query,
				'lastOutput' => $zero_db->last_output,
				'restored'   => $zero_db->restored,
			)
		);

		$rows     = self::database_rows( $ctx->fork( 'rows' ) );
		$expected = 0;
		foreach ( $rows as $row ) {
			$expected += $row['Data_length'] + $row['Index_length'];
		}

		$sum_db = new SiteHealthDebugWpdbDouble( $rows );
		$sum    = self::with_wpdb(
			$sum_db,
			static function (): int {
				return \WP_Debug_Data::get_database_size();
			}
		);

		self::collect_failure(
			$failures,
			$expected === $sum
				&& count( $rows ) === $sum_db->num_rows
				&& 'SHOW TABLE STATUS' === $sum_db->last_query
				&& ARRAY_A === $sum_db->last_output
				&& $sum_db->restored,
			'generated Data_length and Index_length values are summed exactly and $wpdb is restored',
			array(
				'expected'   => $expected,
				'actual'     => $sum,
				'rowCount'   => count( $rows ),
				'lastQuery'  => $sum_db->last_query,
				'lastOutput' => $sum_db->last_output,
				'restored'   => $sum_db->restored,
				'rows'       => $rows,
			)
		);

		return $ctx->result(
			'site-health-debug.database-size.show-table-status',
			array() === $failures,
			array(
				'failures'      => array_slice( $failures, 0, 4 ),
				'generatedRows' => count( $rows ),
				'expectedSize'  => $expected,
			)
		);
	}

	private static function check_database_size_malformed_rows( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$complete = array(
			'Name'         => 'component_fuzz_complete',
			'Data_length'  => $ctx->int( 128, 65535 ),
			'Index_length' => $ctx->int( 64, 32767 ),
		);
		$rows     = array(
			$complete,
			array(
				'Name'        => 'component_fuzz_missing_index',
				'Data_length' => $ctx->int( 1, 4096 ),
			),
			array(
				'Name'         => 'component_fuzz_missing_data',
				'Index_length' => $ctx->int( 1, 4096 ),
			),
		);
		$expected_omission_size = $complete['Data_length'] + $complete['Index_length'];
		$malformed_db           = new SiteHealthDebugWpdbDouble( $rows );
		$actual                 = null;
		$throwable              = null;

		try {
			$actual = self::with_wpdb(
				$malformed_db,
				static function (): int {
					return \WP_Debug_Data::get_database_size();
				}
			);
		} catch ( \Throwable $e ) {
			$throwable = $e;
		}

		$query_shape_ok = count( $rows ) === $malformed_db->num_rows
			&& 'SHOW TABLE STATUS' === $malformed_db->last_query
			&& ARRAY_A === $malformed_db->last_output
			&& $malformed_db->restored;
		$current_strict_boundary = $throwable instanceof \Throwable
			&& $query_shape_ok
			&& str_contains( $throwable->getMessage(), 'array key' );
		$future_omission_boundary = null === $throwable
			&& $query_shape_ok
			&& $expected_omission_size === $actual;
		$mode = $current_strict_boundary ? 'strict-row-shape-throws' : ( $future_omission_boundary ? 'malformed-rows-omitted' : 'unexpected' );

		self::collect_failure(
			$failures,
			$current_strict_boundary || $future_omission_boundary,
			'malformed SHOW TABLE STATUS rows either hit the current strict row-shape boundary or are omitted without changing valid-row sums',
			array(
				'mode'                 => $mode,
				'expectedOmissionSize' => $expected_omission_size,
				'actual'               => $actual,
				'throwable'            => $throwable instanceof \Throwable ? self::describe_throwable( $throwable ) : null,
				'rowCount'             => $malformed_db->num_rows,
				'lastQuery'            => $malformed_db->last_query,
				'lastOutput'           => $malformed_db->last_output,
				'restored'             => $malformed_db->restored,
				'rows'                 => $rows,
			)
		);

		return $ctx->result(
			'site-health-debug.database-size.malformed-row-boundary-accounting',
			array() === $failures,
			array(
				'failures'             => array_slice( $failures, 0, 4 ),
				'mode'                 => $mode,
				'expectedOmissionSize' => $expected_omission_size,
				'notClaimed'           => array(
					'coercion of non-numeric SHOW TABLE STATUS size values',
					'inclusion of malformed rows as partial zero-filled rows',
				),
			)
		);
	}

	private static function check_sizes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		if ( ! function_exists( 'ini_set' ) ) {
			return $ctx->skip(
				'site-health-debug.sizes.directory-database-total',
				'Skipped deprecated get_sizes() directory aggregation because ini_set() is unavailable and the harness cannot raise max_execution_time for long all-surface runs.',
				array( 'elapsedSeconds' => self::elapsed_since_start() )
			);
		}

		\wp_upload_dir( null, false, true );
		\ComponentFuzz\ensure_dir( WP_CONTENT_DIR . '/uploads' );
		\ComponentFuzz\ensure_dir( WP_CONTENT_DIR . '/uploads/fonts' );

		$upload_dir = \wp_get_upload_dir();
		$font_dir   = \wp_get_font_dir();
		$paths      = array(
			'wordpress_size' => \untrailingslashit( ABSPATH ),
			'themes_size'    => \untrailingslashit( \get_theme_root() ),
			'plugins_size'   => \untrailingslashit( WP_PLUGIN_DIR ),
			'uploads_size'   => \untrailingslashit( $upload_dir['basedir'] ),
			'fonts_size'     => \untrailingslashit( $font_dir['basedir'] ),
		);
		$dir_sizes  = self::directory_sizes( $ctx->fork( 'directory-sizes' ), $paths );
		$db_rows    = self::database_rows( $ctx->fork( 'database-rows' ) );
		$db_size    = 0;

		foreach ( $db_rows as $row ) {
			$db_size += $row['Data_length'] + $row['Index_length'];
		}
		if ( 0 === $db_size ) {
			$db_rows[0]['Data_length'] = 1;
			$db_size                   = 1;
		}

		$size_calls       = array();
		$deprecated_calls = array();
		$size_filter      = static function ( $space_used, $directory, $exclude, $max_execution_time, $directory_cache ) use ( $dir_sizes, &$size_calls ) {
			$directory = \untrailingslashit( (string) $directory );
			$size_calls[] = array(
				'directory'        => $directory,
				'exclude'          => is_array( $exclude ) ? array_map( 'strval', $exclude ) : $exclude,
				'maxExecutionTime' => $max_execution_time,
				'cacheCount'       => is_array( $directory_cache ) ? count( $directory_cache ) : null,
			);

			return array_key_exists( $directory, $dir_sizes ) ? $dir_sizes[ $directory ] : $space_used;
		};
		$deprecated_hook  = static function ( string $function_name, string $replacement, string $version ) use ( &$deprecated_calls ): void {
			$deprecated_calls[] = array(
				'function'    => $function_name,
				'replacement' => $replacement,
				'version'     => $version,
			);
		};
		$wpdb             = new SiteHealthDebugWpdbDouble( $db_rows );
		$actual           = array();
		$previous_ext     = array(
			'exists' => array_key_exists( '_wp_using_ext_object_cache', $GLOBALS ),
			'value'  => $GLOBALS['_wp_using_ext_object_cache'] ?? null,
		);
		$previous_max_execution_time = self::raise_max_execution_time_for_size_scan();
		if ( false === $previous_max_execution_time ) {
			return $ctx->skip(
				'site-health-debug.sizes.directory-database-total',
				'Skipped deprecated get_sizes() directory aggregation because max_execution_time could not be raised for long all-surface runs.',
				array( 'elapsedSeconds' => self::elapsed_since_start() )
			);
		}
		$removed          = array(
			'size'       => false,
			'deprecated' => false,
		);

		\wp_using_ext_object_cache( true );
		\wp_cache_delete( 'dirsize_cache', 'transient' );

		try {
			\add_filter( 'pre_recurse_dirsize', $size_filter, 10, 5 );
			\add_filter( 'deprecated_function_run', $deprecated_hook, 10, 3 );

			$actual = self::with_wpdb(
				$wpdb,
				static function (): array {
					return \WP_Debug_Data::get_sizes();
				}
			);
		} finally {
			$removed['size']       = \remove_filter( 'pre_recurse_dirsize', $size_filter, 10 );
			$removed['deprecated'] = \remove_filter( 'deprecated_function_run', $deprecated_hook, 10 );
			\wp_cache_delete( 'dirsize_cache', 'transient' );
			self::restore_max_execution_time( $previous_max_execution_time );
			self::restore_ext_object_cache_flag( $previous_ext );
		}

		$expected_total = $db_size + array_sum( $dir_sizes );
		foreach ( $paths as $name => $path ) {
			$expected_size = \size_format( $dir_sizes[ $path ], 2 );
			self::collect_failure(
				$failures,
				isset( $actual[ $name ] )
					&& $path === ( $actual[ $name ]['path'] ?? null )
					&& $dir_sizes[ $path ] === ( $actual[ $name ]['raw'] ?? null )
					&& $expected_size === ( $actual[ $name ]['size'] ?? null )
					&& $expected_size . ' (' . $dir_sizes[ $path ] . ' bytes)' === ( $actual[ $name ]['debug'] ?? null ),
				"get_sizes returns generated {$name} path, raw bytes, display size, and debug size",
				array(
					'name'     => $name,
					'path'     => $path,
					'expected' => array(
						'raw'   => $dir_sizes[ $path ],
						'size'  => $expected_size,
						'debug' => $expected_size . ' (' . $dir_sizes[ $path ] . ' bytes)',
					),
					'actual'   => $actual[ $name ] ?? null,
				)
			);
		}

		$expected_db_size    = \size_format( $db_size, 2 );
		$expected_total_size = \size_format( $expected_total, 2 );
		self::collect_failure(
			$failures,
			isset( $actual['database_size'], $actual['total_size'] )
				&& $db_size === ( $actual['database_size']['raw'] ?? null )
				&& $expected_db_size === ( $actual['database_size']['size'] ?? null )
				&& $expected_db_size . ' (' . $db_size . ' bytes)' === ( $actual['database_size']['debug'] ?? null )
				&& $expected_total === ( $actual['total_size']['raw'] ?? null )
				&& $expected_total_size === ( $actual['total_size']['size'] ?? null )
				&& $expected_total_size . ' (' . $expected_total . ' bytes)' === ( $actual['total_size']['debug'] ?? null ),
			'get_sizes combines generated directory sizes with database size into total_size',
			array(
				'expectedDatabase' => array(
					'raw'   => $db_size,
					'size'  => $expected_db_size,
					'debug' => $expected_db_size . ' (' . $db_size . ' bytes)',
				),
				'expectedTotal'    => array(
					'raw'   => $expected_total,
					'size'  => $expected_total_size,
					'debug' => $expected_total_size . ' (' . $expected_total . ' bytes)',
				),
				'actualDatabase'   => $actual['database_size'] ?? null,
				'actualTotal'      => $actual['total_size'] ?? null,
			)
		);

		$wordpress_call = $size_calls[0] ?? array();
		$expected_exclude = array_values(
			array(
				$paths['themes_size'],
				$paths['plugins_size'],
				$paths['uploads_size'],
				$paths['fonts_size'],
			)
		);
		self::collect_failure(
			$failures,
			count( $paths ) === count( $size_calls )
				&& $paths['wordpress_size'] === ( $wordpress_call['directory'] ?? null )
				&& $expected_exclude === ( $wordpress_call['exclude'] ?? null )
				&& array() === array_diff( array_values( $paths ), array_column( $size_calls, 'directory' ) ),
			'get_sizes asks recurse_dirsize for each bounded install path and excludes nested content from ABSPATH',
			array(
				'paths'           => $paths,
				'sizeCalls'       => $size_calls,
				'expectedExclude' => $expected_exclude,
			)
		);
		self::collect_failure(
			$failures,
			$removed['size']
				&& $removed['deprecated']
				&& false === \has_filter( 'pre_recurse_dirsize', $size_filter )
				&& false === \has_filter( 'deprecated_function_run', $deprecated_hook )
				&& array(
					array(
						'function'    => 'WP_Debug_Data::get_sizes',
						'replacement' => 'WP_REST_Site_Health_Controller::get_directory_sizes()',
						'version'     => '5.6.0',
					),
				) === $deprecated_calls
				&& $wpdb->restored
				&& self::ext_object_cache_flag_matches( $previous_ext )
				&& self::max_execution_time_matches( $previous_max_execution_time ),
			'get_sizes emits its deprecation hook once and temporary filters plus $wpdb are restored',
			array(
				'removed'          => $removed,
				'deprecatedCalls'  => $deprecated_calls,
				'afterSizeFilter'  => \has_filter( 'pre_recurse_dirsize', $size_filter ),
				'afterDeprecated'  => \has_filter( 'deprecated_function_run', $deprecated_hook ),
				'wpdbRestored'     => $wpdb->restored,
				'extObjectCache'   => array(
					'previous' => $previous_ext,
					'current'  => array(
						'exists' => array_key_exists( '_wp_using_ext_object_cache', $GLOBALS ),
						'value'  => $GLOBALS['_wp_using_ext_object_cache'] ?? null,
					),
				),
				'maxExecutionTime' => array(
					'previous' => $previous_max_execution_time,
					'current'  => function_exists( 'ini_get' ) ? ini_get( 'max_execution_time' ) : null,
				),
				'databaseLastCall' => array(
					'query'  => $wpdb->last_query,
					'output' => $wpdb->last_output,
				),
			)
		);

		return $ctx->result(
			'site-health-debug.sizes.directory-database-total',
			array() === $failures,
			array(
				'failures'      => array_slice( $failures, 0, 8 ),
				'directoryRaw'  => $dir_sizes,
				'databaseRaw'   => $db_size,
				'totalRaw'      => $expected_total,
				'pathCount'     => count( $paths ),
				'sizeCallCount' => count( $size_calls ),
			)
		);
	}

	private static function check_mysql_var( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$available_var = 'cfz_' . self::slug( $ctx->fork( 'available' ), 'available' );
		$missing_var   = 'cfz_' . self::slug( $ctx->fork( 'missing' ), 'missing' );
		$malformed_var = 'cfz_' . self::slug( $ctx->fork( 'malformed' ), 'malformed' );
		$available     = self::mysql_var_value( $ctx->fork( 'value' ) );
		$wpdb          = new SiteHealthDebugWpdbDouble(
			array(),
			array(
				$available_var => $available,
				$malformed_var => array(
					'Variable_name' => $malformed_var,
					'NotValue'      => 'missing value column',
				),
			)
		);
		$observed      = self::with_wpdb(
			$wpdb,
			static function () use ( $available_var, $missing_var, $malformed_var ): array {
				return array(
					'available' => \WP_Debug_Data::get_mysql_var( $available_var ),
					'missing'   => \WP_Debug_Data::get_mysql_var( $missing_var ),
					'malformed' => \WP_Debug_Data::get_mysql_var( $malformed_var ),
				);
			}
		);

		self::collect_failure(
			$failures,
			$available === $observed['available']
				&& null === $observed['missing']
				&& null === $observed['malformed'],
			'get_mysql_var returns only populated Value columns and null for missing or malformed rows',
			array(
				'availableVar' => $available_var,
				'expected'     => $available,
				'observed'     => $observed,
			)
		);
		self::collect_failure(
			$failures,
			array(
				array(
					'query' => 'SHOW VARIABLES LIKE %s',
					'args'  => array( $available_var ),
				),
				array(
					'query' => 'SHOW VARIABLES LIKE %s',
					'args'  => array( $missing_var ),
				),
				array(
					'query' => 'SHOW VARIABLES LIKE %s',
					'args'  => array( $malformed_var ),
				),
			) === $wpdb->prepared_queries
				&& array( ARRAY_A, ARRAY_A, ARRAY_A ) === array_column( $wpdb->row_queries, 'output' )
				&& $wpdb->restored,
			'get_mysql_var prepares exact SHOW VARIABLES queries, requests ARRAY_A rows, and restores $wpdb',
			array(
				'prepared' => $wpdb->prepared_queries,
				'rows'     => $wpdb->row_queries,
				'restored' => $wpdb->restored,
			)
		);

		return $ctx->result(
			'site-health-debug.mysql-var.lookup-contract',
			array() === $failures,
			array(
				'failures'      => array_slice( $failures, 0, 4 ),
				'availableVar'  => $available_var,
				'missingVar'    => $missing_var,
				'malformedVar'  => $malformed_var,
				'preparedCount' => count( $wpdb->prepared_queries ),
			)
		);
	}

	private static function check_debug_data_full_scan_child( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = array();
		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}
		foreach ( array( 'file_put_contents', 'json_decode', 'json_encode', 'proc_close', 'proc_open', 'random_bytes', 'stream_get_contents' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( array() !== $missing ) {
			return $ctx->skip(
				'site-health-debug.debug-data.full-scan-bounded',
				'Skipped isolated WP_Debug_Data::debug_data() coverage because PHP subprocess APIs are unavailable.',
				array(
					'missing' => $missing,
				)
			);
		}

		$case = self::debug_data_child_case( $ctx );
		$run  = self::run_debug_data_child( $case );

		$failures = array();
		self::collect_failure(
			$failures,
			$run['ok'] && is_array( $run['result'] ) && ! empty( $run['result']['ok'] ),
			'isolated debug_data subprocess exits cleanly and returns structured JSON',
			array(
				'exitCode' => $run['exitCode'],
				'stdout'   => self::describe_string( $run['stdout'] ),
				'stderr'   => self::describe_string( $run['stderr'] ),
				'result'   => $run['result'],
			)
		);

		$result = is_array( $run['result'] ) ? $run['result'] : array();
		$sections = is_array( $result['sections'] ?? null ) ? $result['sections'] : array();
		$field_counts = is_array( $result['fieldCounts'] ?? null ) ? $result['fieldCounts'] : array();
		$expected_sections = array(
			'wp-core',
			'wp-paths-sizes',
			'wp-dropins',
			'wp-active-theme',
			'wp-parent-theme',
			'wp-themes-inactive',
			'wp-mu-plugins',
			'wp-plugins-active',
			'wp-plugins-inactive',
			'wp-media',
			'wp-server',
			'wp-database',
			'wp-constants',
			'wp-filesystem',
			$case['customSectionId'],
		);
		$expected_empty_sections = array(
			'wp-dropins',
			'wp-parent-theme',
			'wp-themes-inactive',
			'wp-mu-plugins',
			'wp-plugins-active',
			'wp-plugins-inactive',
		);
		$empty_section_counts_ok = true;
		foreach ( $expected_empty_sections as $section ) {
			$empty_section_counts_ok = $empty_section_counts_ok && 0 === ( $field_counts[ $section ] ?? null );
		}

		self::collect_failure(
			$failures,
			$expected_sections === $sections
				&& ( $field_counts['wp-core'] ?? 0 ) >= 12
				&& ( $field_counts['wp-media'] ?? 0 ) >= 8
				&& 11 === ( $field_counts['wp-database'] ?? null )
				&& ( $field_counts['wp-constants'] ?? 0 ) >= 18
				&& ( $field_counts[ $case['customSectionId'] ] ?? null ) === 2
				&& $empty_section_counts_ok,
			'debug_data returns expected single-site core sections, empty plugin/theme buckets, and generated debug_information section',
			array(
				'expectedSections'      => $expected_sections,
				'sections'              => $sections,
				'fieldCounts'           => $field_counts,
				'expectedEmptySections' => $expected_empty_sections,
			)
		);

		$network_requests = is_array( $result['networkRequests'] ?? null ) ? $result['networkRequests'] : array();
		self::collect_failure(
			$failures,
			'true' === ( $result['core']['dotorgDebug'] ?? null )
				&& count( $network_requests ) >= 1
				&& in_array( 'https://wordpress.org', array_column( $network_requests, 'url' ), true ),
			'full debug data records a bounded successful WordPress.org communication field through pre_http_request',
			array(
				'core'            => $result['core'] ?? null,
				'networkRequests' => $network_requests,
			)
		);

		$paths = is_array( $result['paths'] ?? null ) ? $result['paths'] : array();
		$loading_size_fields = array( 'wordpress_size', 'uploads_size', 'themes_size', 'plugins_size', 'fonts_size', 'database_size', 'total_size' );
		$loading_fields_ok = true;
		foreach ( $loading_size_fields as $field ) {
			$loading_fields_ok = $loading_fields_ok
				&& 'loading...' === ( $paths[ $field ]['debug'] ?? null )
				&& is_string( $paths[ $field ]['value'] ?? null );
		}
		self::collect_failure(
			$failures,
			$loading_fields_ok
				&& is_string( $paths['wordpress_path']['value'] ?? null )
				&& is_string( $paths['uploads_path']['value'] ?? null )
				&& is_string( $paths['themes_path']['value'] ?? null )
				&& is_string( $paths['plugins_path']['value'] ?? null )
				&& is_string( $paths['fonts_path']['value'] ?? null ),
			'paths and sizes section exposes bounded paths and loading placeholders rather than scanning directories',
			array(
				'paths'             => $paths,
				'loadingSizeFields' => $loading_size_fields,
			)
		);

		self::collect_failure(
			$failures,
			$case['ghostscriptVersion'] === ( $result['media']['ghostscriptDebug'] ?? null )
				&& $case['serverVersion'] === ( $result['database']['serverVersion'] ?? null )
				&& $case['clientVersion'] === ( $result['database']['clientVersion'] ?? null )
				&& $case['maxAllowedPacket'] === ( $result['database']['maxAllowedPacket'] ?? null )
				&& $case['maxConnections'] === ( $result['database']['maxConnections'] ?? null ),
			'generated Ghostscript and database fixture values are reflected in media/database sections',
			array(
				'expected' => array(
					'ghostscript'       => $case['ghostscriptVersion'],
					'serverVersion'     => $case['serverVersion'],
					'clientVersion'     => $case['clientVersion'],
					'maxAllowedPacket'  => $case['maxAllowedPacket'],
					'maxConnections'    => $case['maxConnections'],
				),
				'media'    => $result['media'] ?? null,
				'database' => $result['database'] ?? null,
			)
		);

		$format = is_array( $result['format'] ?? null ) ? $result['format'] : array();
		self::collect_failure(
			$failures,
			! empty( $format['customDebugPresent'] )
				&& ! empty( $format['customInfoPresent'] )
				&& empty( $format['privateLeak'] )
				&& empty( $format['privateDatabaseLeak'] )
				&& is_string( $format['debugSha1'] ?? null )
				&& is_string( $format['infoSha1'] ?? null ),
			'formatted full debug_data output includes generated public fields and omits private fields',
			array( 'format' => $format )
		);

		self::collect_failure(
			$failures,
			'' === ( $result['unexpectedOutput'] ?? null )
				&& '' === $run['stderr']
				&& ! empty( $result['filtersRemoved'] )
				&& ! empty( $result['fakeBinRemoved'] )
				&& ! empty( $result['pathRestored'] ),
			'child process cleans temporary hooks, fake Ghostscript path, and produces no stray output',
			array(
				'unexpectedOutput' => $result['unexpectedOutput'] ?? null,
				'stderr'           => self::describe_string( $run['stderr'] ),
				'filtersRemoved'   => $result['filtersRemoved'] ?? null,
				'fakeBinRemoved'   => $result['fakeBinRemoved'] ?? null,
				'pathRestored'     => $result['pathRestored'] ?? null,
			)
		);

		return $ctx->result(
			'site-health-debug.debug-data.full-scan-bounded',
			array() === $failures,
			array(
				'failures'       => array_slice( $failures, 0, 8 ),
				'sectionCount'   => count( $sections ),
				'fieldCounts'    => $field_counts,
				'networkCount'   => count( $network_requests ),
				'customSection'  => $case['customSectionId'],
				'debugSha1'      => $format['debugSha1'] ?? null,
				'infoSha1'       => $format['infoSha1'] ?? null,
			)
		);
	}

	private static function debug_data_child_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::slug( $ctx->fork( 'token' ), 'fullscan' );

		return array(
			'repoRoot'            => \ComponentFuzz\repo_root(),
			'token'               => $token,
			'networkBody'         => 'component-fuzz-wordpress-org-ok-' . $token,
			'ghostscriptVersion'  => '9.' . $ctx->int( 10, 99 ) . '.' . $ctx->int( 0, 9 ),
			'serverVersion'       => '8.0.' . $ctx->int( 20, 99 ) . '-component-fuzz',
			'clientVersion'       => 'mysqlnd-component-fuzz-' . $ctx->int( 1, 99 ),
			'dbUser'              => 'cfz_user_' . $token,
			'dbHost'              => 'db-' . $token . '.example.test',
			'dbName'              => 'cfz_db_' . $token,
			'dbCollate'           => 'utf8mb4_unicode_ci',
			'maxAllowedPacket'    => (string) $ctx->int( 1048576, 16777216 ),
			'maxConnections'      => (string) $ctx->int( 50, 500 ),
			'customSectionId'     => 'cfz-site-health-debug-' . $token,
			'customSectionLabel'  => 'Component Fuzz Debug ' . self::safe_label( $ctx->fork( 'section-label' ) ),
			'customFieldId'       => 'cfz_full_scan_' . str_replace( '-', '_', $token ),
			'customFieldLabel'    => 'Full Scan Field ' . self::safe_label( $ctx->fork( 'field-label' ) ),
			'customValue'         => 'visible-info-' . self::safe_label( $ctx->fork( 'value' ) ),
			'customDebug'         => 'visible-debug-' . self::safe_label( $ctx->fork( 'debug' ) ),
			'privateSentinel'     => 'cfz-private-full-scan-' . sha1( (string) $ctx->seed() ),
		);
	}

	private static function run_debug_data_child( array $case ): array {
		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-site-health-debug';
		\ComponentFuzz\ensure_dir( $dir );

		$script = $dir . DIRECTORY_SEPARATOR . 'debug-data-child-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) ) . '.php';
		file_put_contents( $script, self::debug_data_child_program() );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates full WP_Debug_Data::debug_data() discovery in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			@unlink( $script );
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		$payload = json_encode( $case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		fwrite( $pipes[0], false === $payload ? '{}' : $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		@unlink( $script );

		$result = json_decode( (string) $stdout, true );
		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && ! empty( $result['ok'] ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function debug_data_child_program(): string {
		return <<<'PHP'
<?php
ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

function component_fuzz_site_health_debug_preview( string $value, int $limit = 240 ): string {
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

function component_fuzz_site_health_debug_remove_dir( string $dir ): bool {
	if ( ! is_dir( $dir ) ) {
		return ! file_exists( $dir );
	}

	$items = scandir( $dir );
	if ( false === $items ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) ) {
			if ( ! component_fuzz_site_health_debug_remove_dir( $path ) ) {
				return false;
			}
		} elseif ( ! @unlink( $path ) ) {
			return false;
		}
	}

	return @rmdir( $dir );
}

$component_fuzz_debug_outer_ob_level = ob_get_level();
ob_start();

$component_fuzz_debug_raw     = stream_get_contents( STDIN );
$component_fuzz_debug_fixture = json_decode( $component_fuzz_debug_raw, true );
$component_fuzz_debug_result  = array(
	'ok'               => false,
	'sections'         => array(),
	'fieldCounts'      => array(),
	'networkRequests'  => array(),
	'core'             => array(),
	'media'            => array(),
	'database'         => array(),
	'paths'            => array(),
	'format'           => array(),
	'unexpectedOutput' => '',
	'filtersRemoved'   => false,
	'fakeBinRemoved'   => false,
	'pathRestored'     => false,
);
$component_fuzz_debug_original_path = getenv( 'PATH' );
$component_fuzz_debug_fake_bin      = null;
$component_fuzz_debug_network_filter = null;
$component_fuzz_debug_info_filter    = null;

try {
	if ( ! is_array( $component_fuzz_debug_fixture ) || empty( $component_fuzz_debug_fixture['repoRoot'] ) ) {
		throw new RuntimeException( 'Invalid Site Health debug_data fixture.' );
	}

	if ( ! defined( 'WP_CACHE' ) ) {
		define( 'WP_CACHE', false );
	}

	require_once $component_fuzz_debug_fixture['repoRoot'] . '/tools/component-fuzz/lib/autoload.php';

	\ComponentFuzz\WpBootstrap::load();

	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';

	if ( ! class_exists( 'Component_Fuzz_Site_Health_Debug_WPDB_Double', false ) ) {
		class Component_Fuzz_Site_Health_Debug_WPDB_Double extends Component_Fuzz_WPDB_Stub {
			public $dbh;
			public $dbuser;
			public $dbhost;
			public $dbname;
			public $collate;
			public array $query_log = array();

			private array $fixture;

			public function __construct( array $fixture ) {
				$this->fixture = $fixture;
				parent::__construct();

				$this->dbh     = (object) array( 'client_info' => (string) $fixture['clientVersion'] );
				$this->dbuser  = (string) $fixture['dbUser'];
				$this->dbhost  = (string) $fixture['dbHost'];
				$this->dbname  = (string) $fixture['dbName'];
				$this->collate = (string) $fixture['dbCollate'];
			}

			public function get_var( $query = null, $x = 0, $y = 0 ) {
				$query = (string) $query;
				$this->query_log[] = array(
					'method' => 'get_var',
					'query'  => $query,
				);

				if ( 'SELECT VERSION()' === $query ) {
					return (string) $this->fixture['serverVersion'];
				}

				return parent::get_var( $query, $x, $y );
			}

			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				$query = (string) $query;
				$this->query_log[] = array(
					'method' => 'get_row',
					'query'  => $query,
					'output' => $output,
				);

				$prefix = 'SHOW VARIABLES LIKE ';
				if ( str_starts_with( $query, $prefix ) ) {
					$name = trim( substr( $query, strlen( $prefix ) ), "'\"" );
					if ( 'max_allowed_packet' === $name ) {
						return array(
							'Variable_name' => $name,
							'Value'         => (string) $this->fixture['maxAllowedPacket'],
						);
					}
					if ( 'max_connections' === $name ) {
						return array(
							'Variable_name' => $name,
							'Value'         => (string) $this->fixture['maxConnections'],
						);
					}

					return null;
				}

				return parent::get_row( $query, $output, $y );
			}
		}
	}

	$GLOBALS['wpdb'] = new Component_Fuzz_Site_Health_Debug_WPDB_Double( $component_fuzz_debug_fixture );

	$component_fuzz_debug_fake_bin = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-site-health-debug-bin-' . getmypid();
	if ( ! is_dir( $component_fuzz_debug_fake_bin ) && ! mkdir( $component_fuzz_debug_fake_bin, 0700, true ) ) {
		throw new RuntimeException( 'Could not create fake Ghostscript bin directory.' );
	}

	$component_fuzz_debug_gs = $component_fuzz_debug_fake_bin . DIRECTORY_SEPARATOR . 'gs';
	$component_fuzz_debug_gs_script = "#!/bin/sh\nprintf '%s\n' " . escapeshellarg( (string) $component_fuzz_debug_fixture['ghostscriptVersion'] ) . "\n";
	if ( false === file_put_contents( $component_fuzz_debug_gs, $component_fuzz_debug_gs_script ) || ! chmod( $component_fuzz_debug_gs, 0700 ) ) {
		throw new RuntimeException( 'Could not create fake Ghostscript executable.' );
	}

	$component_fuzz_debug_path_suffix = false === $component_fuzz_debug_original_path || '' === $component_fuzz_debug_original_path
		? ''
		: PATH_SEPARATOR . $component_fuzz_debug_original_path;
	putenv( 'PATH=' . $component_fuzz_debug_fake_bin . $component_fuzz_debug_path_suffix );

	$component_fuzz_debug_network_filter = static function ( $preempt, array $parsed_args, string $url ) use ( &$component_fuzz_debug_result, $component_fuzz_debug_fixture ) {
		unset( $preempt );

		$component_fuzz_debug_result['networkRequests'][] = array(
			'url'     => $url,
			'timeout' => $parsed_args['timeout'] ?? null,
		);

		return array(
			'headers'  => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'     => (string) $component_fuzz_debug_fixture['networkBody'],
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$component_fuzz_debug_info_filter = static function ( array $info ) use ( $component_fuzz_debug_fixture ): array {
		$info[ $component_fuzz_debug_fixture['customSectionId'] ] = array(
			'label'      => (string) $component_fuzz_debug_fixture['customSectionLabel'],
			'show_count' => true,
			'fields'     => array(
				$component_fuzz_debug_fixture['customFieldId'] => array(
					'label' => (string) $component_fuzz_debug_fixture['customFieldLabel'],
					'value' => (string) $component_fuzz_debug_fixture['customValue'],
					'debug' => (string) $component_fuzz_debug_fixture['customDebug'],
				),
				'cfz_private_full_scan' => array(
					'label'   => 'Private Full Scan',
					'value'   => (string) $component_fuzz_debug_fixture['privateSentinel'],
					'debug'   => (string) $component_fuzz_debug_fixture['privateSentinel'],
					'private' => true,
				),
			),
		);

		return $info;
	};

	add_filter( 'pre_http_request', $component_fuzz_debug_network_filter, 10, 3 );
	add_filter( 'debug_information', $component_fuzz_debug_info_filter, 10, 1 );

	$component_fuzz_debug_info  = WP_Debug_Data::debug_data();
	$component_fuzz_debug_debug = WP_Debug_Data::format( $component_fuzz_debug_info, 'debug' );
	$component_fuzz_debug_info_text = WP_Debug_Data::format( $component_fuzz_debug_info, 'info' );

	$component_fuzz_debug_result['sections'] = array_keys( $component_fuzz_debug_info );
	foreach ( $component_fuzz_debug_info as $component_fuzz_debug_section => $component_fuzz_debug_details ) {
		$component_fuzz_debug_result['fieldCounts'][ $component_fuzz_debug_section ] = isset( $component_fuzz_debug_details['fields'] ) && is_array( $component_fuzz_debug_details['fields'] )
			? count( $component_fuzz_debug_details['fields'] )
			: null;
	}

	$component_fuzz_debug_result['core'] = array(
		'dotorgDebug' => $component_fuzz_debug_info['wp-core']['fields']['dotorg_communication']['debug'] ?? null,
		'dotorgValue' => $component_fuzz_debug_info['wp-core']['fields']['dotorg_communication']['value'] ?? null,
	);
	$component_fuzz_debug_result['media'] = array(
		'ghostscriptDebug' => $component_fuzz_debug_info['wp-media']['fields']['ghostscript_version']['debug'] ?? null,
		'ghostscriptValue' => $component_fuzz_debug_info['wp-media']['fields']['ghostscript_version']['value'] ?? null,
	);
	$component_fuzz_debug_path_fields = array(
		'wordpress_path',
		'wordpress_size',
		'uploads_path',
		'uploads_size',
		'themes_path',
		'themes_size',
		'plugins_path',
		'plugins_size',
		'fonts_path',
		'fonts_size',
		'database_size',
		'total_size',
	);
	foreach ( $component_fuzz_debug_path_fields as $component_fuzz_debug_path_field ) {
		$component_fuzz_debug_result['paths'][ $component_fuzz_debug_path_field ] = array(
			'value' => $component_fuzz_debug_info['wp-paths-sizes']['fields'][ $component_fuzz_debug_path_field ]['value'] ?? null,
			'debug' => $component_fuzz_debug_info['wp-paths-sizes']['fields'][ $component_fuzz_debug_path_field ]['debug'] ?? null,
		);
	}
	$component_fuzz_debug_result['database'] = array(
		'serverVersion'    => $component_fuzz_debug_info['wp-database']['fields']['server_version']['value'] ?? null,
		'clientVersion'    => $component_fuzz_debug_info['wp-database']['fields']['client_version']['value'] ?? null,
		'maxAllowedPacket' => $component_fuzz_debug_info['wp-database']['fields']['max_allowed_packet']['value'] ?? null,
		'maxConnections'   => $component_fuzz_debug_info['wp-database']['fields']['max_connections']['value'] ?? null,
		'queryLog'         => $GLOBALS['wpdb']->query_log,
	);
	$component_fuzz_debug_result['format'] = array(
		'debugSha1'             => sha1( $component_fuzz_debug_debug ),
		'infoSha1'              => sha1( $component_fuzz_debug_info_text ),
		'customDebugPresent'    => str_contains( $component_fuzz_debug_debug, (string) $component_fuzz_debug_fixture['customDebug'] ),
		'customInfoPresent'     => str_contains( $component_fuzz_debug_info_text, (string) $component_fuzz_debug_fixture['customValue'] ),
		'privateLeak'           => str_contains( $component_fuzz_debug_debug, (string) $component_fuzz_debug_fixture['privateSentinel'] )
			|| str_contains( $component_fuzz_debug_info_text, (string) $component_fuzz_debug_fixture['privateSentinel'] ),
		'privateDatabaseLeak'   => str_contains( $component_fuzz_debug_debug, (string) $component_fuzz_debug_fixture['dbUser'] )
			|| str_contains( $component_fuzz_debug_debug, (string) $component_fuzz_debug_fixture['dbHost'] )
			|| str_contains( $component_fuzz_debug_debug, (string) $component_fuzz_debug_fixture['dbName'] ),
	);

	$component_fuzz_debug_result['ok'] = true;
} catch ( Throwable $e ) {
	$component_fuzz_debug_result['throwable'] = array(
		'class'   => get_class( $e ),
		'message' => $e->getMessage(),
		'file'    => $e->getFile(),
		'line'    => $e->getLine(),
	);
} finally {
	if ( null !== $component_fuzz_debug_info_filter ) {
		$removed_info = remove_filter( 'debug_information', $component_fuzz_debug_info_filter, 10 );
	} else {
		$removed_info = true;
	}
	if ( null !== $component_fuzz_debug_network_filter ) {
		$removed_network = remove_filter( 'pre_http_request', $component_fuzz_debug_network_filter, 10 );
	} else {
		$removed_network = true;
	}

	$component_fuzz_debug_result['filtersRemoved'] = $removed_info && $removed_network;

	if ( false === $component_fuzz_debug_original_path ) {
		putenv( 'PATH' );
		$component_fuzz_debug_result['pathRestored'] = false === getenv( 'PATH' );
	} else {
		putenv( 'PATH=' . $component_fuzz_debug_original_path );
		$component_fuzz_debug_result['pathRestored'] = getenv( 'PATH' ) === $component_fuzz_debug_original_path;
	}

	if ( null !== $component_fuzz_debug_fake_bin ) {
		$component_fuzz_debug_result['fakeBinRemoved'] = component_fuzz_site_health_debug_remove_dir( $component_fuzz_debug_fake_bin );
	}

	while ( ob_get_level() > $component_fuzz_debug_outer_ob_level ) {
		$component_fuzz_debug_result['unexpectedOutput'] = ob_get_clean() . $component_fuzz_debug_result['unexpectedOutput'];
	}

	$component_fuzz_debug_result['unexpectedOutput'] = component_fuzz_site_health_debug_preview( (string) $component_fuzz_debug_result['unexpectedOutput'] );
}

$component_fuzz_debug_json = json_encode( $component_fuzz_debug_result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
echo false === $component_fuzz_debug_json ? '{"ok":false,"error":"json_encode failed"}' : $component_fuzz_debug_json;
exit( ! empty( $component_fuzz_debug_result['ok'] ) ? 0 : 1 );
PHP;
	}

	private static function format_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$private_sentinel = 'cfz-private-debug-data-' . $ctx->seed();
		$main_section_id  = 'cfz-section-' . self::slug( $ctx->fork( 'main-section-id' ), 'section' );
		$main_label       = 'Component Fuzz Section ' . self::safe_label( $ctx->fork( 'main-label' ) );
		$main_field_id    = 'cfz_generated_' . self::slug( $ctx->fork( 'main-field-id' ), 'field' );
		$main_field_label = 'Generated Field Label ' . self::safe_label( $ctx->fork( 'main-field-label' ) );
		$main_info_value  = 'info-value-' . self::safe_label( $ctx->fork( 'main-info' ) );
		$main_debug_value = 'debug-value-' . self::safe_label( $ctx->fork( 'main-debug' ) );
		$secondary_id     = 'cfz-section-' . self::slug( $ctx->fork( 'secondary-section-id' ), 'secondary' );

		$info = array(
			$main_section_id                  => array(
				'label'      => $main_label,
				'show_count' => true,
				'fields'     => array(
					$main_field_id      => array(
						'label' => $main_field_label,
						'value' => $main_info_value,
						'debug' => $main_debug_value,
					),
					'cfz_bool_true'     => array(
						'label' => 'Boolean True',
						'value' => true,
					),
					'cfz_bool_false'    => array(
						'label' => 'Boolean False',
						'value' => 'visible false fallback',
						'debug' => false,
					),
					'cfz_array'         => array(
						'label' => 'Array Value',
						'value' => array(
							'alpha' => 'one',
							'zero'  => '0',
							'count' => 3,
						),
					),
					'cfz_empty_string'  => array(
						'label' => 'Empty String',
						'value' => 'non-empty fallback',
						'debug' => '',
					),
					'cfz_zero_string'   => array(
						'label' => 'Zero String',
						'value' => '0',
					),
					'cfz_private_field' => array(
						'label'   => 'Private Field ' . $private_sentinel,
						'value'   => 'private value ' . $private_sentinel,
						'debug'   => 'private debug ' . $private_sentinel,
						'private' => true,
					),
				),
			),
			$secondary_id                     => array(
				'label'  => 'Secondary Section ' . self::safe_label( $ctx->fork( 'secondary-label' ) ),
				'fields' => array(
					'cfz_value_null'  => array(
						'label' => 'Null Value',
						'value' => null,
					),
					'cfz_value_zero'  => array(
						'label' => 'Integer Zero',
						'value' => 0,
					),
					'cfz_empty_array' => array(
						'label' => 'Empty Array',
						'value' => array(),
					),
					'cfz_generated'   => array(
						'label' => 'Generated Scalar',
						'value' => self::scalar_value( $ctx->fork( 'generated-scalar' ) ),
					),
				),
			),
			'cfz-empty-section'               => array(
				'label'  => 'Empty Section',
				'fields' => array(),
			),
			'cfz-private-section-' . $ctx->seed() => array(
				'label'   => 'Private Section ' . $private_sentinel,
				'private' => true,
				'fields'  => array(
					'secret' => array(
						'label' => 'Private Section Field',
						'value' => $private_sentinel,
					),
				),
			),
		);

		return array(
			'info'             => $info,
			'mainSectionId'    => $main_section_id,
			'mainSectionLabel' => $main_label,
			'mainFieldId'      => $main_field_id,
			'mainFieldLabel'   => $main_field_label,
			'mainInfoValue'    => $main_info_value,
			'mainDebugValue'   => $main_debug_value,
			'mainFieldCount'   => count( $info[ $main_section_id ]['fields'] ),
			'privateSentinel'  => $private_sentinel,
		);
	}

	private static function expected_format( array $info_array, string $data_type ): string {
		$return = "`\n";

		foreach ( $info_array as $section => $details ) {
			if ( empty( $details['fields'] ) || ( isset( $details['private'] ) && $details['private'] ) ) {
				continue;
			}

			$section_label = 'debug' === $data_type ? $section : $details['label'];
			$return       .= sprintf(
				"### %s%s ###\n\n",
				$section_label,
				( isset( $details['show_count'] ) && $details['show_count'] ? sprintf( ' (%d)', count( $details['fields'] ) ) : '' )
			);

			foreach ( $details['fields'] as $field_name => $field ) {
				if ( isset( $field['private'] ) && true === $field['private'] ) {
					continue;
				}

				if ( 'debug' === $data_type && isset( $field['debug'] ) ) {
					$debug_data = $field['debug'];
				} else {
					$debug_data = $field['value'];
				}

				if ( is_array( $debug_data ) ) {
					$value = '';

					foreach ( $debug_data as $sub_field_name => $sub_field_value ) {
						$value .= sprintf( "\n\t%s: %s", $sub_field_name, $sub_field_value );
					}
				} elseif ( is_bool( $debug_data ) ) {
					$value = $debug_data ? 'true' : 'false';
				} elseif ( empty( $debug_data ) && '0' !== $debug_data ) {
					$value = 'undefined';
				} else {
					$value = $debug_data;
				}

				$label   = 'debug' === $data_type ? $field_name : $field['label'];
				$return .= sprintf( "%s: %s\n", $label, $value );
			}

			$return .= "\n";
		}

		$return .= '`';

		return $return;
	}

	private static function database_rows( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$count = $ctx->int( 1, 8 );

		for ( $i = 0; $i < $count; ++$i ) {
			$data_length  = $ctx->int( 0, 2000000 );
			$index_length = $ctx->int( 0, 1000000 );

			$rows[] = array(
				'Name'         => 'wp_cfz_' . self::slug( $ctx->fork( 'table-' . $i ), 'table' ),
				'Rows'         => $ctx->int( 0, 5000 ),
				'Data_length'  => $ctx->bool( 50 ) ? (string) $data_length : $data_length,
				'Index_length' => $ctx->bool( 50 ) ? (string) $index_length : $index_length,
				'Comment'      => $ctx->bool( 20 ) ? 'generated table status fixture' : '',
			);
		}

		return $rows;
	}

	private static function mysql_var_value( \ComponentFuzz\FuzzContext $ctx ) {
		switch ( $ctx->choice( array( 'string', 'int-string', 'empty', 'zero', 'unicode' ) ) ) {
			case 'int-string':
				return (string) $ctx->int( 1, 999999 );
			case 'empty':
				return '';
			case 'zero':
				return '0';
			case 'unicode':
				return 'utf8mb4_' . self::safe_label( $ctx );
			case 'string':
			default:
				return 'value_' . self::slug( $ctx, 'mysql' );
		}
	}

	private static function directory_sizes( \ComponentFuzz\FuzzContext $ctx, array $paths ): array {
		$sizes = array();
		foreach ( $paths as $name => $path ) {
			$sizes[ $path ] = self::directory_size( $ctx->fork( $name ) );
		}

		return $sizes;
	}

	private static function directory_size( \ComponentFuzz\FuzzContext $ctx ): int {
		return $ctx->choice(
			array(
				0,
				$ctx->int( 1, 1023 ),
				$ctx->int( 1024, 1024 * 1024 ),
				$ctx->int( 1024 * 1024, 6 * 1024 * 1024 ),
			)
		);
	}

	private static function raise_max_execution_time_for_size_scan() {
		if ( ! function_exists( 'ini_set' ) ) {
			return false;
		}

		return @ini_set( 'max_execution_time', (string) max( 60, self::elapsed_since_start() + 60 ) );
	}

	private static function elapsed_since_start(): int {
		return defined( 'WP_START_TIMESTAMP' )
			? max( 0, (int) ceil( microtime( true ) - WP_START_TIMESTAMP ) )
			: 0;
	}

	private static function restore_max_execution_time( $previous ): void {
		if ( false === $previous || ! function_exists( 'ini_set' ) ) {
			return;
		}

		@ini_set( 'max_execution_time', (string) $previous );
	}

	private static function max_execution_time_matches( $previous ): bool {
		return false === $previous
			|| ! function_exists( 'ini_get' )
			|| (string) $previous === (string) ini_get( 'max_execution_time' );
	}

	private static function restore_ext_object_cache_flag( array $previous ): void {
		if ( $previous['exists'] ) {
			$GLOBALS['_wp_using_ext_object_cache'] = $previous['value'];
		} else {
			unset( $GLOBALS['_wp_using_ext_object_cache'] );
		}
	}

	private static function ext_object_cache_flag_matches( array $previous ): bool {
		$exists = array_key_exists( '_wp_using_ext_object_cache', $GLOBALS );
		return $exists === $previous['exists']
			&& ( ! $exists || $GLOBALS['_wp_using_ext_object_cache'] === $previous['value'] );
	}

	private static function with_wpdb( SiteHealthDebugWpdbDouble $wpdb, callable $callback ) {
		$previous_exists = array_key_exists( 'wpdb', $GLOBALS );
		$previous_wpdb   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;

		try {
			return $callback();
		} finally {
			if ( $previous_exists ) {
				$GLOBALS['wpdb'] = $previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
			$wpdb->restored = ( $previous_exists && ( $GLOBALS['wpdb'] ?? null ) === $previous_wpdb )
				|| ( ! $previous_exists && ! array_key_exists( 'wpdb', $GLOBALS ) );
		}
	}

	private static function scalar_value( \ComponentFuzz\FuzzContext $ctx ) {
		switch ( $ctx->choice( array( 'string', 'int', 'float', 'false', 'empty', 'zero-string' ) ) ) {
			case 'int':
				return $ctx->int( -2000, 2000 );
			case 'float':
				return $ctx->int( -200000, 200000 ) / 100;
			case 'false':
				return false;
			case 'empty':
				return '';
			case 'zero-string':
				return '0';
			case 'string':
			default:
				return 'generated-' . self::safe_label( $ctx );
		}
	}

	private static function safe_label( \ComponentFuzz\FuzzContext $ctx ): string {
		$value = preg_replace( '/[^A-Za-z0-9 _.-]+/', ' ', $ctx->text( 1, 24 ) );
		$value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

		return '' === $value ? 'value' : $value;
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$value = strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $ctx->identifier( 4, 14 ) ) );
		$value = trim( (string) $value, '-' );

		return '' === $value ? $prefix : $value;
	}

	private static function is_backtick_wrapped( string $value ): bool {
		return str_starts_with( $value, "`\n" ) && str_ends_with( $value, '`' );
	}

	private static function snapshot_filter_hook( string $hook_name ) {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook_name ] ) ) {
			return null;
		}

		return clone $GLOBALS['wp_filter'][ $hook_name ];
	}

	private static function filter_hooks_match( $before, $after ): bool {
		if ( null === $before || null === $after ) {
			return $before === $after;
		}

		return $before == $after;
	}

	private static function snapshot_state(): array {
		return array(
			'globals'       => self::snapshot_globals(
				array(
					'wpdb',
					'wp_filter',
					'wp_filters',
					'wp_actions',
					'wp_current_filter',
					'wp_object_cache',
					'_wp_using_ext_object_cache',
				)
			),
			'obLevel'       => ob_get_level(),
			'options'       => self::wpdb_options(),
			'contentCounts' => self::wpdb_content_counts(),
			'superglobals'  => self::snapshot_superglobals(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}

		self::restore_superglobals( $snapshot['superglobals'] );
		self::restore_globals( $snapshot['globals'] );

		if (
			null !== $snapshot['options']
			&& isset( $GLOBALS['wpdb'] )
			&& is_object( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' )
		) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}
	}

	private static function state_matches( array $snapshot ): bool {
		return ob_get_level() === $snapshot['obLevel']
			&& self::superglobals_match( $snapshot['superglobals'] )
			&& self::globals_match( $snapshot['globals'] )
			&& self::wpdb_options() === $snapshot['options']
			&& self::wpdb_content_counts() === $snapshot['contentCounts'];
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::snapshot_global_value( $name, $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::restore_global_value( $name, $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function globals_match( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}

			if ( $exists && ! self::global_value_matches( $name, $entry['value'], $GLOBALS[ $name ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_global_value( string $name, $value ) {
		if ( 'wp_filter' === $name ) {
			return self::clone_wp_filter( $value );
		}

		if ( 'wpdb' === $name ) {
			return $value;
		}

		return self::clone_value( $value );
	}

	private static function restore_global_value( string $name, $value ) {
		if ( 'wp_filter' === $name ) {
			return self::clone_wp_filter( $value );
		}

		if ( 'wpdb' === $name ) {
			return $value;
		}

		return self::clone_value( $value );
	}

	private static function global_value_matches( string $name, $expected, $actual ): bool {
		if ( 'wpdb' === $name ) {
			return $expected === $actual;
		}

		return $expected == $actual;
	}

	private static function clone_wp_filter( $wp_filter ) {
		if ( ! is_array( $wp_filter ) ) {
			return $wp_filter;
		}

		$clone = array();
		foreach ( $wp_filter as $hook => $value ) {
			$clone[ $hook ] = is_object( $value ) ? clone $value : $value;
		}

		return $clone;
	}

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		return $value;
	}

	private static function snapshot_superglobals(): array {
		return array(
			'_COOKIE'  => self::clone_value( $_COOKIE ),
			'_ENV'     => self::clone_value( $_ENV ),
			'_FILES'   => self::clone_value( $_FILES ),
			'_GET'     => self::clone_value( $_GET ),
			'_POST'    => self::clone_value( $_POST ),
			'_REQUEST' => self::clone_value( $_REQUEST ),
			'_SERVER'  => self::clone_value( $_SERVER ),
		);
	}

	private static function restore_superglobals( array $snapshot ): void {
		$_COOKIE  = self::clone_value( $snapshot['_COOKIE'] );
		$_ENV     = self::clone_value( $snapshot['_ENV'] );
		$_FILES   = self::clone_value( $snapshot['_FILES'] );
		$_GET     = self::clone_value( $snapshot['_GET'] );
		$_POST    = self::clone_value( $snapshot['_POST'] );
		$_REQUEST = self::clone_value( $snapshot['_REQUEST'] );
		$_SERVER  = self::clone_value( $snapshot['_SERVER'] );
	}

	private static function superglobals_match( array $snapshot ): bool {
		return $_COOKIE == $snapshot['_COOKIE']
			&& $_ENV == $snapshot['_ENV']
			&& $_FILES == $snapshot['_FILES']
			&& $_GET == $snapshot['_GET']
			&& $_POST == $snapshot['_POST']
			&& $_REQUEST == $snapshot['_REQUEST']
			&& $_SERVER == $snapshot['_SERVER'];
	}

	private static function wpdb_options(): ?array {
		if (
			isset( $GLOBALS['wpdb'] )
			&& is_object( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
		) {
			return $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return null;
	}

	private static function wpdb_content_counts(): ?array {
		if (
			isset( $GLOBALS['wpdb'] )
			&& is_object( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
		) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return null;
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

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_array( $value ) ) {
			if ( $depth >= 5 ) {
				return array(
					'type'  => 'array',
					'count' => count( $value ),
				);
			}

			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 20 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => self::escape_bytes( $value ),
			'sha1'    => sha1( $value ),
			'type'    => 'string',
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
			'message' => self::escape_bytes( $e->getMessage() ),
		);
	}

	private static function escape_bytes( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; ++$i ) {
			$ord = ord( $value[ $i ] );
			if ( 9 === $ord ) {
				$out .= '\t';
			} elseif ( 10 === $ord ) {
				$out .= '\n';
			} elseif ( 13 === $ord ) {
				$out .= '\r';
			} elseif ( $ord < 32 || $ord > 126 ) {
				$out .= sprintf( '\x%02X', $ord );
			} else {
				$out .= $value[ $i ];
			}
		}

		if ( $shown < $length ) {
			$out .= '...';
		}

		return $out;
	}
}

final class SiteHealthDebugWpdbDouble {
	public int $num_rows = 0;
	public string $last_query = '';
	public $last_output = null;
	public bool $restored = false;
	public array $prepared_queries = array();
	public array $row_queries = array();

	private array $rows;
	private array $variables;

	public function __construct( array $rows, array $variables = array() ) {
		$this->rows      = $rows;
		$this->variables = $variables;
	}

	public function get_results( $query = null, $output = OBJECT ): array {
		$this->last_query  = (string) $query;
		$this->last_output = $output;
		$this->num_rows    = count( $this->rows );

		return $this->rows;
	}

	public function prepare( $query, ...$args ): string {
		$query = (string) $query;
		$this->prepared_queries[] = array(
			'query' => $query,
			'args'  => $args,
		);

		if ( 'SHOW VARIABLES LIKE %s' === $query && isset( $args[0] ) ) {
			return 'SHOW VARIABLES LIKE ' . (string) $args[0];
		}

		return $query;
	}

	public function get_row( $query = null, $output = OBJECT ) {
		$query               = (string) $query;
		$this->last_query    = $query;
		$this->last_output   = $output;
		$this->row_queries[] = array(
			'query'  => $query,
			'output' => $output,
		);

		$prefix = 'SHOW VARIABLES LIKE ';
		if ( ! str_starts_with( $query, $prefix ) ) {
			return null;
		}

		$name = substr( $query, strlen( $prefix ) );
		if ( ! array_key_exists( $name, $this->variables ) ) {
			return null;
		}

		$value = $this->variables[ $name ];
		if ( is_array( $value ) ) {
			return $value;
		}

		return array(
			'Variable_name' => $name,
			'Value'         => $value,
		);
	}
}
