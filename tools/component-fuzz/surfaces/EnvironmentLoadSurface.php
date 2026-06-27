<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes low-level environment, loading, HTTPS, and UTF-8 compatibility helpers.
 */
final class EnvironmentLoadSurface {
	public const NAME = 'environment-load';

	private const UTF8_CASES = 12;
	private const ENVIRONMENT_TYPE_GENERATED_CASES = 8;
	private const REQUEST_MEDIA_GENERATED_CASES = 16;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'environment-load.bootstrap-apis-available',
					'Required WordPress environment/load APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows = array_merge( $rows, self::check_environment_type( $ctx->fork( 'environment-type' ) ) );
			$rows[] = self::check_development_mode( $ctx->fork( 'development-mode' ) );
			$rows[] = self::check_server_protocols_and_fixups( $ctx->fork( 'server-fixups' ) );
			$rows[] = self::check_basic_auth( $ctx->fork( 'basic-auth' ) );
			$rows[] = self::check_ssl_detection( $ctx->fork( 'ssl' ) );
			$rows[] = self::check_memory_limit_parsing( $ctx->fork( 'memory' ) );
			$rows[] = self::check_ini_mutability( $ctx->fork( 'ini' ) );
			$rows[] = self::check_installing_and_maintenance_flags( $ctx->fork( 'installing' ), $snapshot );
			$rows[] = self::check_runtime_filters_and_request_guards( $ctx->fork( 'request-guards' ) );
			$rows[] = self::check_json_xml_request_guards( $ctx->fork( 'json-xml' ) );
			$rows[] = self::check_generated_request_media_matrix( $ctx->fork( 'request-media-matrix' ) );
			$rows = array_merge( $rows, self::check_https_helpers( $ctx->fork( 'https' ) ) );
			$rows[] = self::check_utf8_validation_and_scanning( $ctx->fork( 'utf8-scan' ) );
			$rows[] = self::check_utf8_compat_helpers( $ctx->fork( 'utf8-compat' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'environment-load.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'environment-load.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals'      => array_keys( $snapshot['globals'] ),
				'trackedSuperglobals' => array_keys( $snapshot['superglobals'] ),
				'trackedEnv'          => 'WP_ENVIRONMENT_TYPE',
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'_is_utf8_charset',
				'_mb_chr',
				'_mb_ord',
				'_mb_strlen',
				'_mb_substr',
				'_wp_is_valid_utf8_fallback',
				'_wp_scan_utf8',
				'_wp_scrub_utf8_fallback',
				'_wp_utf8_codepoint_count',
				'_wp_utf8_codepoint_span',
				'add_action',
				'add_filter',
				'get_option',
				'has_filter',
				'is_protected_ajax_action',
				'is_ssl',
				'remove_action',
				'remove_filter',
				'update_option',
				'wp_convert_hr_to_bytes',
				'wp_doing_ajax',
				'wp_doing_cron',
				'wp_fix_server_vars',
				'wp_get_development_mode',
				'wp_get_environment_type',
				'wp_get_https_detection_errors',
				'wp_get_server_protocol',
				'wp_has_noncharacters',
				'wp_installing',
				'wp_is_development_mode',
				'wp_is_file_mod_allowed',
				'wp_is_home_url_using_https',
				'wp_is_https_supported',
				'wp_is_ini_value_changeable',
				'wp_is_json_media_type',
				'wp_is_json_request',
				'wp_is_jsonp_request',
				'wp_is_local_html_output',
				'wp_is_maintenance_mode',
				'wp_is_site_protected_by_basic_auth',
				'wp_is_site_url_using_https',
				'wp_is_using_https',
				'wp_is_valid_utf8',
				'wp_is_xml_request',
				'wp_populate_basic_auth_from_authorization_header',
				'wp_replace_insecure_home_url',
				'wp_scrub_utf8',
				'wp_should_replace_insecure_home_url',
				'wp_using_themes',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Error' ) ) {
			$missing[] = 'class WP_Error';
		}

		return $missing;
	}

	private static function check_environment_type( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'getenv' ) || ! function_exists( 'putenv' ) ) {
			return array(
				$ctx->skip(
					'environment-load.environment-type.getenv-putenv-available',
					'getenv() or putenv() is unavailable in this PHP runtime.'
				),
			);
		}

		$allowed  = self::environment_type_allowed_values();
		$cases    = self::environment_type_matrix_cases( $ctx );
		$failures = array();
		$original = getenv( 'WP_ENVIRONMENT_TYPE' );

		try {
			foreach ( $cases as $index => $case ) {
				$value = $case['value'];
				self::set_env_var( 'WP_ENVIRONMENT_TYPE', $value );

				$actual   = \wp_get_environment_type();
				$expected = self::environment_type_expected_value(
					$value,
					defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : null
				);
				$honored  = $actual === $expected;

				self::collect_failure(
					$failures,
					in_array( $actual, $allowed, true ) && ( ! defined( 'WP_RUN_CORE_TESTS' ) || $honored ),
					"wp_get_environment_type allowed/fail-closed case {$index}",
					array(
						'label'        => $case['label'],
						'value'        => $value,
						'actual'       => $actual,
						'expected'     => $expected,
						'caseExpected' => $case['expected'],
						'honored'      => $honored,
					)
				);
			}
		} finally {
			self::set_env_var( 'WP_ENVIRONMENT_TYPE', $original );
		}

		$rows = array(
			self::result(
				$ctx,
				'environment-load.environment-type.allowed-values',
				array() === $failures,
				array(
					'cases'       => count( $cases ),
					'cacheBypass' => defined( 'WP_RUN_CORE_TESTS' ),
					'failures'    => array_slice( $failures, 0, 6 ),
				)
			),
		);

		$rows[] = self::check_environment_type_full_matrix( $ctx->fork( 'full-matrix' ), $cases );

		return $rows;
	}

	private static function environment_type_allowed_values(): array {
		return array( 'local', 'development', 'staging', 'production' );
	}

	private static function environment_type_expected_value( $env_value, $constant_value = null ): string {
		$current = false === $env_value ? '' : $env_value;
		if ( null !== $constant_value && $constant_value ) {
			$current = $constant_value;
		}

		return is_string( $current ) && in_array( $current, self::environment_type_allowed_values(), true )
			? $current
			: 'production';
	}

	private static function environment_type_matrix_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		foreach ( self::environment_type_allowed_values() as $value ) {
			$cases[] = array(
				'label'    => 'allowed-' . $value,
				'value'    => $value,
				'expected' => $value,
				'allowed'  => true,
			);
		}

		foreach (
			array(
				'empty'          => '',
				'uppercase'      => 'LOCAL',
				'abbreviation'   => 'prod',
				'trailing-lf'    => "staging\n",
				'leading-space'  => ' production',
				'trailing-space' => 'development ',
			) as $label => $value
		) {
			$cases[] = array(
				'label'    => 'invalid-' . $label,
				'value'    => $value,
				'expected' => 'production',
				'allowed'  => false,
			);
		}

		for ( $i = 0; $i < self::ENVIRONMENT_TYPE_GENERATED_CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'generated-' . $i );
			$value    = self::generated_environment_type_value( $case_ctx );
			$cases[]  = array(
				'label'    => 'generated-invalid-' . $i,
				'value'    => $value,
				'expected' => 'production',
				'allowed'  => false,
			);
		}

		return $cases;
	}

	private static function generated_environment_type_value( \ComponentFuzz\FuzzContext $ctx ): string {
		$allowed = self::environment_type_allowed_values();
		$value   = $ctx->choice(
			array(
				'component-' . strtolower( $ctx->identifier( 3, 10 ) ),
				strtoupper( $ctx->choice( $allowed ) ),
				$ctx->choice( $allowed ) . '-' . strtolower( $ctx->identifier( 2, 6 ) ),
				$ctx->ascii( 1, 12 ) . '-cfz',
			)
		);

		return in_array( $value, $allowed, true ) || '' === $value ? 'component-fuzz-invalid' : $value;
	}

	private static function check_environment_type_full_matrix( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$missing = self::missing_environment_type_matrix_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'environment-load.environment-type.full-matrix',
				'Child-process support for isolated WP_RUN_CORE_TESTS environment-type coverage is unavailable.',
				array( 'missing' => implode( ', ', $missing ) )
			);
		}

		$constant_allowed_value = $ctx->fork( 'constant-allowed' )->choice( self::environment_type_allowed_values() );
		$constant_invalid_value = self::generated_environment_type_value( $ctx->fork( 'constant-invalid' ) );
		$matrix_runs            = array(
			'env-var'          => array(
				'label'         => 'environment variable matrix',
				'constantValue' => null,
			),
			'constant-allowed' => array(
				'label'         => 'allowed constant override matrix',
				'constantValue' => $constant_allowed_value,
			),
			'constant-invalid' => array(
				'label'         => 'invalid constant override matrix',
				'constantValue' => $constant_invalid_value,
			),
		);

		$parent_snapshot_before = self::snapshot_state();
		$parent_constant_before = defined( 'WP_RUN_CORE_TESTS' );
		try {
			foreach ( $matrix_runs as $key => $matrix ) {
				$matrix_runs[ $key ]['run'] = self::run_child_environment_type_matrix( $cases, $matrix['constantValue'] );
			}
		} finally {
			self::restore_state( $parent_snapshot_before );
		}
		$parent_state_restored = self::state_matches( $parent_snapshot_before );
		$parent_constant_after = defined( 'WP_RUN_CORE_TESTS' );
		$allowed_values        = self::environment_type_allowed_values();
		$failures              = array();
		$matrix_summaries      = array();

		self::collect_failure(
			$failures,
			$parent_state_restored && $parent_constant_before === $parent_constant_after,
			'parent environment, globals, and WP_RUN_CORE_TESTS definition are unchanged',
			array(
				'parentStateRestored'    => $parent_state_restored,
				'parentConstantBefore'   => $parent_constant_before,
				'parentConstantAfter'    => $parent_constant_after,
				'parentEnvBefore'        => $parent_snapshot_before['env'],
				'parentEnvAfter'         => function_exists( 'getenv' ) ? getenv( 'WP_ENVIRONMENT_TYPE' ) : false,
			)
		);

		foreach ( $matrix_runs as $mode => $matrix ) {
			$run = $matrix['run'] ?? array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'child matrix was not executed',
				'result'   => null,
			);
			$result                    = is_array( $run['result'] ) ? $run['result'] : array();
			$child_cases               = is_array( $result['cases'] ?? null ) ? $result['cases'] : array();
			$expected_shape            = self::environment_type_child_result_has_expected_shape( $result );
			$child_unexpected_output   = (string) ( $result['unexpectedOutput'] ?? '' );
			$allowed_cases_honored     = 0;
			$fail_closed_cases_honored = 0;
			$constant_cases_honored    = 0;
			$constant_defined          = null !== $matrix['constantValue'];

			self::collect_failure(
				$failures,
				$run['ok'],
				"isolated environment-type {$matrix['label']} subprocess exits cleanly and returns structured JSON",
				array(
					'mode'     => $mode,
					'exitCode' => $run['exitCode'],
					'stdout'   => \ComponentFuzz\preview_value( $run['stdout'] ),
					'stderr'   => \ComponentFuzz\preview_value( $run['stderr'] ),
				)
			);

			self::collect_failure(
				$failures,
				'' === $run['stderr'] && '' === $child_unexpected_output,
				"isolated environment-type {$matrix['label']} subprocess emits no stderr or stray output",
				array(
					'mode'             => $mode,
					'stderr'           => \ComponentFuzz\preview_value( $run['stderr'] ),
					'unexpectedOutput' => \ComponentFuzz\preview_value( $child_unexpected_output ),
				)
			);

			self::collect_failure(
				$failures,
				$expected_shape,
				"isolated environment-type {$matrix['label']} subprocess result has expected shape",
				array(
					'mode'       => $mode,
					'resultKeys' => array_keys( $result ),
					'casesType'  => gettype( $result['cases'] ?? null ),
				)
			);

			self::collect_failure(
				$failures,
				true === ( $result['wpRunCoreTests'] ?? null ),
				"{$matrix['label']} child defines WP_RUN_CORE_TESTS before evaluating environment types",
				array(
					'mode'           => $mode,
					'wpRunCoreTests' => $result['wpRunCoreTests'] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				$constant_defined === ( $result['constantDefined'] ?? null )
					&& ( ! $constant_defined || $matrix['constantValue'] === ( $result['constantValue'] ?? null ) ),
				"{$matrix['label']} child has the expected WP_ENVIRONMENT_TYPE constant state",
				array(
					'mode'                    => $mode,
					'expectedConstantDefined' => $constant_defined,
					'actualConstantDefined'   => $result['constantDefined'] ?? null,
					'expectedConstantValue'   => $matrix['constantValue'],
					'actualConstantValue'     => $result['constantValue'] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				true === ( $result['childStateRestored'] ?? null ),
				"{$matrix['label']} child restores WP_ENVIRONMENT_TYPE before exit",
				array(
					'mode'               => $mode,
					'childStateRestored' => $result['childStateRestored'] ?? null,
					'childOriginalEnv'   => $result['originalEnv'] ?? null,
					'childRestoredEnv'   => $result['restoredEnv'] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				count( $cases ) === count( $child_cases ),
				"{$matrix['label']} child evaluates every generated environment-type matrix case",
				array(
					'mode'          => $mode,
					'expectedCount' => count( $cases ),
					'actualCount'   => count( $child_cases ),
				)
			);

			foreach ( $cases as $index => $case ) {
				$child_case = $child_cases[ $index ] ?? null;
				if ( ! is_array( $child_case ) ) {
					self::collect_failure(
						$failures,
						false,
						"{$matrix['label']} environment-type matrix case {$index} is present",
						array(
							'mode'        => $mode,
							'case'        => $case,
							'actualEntry' => $child_case,
						)
					);
					continue;
				}

				$actual   = $child_case['actual'] ?? null;
				$repeat   = $child_case['repeat'] ?? null;
				$expected = self::environment_type_expected_value( $case['value'], $matrix['constantValue'] );
				$matched  = ( $child_case['label'] ?? null ) === $case['label']
					&& ( $child_case['value'] ?? null ) === $case['value']
					&& ( $child_case['expected'] ?? null ) === $expected
					&& $actual === $expected
					&& $repeat === $expected
					&& in_array( $actual, $allowed_values, true );

				if ( ! $constant_defined && ! empty( $case['allowed'] ) && $matched ) {
					++$allowed_cases_honored;
				}
				if ( ! $constant_defined && empty( $case['allowed'] ) && $matched && 'production' === $actual ) {
					++$fail_closed_cases_honored;
				}
				if ( $constant_defined && $matched ) {
					++$constant_cases_honored;
				}

				self::collect_failure(
					$failures,
					$matched,
					$constant_defined
						? "wp_get_environment_type lets WP_ENVIRONMENT_TYPE constant override {$case['label']}"
						: ( ! empty( $case['allowed'] )
							? "wp_get_environment_type honors allowed value {$case['value']}"
							: "wp_get_environment_type fails closed for {$case['label']}" ),
					array(
						'mode'      => $mode,
						'case'      => $case,
						'expected'  => $expected,
						'childCase' => $child_case,
					)
				);
			}

			$matrix_summaries[ $mode ] = array(
				'constantValue'         => $matrix['constantValue'],
				'caseCount'             => count( $cases ),
				'childCaseCount'        => count( $child_cases ),
				'allowedCasesHonored'   => $allowed_cases_honored,
				'failClosedCasesHonored' => $fail_closed_cases_honored,
				'constantCasesHonored'  => $constant_cases_honored,
				'childWpRunCoreTests'   => $result['wpRunCoreTests'] ?? null,
				'constantDefined'       => $result['constantDefined'] ?? null,
				'childStateRestored'    => $result['childStateRestored'] ?? null,
				'childUnexpectedOutput' => \ComponentFuzz\preview_value( $child_unexpected_output ),
				'childStderr'           => \ComponentFuzz\preview_value( $run['stderr'] ),
			);
		}

		return self::result(
			$ctx,
			'environment-load.environment-type.full-matrix',
			array() === $failures,
			array(
				'cases'                 => count( $cases ),
				'generatedCases'        => self::ENVIRONMENT_TYPE_GENERATED_CASES,
				'matrixRuns'            => $matrix_summaries,
				'parentStateRestored'   => $parent_state_restored,
				'parentConstantBefore'  => $parent_constant_before,
				'parentConstantAfter'   => $parent_constant_after,
				'failures'              => array_slice( $failures, 0, 10 ),
			)
		);
	}

	private static function missing_environment_type_matrix_requirements(): array {
		$missing = array();

		foreach ( array( 'json_decode', 'json_encode', 'proc_close', 'proc_open', 'stream_get_contents' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}

		return $missing;
	}

	private static function run_child_environment_type_matrix( array $cases, $constant_value ): array {
		$child_cases = array();
		foreach ( $cases as $case ) {
			$case['expected'] = self::environment_type_expected_value( $case['value'], $constant_value );
			$child_cases[]    = $case;
		}

		$payload = json_encode(
			array(
				'repoRoot'      => \ComponentFuzz\repo_root(),
				'cases'         => $child_cases,
				'constantValue' => $constant_value,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( false === $payload ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'json_encode failed',
				'result'   => null,
			);
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates WP_RUN_CORE_TESTS in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::environment_type_matrix_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		fwrite( $pipes[0], $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		$result    = json_decode( (string) $stdout, true );

		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && true === ( $result['ok'] ?? null ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function environment_type_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& is_bool( $result['ok'] )
			&& array_key_exists( 'wpRunCoreTests', $result )
			&& is_bool( $result['wpRunCoreTests'] )
			&& array_key_exists( 'constantDefined', $result )
			&& is_bool( $result['constantDefined'] )
			&& array_key_exists( 'constantValue', $result )
			&& array_key_exists( 'cases', $result )
			&& is_array( $result['cases'] )
			&& array_key_exists( 'childStateRestored', $result )
			&& is_bool( $result['childStateRestored'] )
			&& array_key_exists( 'unexpectedOutput', $result )
			&& is_string( $result['unexpectedOutput'] );
	}

	private static function environment_type_matrix_child_program(): string {
		return <<<'PHP'
ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

function component_fuzz_env_load_set_env_var( string $name, $value ): void {
	if ( false === $value ) {
		putenv( $name );
		return;
	}

	putenv( $name . '=' . (string) $value );
}

function component_fuzz_env_load_snapshot(): array {
	return array(
		'env' => getenv( 'WP_ENVIRONMENT_TYPE' ),
	);
}

function component_fuzz_env_load_restore( array $snapshot ): void {
	component_fuzz_env_load_set_env_var( 'WP_ENVIRONMENT_TYPE', $snapshot['env'] );
}

function component_fuzz_env_load_state_matches( array $snapshot ): bool {
	return getenv( 'WP_ENVIRONMENT_TYPE' ) === $snapshot['env'];
}

function component_fuzz_env_load_preview( string $value, int $limit = 240 ): string {
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

$component_fuzz_env_load_outer_ob_level = ob_get_level();
ob_start();

$component_fuzz_env_load_snapshot          = null;
$component_fuzz_env_load_unexpected_output = '';
$component_fuzz_env_load_result            = array(
	'ok'                 => false,
	'wpRunCoreTests'     => false,
	'constantDefined'    => false,
	'constantValue'      => null,
	'cases'              => array(),
	'childStateRestored' => false,
	'originalEnv'        => null,
	'restoredEnv'        => null,
	'unexpectedOutput'   => '',
);

try {
	$component_fuzz_env_load_raw     = stream_get_contents( STDIN );
	$component_fuzz_env_load_fixture = json_decode( $component_fuzz_env_load_raw, true );

	if (
		! is_array( $component_fuzz_env_load_fixture )
		|| empty( $component_fuzz_env_load_fixture['repoRoot'] )
		|| ! is_array( $component_fuzz_env_load_fixture['cases'] ?? null )
	) {
		throw new RuntimeException( 'Invalid environment-type fixture.' );
	}

	if ( array_key_exists( 'constantValue', $component_fuzz_env_load_fixture ) && null !== $component_fuzz_env_load_fixture['constantValue'] ) {
		define( 'WP_ENVIRONMENT_TYPE', (string) $component_fuzz_env_load_fixture['constantValue'] );
	}

	if ( ! defined( 'WP_RUN_CORE_TESTS' ) ) {
		define( 'WP_RUN_CORE_TESTS', true );
	}

	$component_fuzz_env_load_result['constantDefined'] = defined( 'WP_ENVIRONMENT_TYPE' );
	$component_fuzz_env_load_result['constantValue']   = defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : null;

	require_once $component_fuzz_env_load_fixture['repoRoot'] . '/tools/component-fuzz/lib/autoload.php';

	\ComponentFuzz\WpBootstrap::load();

	$component_fuzz_env_load_snapshot = component_fuzz_env_load_snapshot();
	$component_fuzz_env_load_result['originalEnv'] = $component_fuzz_env_load_snapshot['env'];

	foreach ( $component_fuzz_env_load_fixture['cases'] as $component_fuzz_env_load_index => $component_fuzz_env_load_case ) {
		if (
			! is_array( $component_fuzz_env_load_case )
			|| ! array_key_exists( 'label', $component_fuzz_env_load_case )
			|| ! array_key_exists( 'value', $component_fuzz_env_load_case )
			|| ! array_key_exists( 'expected', $component_fuzz_env_load_case )
			|| ! array_key_exists( 'allowed', $component_fuzz_env_load_case )
		) {
			throw new RuntimeException( 'Invalid environment-type case at index ' . $component_fuzz_env_load_index . '.' );
		}

		component_fuzz_env_load_set_env_var( 'WP_ENVIRONMENT_TYPE', $component_fuzz_env_load_case['value'] );

		$component_fuzz_env_load_actual = wp_get_environment_type();
		$component_fuzz_env_load_repeat = wp_get_environment_type();

		$component_fuzz_env_load_result['cases'][] = array(
			'index'    => $component_fuzz_env_load_index,
			'label'    => $component_fuzz_env_load_case['label'],
			'value'    => $component_fuzz_env_load_case['value'],
			'expected' => $component_fuzz_env_load_case['expected'],
			'allowed'  => $component_fuzz_env_load_case['allowed'],
			'actual'   => $component_fuzz_env_load_actual,
			'repeat'   => $component_fuzz_env_load_repeat,
			'honored'  => $component_fuzz_env_load_actual === $component_fuzz_env_load_case['expected']
				&& $component_fuzz_env_load_repeat === $component_fuzz_env_load_case['expected'],
		);
	}

	$component_fuzz_env_load_result['wpRunCoreTests'] = defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS;
	$component_fuzz_env_load_result['ok']             = true;
} catch ( Throwable $e ) {
	$component_fuzz_env_load_result['throwable'] = array(
		'class'   => get_class( $e ),
		'message' => $e->getMessage(),
		'file'    => $e->getFile(),
		'line'    => $e->getLine(),
	);
} finally {
	if ( is_array( $component_fuzz_env_load_snapshot ) ) {
		component_fuzz_env_load_restore( $component_fuzz_env_load_snapshot );
		$component_fuzz_env_load_result['childStateRestored'] = component_fuzz_env_load_state_matches( $component_fuzz_env_load_snapshot );
		$component_fuzz_env_load_result['restoredEnv']        = getenv( 'WP_ENVIRONMENT_TYPE' );
	}

	while ( ob_get_level() > $component_fuzz_env_load_outer_ob_level ) {
		$component_fuzz_env_load_unexpected_output = ob_get_clean() . $component_fuzz_env_load_unexpected_output;
	}

	$component_fuzz_env_load_result['unexpectedOutput'] = component_fuzz_env_load_preview( $component_fuzz_env_load_unexpected_output );
}

$component_fuzz_env_load_json = json_encode( $component_fuzz_env_load_result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
echo false === $component_fuzz_env_load_json ? '{"ok":false,"error":"json_encode failed"}' : $component_fuzz_env_load_json;
exit( ! empty( $component_fuzz_env_load_result['ok'] ) ? 0 : 1 );
PHP;
	}

	private static function check_development_mode( \ComponentFuzz\FuzzContext $ctx ): array {
		$current  = \wp_get_development_mode();
		$modes    = array( 'core', 'plugin', 'theme', 'all', '', 'invalid-' . $ctx->identifier( 3, 8 ) );
		$allowed  = array( 'core', 'plugin', 'theme', 'all', '' );
		$failures = array();
		$seen     = array();

		foreach ( $modes as $mode ) {
			$actual   = \wp_is_development_mode( $mode );
			$expected = '' !== $current && ( 'all' === $current || $mode === $current );
			$seen[]   = array(
				'mode'     => $mode,
				'actual'   => $actual,
				'expected' => $expected,
			);

			self::collect_failure(
				$failures,
				$actual === $expected,
				"wp_is_development_mode agreement for {$mode}",
				end( $seen )
			);
		}

		return self::result(
			$ctx,
			'environment-load.development-mode.current-mode-agreement',
			in_array( $current, $allowed, true ) && array() === $failures,
			array(
				'current'  => $current,
				'checks'   => $seen,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_server_protocols_and_fixups( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$original_server = $_SERVER;
		$original_self   = $GLOBALS['PHP_SELF'] ?? null;
		$self_existed    = array_key_exists( 'PHP_SELF', $GLOBALS );

		$protocols = array(
			'HTTP/1.1' => 'HTTP/1.1',
			'HTTP/2'   => 'HTTP/2',
			'HTTP/2.0' => 'HTTP/2.0',
			'HTTP/3'   => 'HTTP/3',
			'HTTP/1.0' => 'HTTP/1.0',
			'HTTP/4'   => 'HTTP/1.0',
			''         => 'HTTP/1.0',
			$ctx->ascii( 0, 14 ) => 'HTTP/1.0',
		);

		try {
			foreach ( $protocols as $protocol => $expected ) {
				$_SERVER = array(
					'SERVER_PROTOCOL' => $protocol,
				);

				self::collect_failure(
					$failures,
					\wp_get_server_protocol() === $expected,
					"wp_get_server_protocol normalizes {$protocol}",
					array(
						'protocol' => $protocol,
						'expected' => $expected,
						'actual'   => \wp_get_server_protocol(),
					)
				);
			}

			foreach ( self::server_fixup_cases() as $index => $case ) {
				$_SERVER            = $case['server'];
				$GLOBALS['PHP_SELF'] = $_SERVER['PHP_SELF'];

				\wp_fix_server_vars();

				foreach ( $case['expected'] as $key => $expected ) {
					self::collect_failure(
						$failures,
						( $_SERVER[ $key ] ?? null ) === $expected,
						"wp_fix_server_vars {$case['label']} {$key}",
						array(
							'case'     => $index,
							'key'      => $key,
							'expected' => $expected,
							'actual'   => $_SERVER[ $key ] ?? null,
							'server'   => $_SERVER,
						)
					);
				}
			}
		} finally {
			$_SERVER = $original_server;
			if ( $self_existed ) {
				$GLOBALS['PHP_SELF'] = $original_self;
			} else {
				unset( $GLOBALS['PHP_SELF'] );
			}
		}

		return self::result(
			$ctx,
			'environment-load.server-vars.protocols-fixups',
			array() === $failures,
			array(
				'protocolCases' => count( $protocols ),
				'fixupCases'   => count( self::server_fixup_cases() ),
				'failures'     => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function server_fixup_cases(): array {
		return array(
			array(
				'label'    => 'iis-original-url',
				'server'   => array(
					'SERVER_SOFTWARE'     => 'Microsoft-IIS/10.0',
					'REQUEST_URI'         => '',
					'HTTP_X_ORIGINAL_URL' => '/rewritten/path?x=1',
					'PHP_SELF'            => '',
					'SCRIPT_NAME'         => '/index.php',
					'QUERY_STRING'        => '',
				),
				'expected' => array(
					'REQUEST_URI' => '/rewritten/path?x=1',
					'PHP_SELF'    => '/rewritten/path',
				),
			),
			array(
				'label'    => 'orig-path-info',
				'server'   => array(
					'SERVER_SOFTWARE' => 'Apache',
					'REQUEST_URI'     => '',
					'PHP_SELF'        => '/index.php',
					'SCRIPT_NAME'     => '/index.php',
					'ORIG_PATH_INFO'  => '/library/item',
					'QUERY_STRING'    => 'a=1',
				),
				'expected' => array(
					'PATH_INFO'   => '/library/item',
					'REQUEST_URI' => '/index.php/library/item?a=1',
					'PHP_SELF'    => '/index.php',
				),
			),
			array(
				'label'    => 'php-cgi-script-filename',
				'server'   => array(
					'SERVER_SOFTWARE' => 'ComponentFuzz',
					'REQUEST_URI'     => '/cgi-path',
					'PHP_SELF'        => '/index.php',
					'SCRIPT_NAME'     => '/index.php',
					'SCRIPT_FILENAME' => '/cgi-bin/php.cgi',
					'PATH_TRANSLATED' => '/var/www/index.php',
					'QUERY_STRING'    => '',
				),
				'expected' => array(
					'SCRIPT_FILENAME' => '/var/www/index.php',
					'REQUEST_URI'     => '/cgi-path',
				),
			),
		);
	}

	private static function check_basic_auth( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$original_server = $_SERVER;
		$original_pagenow = $GLOBALS['pagenow'] ?? null;
		$pagenow_existed = array_key_exists( 'pagenow', $GLOBALS );

		$user = 'user_' . self::safe_basic_auth_token( $ctx->identifier( 3, 8 ) );
		$pass = 'pass:' . $ctx->int( 10, 99 );
		$valid_header = 'Basic ' . base64_encode( "{$user}:{$pass}" );
		$cases = array(
			array(
				'label'    => 'http-authorization',
				'server'   => array( 'HTTP_AUTHORIZATION' => $valid_header ),
				'expected' => array( $user, $pass ),
			),
			array(
				'label'    => 'redirect-http-authorization',
				'server'   => array( 'REDIRECT_HTTP_AUTHORIZATION' => $valid_header ),
				'expected' => array( $user, $pass ),
			),
			array(
				'label'    => 'invalid-scheme',
				'server'   => array( 'HTTP_AUTHORIZATION' => 'Bearer ' . base64_encode( "{$user}:{$pass}" ) ),
				'expected' => null,
			),
			array(
				'label'    => 'no-colon',
				'server'   => array( 'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode( $user ) ),
				'expected' => null,
			),
			array(
				'label'    => 'preexisting-not-overridden',
				'server'   => array(
					'HTTP_AUTHORIZATION' => $valid_header,
					'PHP_AUTH_USER'      => 'existing',
					'PHP_AUTH_PW'        => 'secret',
				),
				'expected' => array( 'existing', 'secret' ),
			),
		);

		try {
			foreach ( $cases as $index => $case ) {
				$_SERVER = array_merge( self::default_server(), $case['server'] );
				\wp_populate_basic_auth_from_authorization_header();

				$actual = isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] )
					? array( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] )
					: null;

				self::collect_failure(
					$failures,
					$actual === $case['expected'],
					"wp_populate_basic_auth_from_authorization_header {$case['label']}",
					array(
						'case'     => $index,
						'expected' => $case['expected'],
						'actual'   => $actual,
					)
				);
			}

			$_SERVER = array_merge(
				self::default_server(),
				array(
					'PHP_AUTH_USER' => $user,
					'PHP_AUTH_PW'   => $pass,
				)
			);
			$GLOBALS['pagenow'] = 'wp-login.php';

			$filter_seen = array();
			$filter = static function ( bool $protected, string $context ) use ( &$filter_seen ): bool {
				$filter_seen[] = $context;
				return 'front' === $context ? true : $protected;
			};
			\add_filter( 'wp_is_site_protected_by_basic_auth', $filter, 10, 2 );
			try {
				$login_protected = \wp_is_site_protected_by_basic_auth();
				unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
				$front_filtered = \wp_is_site_protected_by_basic_auth( 'front' );
			} finally {
				\remove_filter( 'wp_is_site_protected_by_basic_auth', $filter, 10 );
			}

			self::collect_failure(
				$failures,
				true === $login_protected
					&& true === $front_filtered
					&& array( 'login', 'front' ) === $filter_seen
					&& false === \has_filter( 'wp_is_site_protected_by_basic_auth', $filter ),
				'wp_is_site_protected_by_basic_auth context and filter locality',
				array(
					'loginProtected' => $login_protected,
					'frontFiltered'  => $front_filtered,
					'filterSeen'     => $filter_seen,
					'filterAfter'    => \has_filter( 'wp_is_site_protected_by_basic_auth', $filter ),
				)
			);
		} finally {
			$_SERVER = $original_server;
			if ( $pagenow_existed ) {
				$GLOBALS['pagenow'] = $original_pagenow;
			} else {
				unset( $GLOBALS['pagenow'] );
			}
		}

		return self::result(
			$ctx,
			'environment-load.basic-auth.normalization',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_ssl_detection( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$original_server = $_SERVER;
		$cases           = array(
			array(
				'label'    => 'https-on',
				'server'   => array( 'HTTPS' => 'on' ),
				'expected' => true,
			),
			array(
				'label'    => 'https-uppercase-on',
				'server'   => array( 'HTTPS' => 'ON' ),
				'expected' => true,
			),
			array(
				'label'    => 'https-one',
				'server'   => array( 'HTTPS' => '1' ),
				'expected' => true,
			),
			array(
				'label'    => 'https-off-port-443-fail-closed',
				'server'   => array(
					'HTTPS'      => 'off',
					'SERVER_PORT' => '443',
				),
				'expected' => false,
			),
			array(
				'label'    => 'port-443',
				'server'   => array( 'SERVER_PORT' => '443' ),
				'expected' => true,
			),
			array(
				'label'    => 'forwarded-proto-only',
				'server'   => array( 'HTTP_X_FORWARDED_PROTO' => 'https' ),
				'expected' => false,
			),
			array(
				'label'    => 'front-end-https-only',
				'server'   => array( 'HTTP_FRONT_END_HTTPS' => 'on' ),
				'expected' => false,
			),
		);

		try {
			foreach ( $cases as $index => $case ) {
				$_SERVER = array_merge( self::default_server(), $case['server'] );
				$actual  = \is_ssl();

				self::collect_failure(
					$failures,
					$actual === $case['expected'],
					"is_ssl {$case['label']}",
					array(
						'case'     => $index,
						'server'   => $case['server'],
						'expected' => $case['expected'],
						'actual'   => $actual,
					)
				);
			}
		} finally {
			$_SERVER = $original_server;
		}

		return self::result(
			$ctx,
			'environment-load.ssl.header-port-normalization',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_memory_limit_parsing( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array_merge(
			array(
				'',
				'0',
				'1',
				'2k',
				'2K',
				'3m',
				'4M',
				'5g',
				' 6M ',
				'7mb',
				'10M garbage',
				'9q',
				'abc',
				'm10',
				'g',
				'-1M',
			),
			self::memory_cases( $ctx )
		);

		foreach ( $cases as $index => $value ) {
			$actual   = \wp_convert_hr_to_bytes( $value );
			$expected = self::expected_hr_to_bytes( $value );

			self::collect_failure(
				$failures,
				$actual === $expected,
				"wp_convert_hr_to_bytes exact oracle case {$index}",
				array(
					'value'    => $value,
					'expected' => $expected,
					'actual'   => $actual,
				)
			);
		}

		foreach ( array( '', 'k', 'm', 'g' ) as $unit ) {
			$previous = null;
			foreach ( array( 0, 1, 2, 8, 16, $ctx->int( 17, 128 ) ) as $number ) {
				$value  = (string) $number . $unit;
				$actual = \wp_convert_hr_to_bytes( $value );
				self::collect_failure(
					$failures,
					null === $previous || $actual >= $previous,
					"wp_convert_hr_to_bytes monotonic {$unit}",
					array(
						'value'    => $value,
						'previous' => $previous,
						'actual'   => $actual,
					)
				);
				$previous = $actual;
			}
		}

		foreach ( array( 'abc', 'm10', 'k', 'gibberish-g', $ctx->identifier( 3, 10 ) . 'M' ) as $malformed ) {
			self::collect_failure(
				$failures,
				0 === \wp_convert_hr_to_bytes( $malformed ),
				'wp_convert_hr_to_bytes malformed non-numeric prefix fails closed',
				array(
					'value'  => $malformed,
					'actual' => \wp_convert_hr_to_bytes( $malformed ),
				)
			);
		}

		return self::result(
			$ctx,
			'environment-load.memory-limit.parsing-monotonicity',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function memory_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < 10; ++$i ) {
			$cases[] = trim( (string) $ctx->int( 0, 4096 ) . $ctx->choice( array( '', 'K', 'k', 'M', 'm', 'G', 'g', 'MB', ' garbage' ) ) );
		}
		return $cases;
	}

	private static function expected_hr_to_bytes( string $value ) {
		$value = strtolower( trim( $value ) );
		$bytes = (int) $value;

		if ( str_contains( $value, 'g' ) ) {
			$bytes *= GB_IN_BYTES;
		} elseif ( str_contains( $value, 'm' ) ) {
			$bytes *= MB_IN_BYTES;
		} elseif ( str_contains( $value, 'k' ) ) {
			$bytes *= KB_IN_BYTES;
		}

		return min( $bytes, PHP_INT_MAX );
	}

	private static function check_ini_mutability( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$ini_all  = function_exists( 'ini_get_all' ) ? ini_get_all() : false;
		$settings = array(
			'memory_limit',
			'display_errors',
			'max_execution_time',
			'component_fuzz_unknown_' . strtolower( $ctx->identifier( 4, 8 ) ),
		);

		foreach ( $settings as $setting ) {
			$actual = \wp_is_ini_value_changeable( $setting );

			if ( isset( $ini_all[ $setting ]['access'] ) ) {
				$expected = INI_ALL === $ini_all[ $setting ]['access'] || INI_USER === $ini_all[ $setting ]['access'];
			} elseif ( ! is_array( $ini_all ) ) {
				$expected = true;
			} else {
				$expected = false;
			}

			self::collect_failure(
				$failures,
				$actual === $expected,
				"wp_is_ini_value_changeable {$setting}",
				array(
					'setting'  => $setting,
					'expected' => $expected,
					'actual'   => $actual,
					'access'   => $ini_all[ $setting ]['access'] ?? null,
				)
			);
		}

		return self::result(
			$ctx,
			'environment-load.ini-mutability.oracle',
			array() === $failures,
			array(
				'cases'    => count( $settings ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_installing_and_maintenance_flags( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$failures = array();
		$original = \wp_installing();

		$previous_before_true = \wp_installing( true );
		$after_true           = \wp_installing();
		$maintenance_install  = \wp_is_maintenance_mode();
		$previous_before_false = \wp_installing( false );
		$after_false           = \wp_installing();
		\wp_installing( $snapshot['installing'] );

		self::collect_failure(
			$failures,
			$previous_before_true === $original
				&& true === $after_true
				&& false === $maintenance_install
				&& true === $previous_before_false
				&& false === $after_false
				&& \wp_installing() === $snapshot['installing'],
			'wp_installing previous-value contract and restore',
			array(
				'original'            => $original,
				'previousBeforeTrue'  => $previous_before_true,
				'afterTrue'           => $after_true,
				'maintenanceInstall'  => $maintenance_install,
				'previousBeforeFalse' => $previous_before_false,
				'afterFalse'          => $after_false,
				'restored'            => \wp_installing(),
			)
		);

		if ( ! file_exists( ABSPATH . '.maintenance' ) ) {
			self::collect_failure(
				$failures,
				false === \wp_is_maintenance_mode(),
				'wp_is_maintenance_mode absent file fails closed',
				array( 'actual' => \wp_is_maintenance_mode() )
			);
		}

		return self::result(
			$ctx,
			'environment-load.installing-maintenance.flags',
			array() === $failures,
			array(
				'maintenanceFilePresent' => file_exists( ABSPATH . '.maintenance' ),
				'failures'               => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_runtime_filters_and_request_guards( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures         = array();
		$original_request = $_REQUEST;

		$filter_checks = array(
			'wp_doing_ajax'   => static fn() => \wp_doing_ajax(),
			'wp_doing_cron'   => static fn() => \wp_doing_cron(),
			'wp_using_themes' => static fn() => \wp_using_themes(),
		);

		foreach ( $filter_checks as $hook => $call ) {
			foreach ( array( true, false ) as $forced ) {
				$filter = static fn() => $forced;
				\add_filter( $hook, $filter );
				try {
					$actual = $call();
				} finally {
					\remove_filter( $hook, $filter );
				}

				self::collect_failure(
					$failures,
					$actual === $forced && false === \has_filter( $hook, $filter ),
					"{$hook} scoped filter {$forced}",
					array(
						'hook'        => $hook,
						'forced'      => $forced,
						'actual'      => $actual,
						'filterAfter' => \has_filter( $hook, $filter ),
					)
				);
			}
		}

		$file_context = 'environment-load-' . strtolower( $ctx->identifier( 4, 8 ) );
		$file_other_baseline = \wp_is_file_mod_allowed( 'unrelated-context' );
		$file_filter = static function ( bool $allowed, string $context ) use ( $file_context ): bool {
			return $context === $file_context ? false : $allowed;
		};
		\add_filter( 'file_mod_allowed', $file_filter, 10, 2 );
		try {
			$file_denied = \wp_is_file_mod_allowed( $file_context );
			$file_other  = \wp_is_file_mod_allowed( 'unrelated-context' );
		} finally {
			\remove_filter( 'file_mod_allowed', $file_filter, 10 );
		}

		self::collect_failure(
			$failures,
			false === $file_denied
				&& $file_other_baseline === $file_other
				&& false === \has_filter( 'file_mod_allowed', $file_filter ),
			'wp_is_file_mod_allowed filter locality',
			array(
				'context'     => $file_context,
				'denied'      => $file_denied,
				'other'       => $file_other,
				'otherBase'   => $file_other_baseline,
				'filterAfter' => \has_filter( 'file_mod_allowed', $file_filter ),
			)
		);

		$ajax_filter = static fn() => true;
		$custom_action = 'cfz_' . strtolower( $ctx->identifier( 4, 10 ) );
		$action_filter = static function ( array $actions ) use ( $custom_action ): array {
			$actions[] = $custom_action;
			return $actions;
		};

		try {
			\add_filter( 'wp_doing_ajax', $ajax_filter );
			$_REQUEST = array( 'action' => 'heartbeat' );
			$default_protected = \is_protected_ajax_action();
			$_REQUEST = array( 'action' => 'unprotected-' . $custom_action );
			$unknown_protected = \is_protected_ajax_action();
			\add_filter( 'wp_protected_ajax_actions', $action_filter );
			$_REQUEST = array( 'action' => $custom_action );
			$custom_protected = \is_protected_ajax_action();
		} finally {
			\remove_filter( 'wp_protected_ajax_actions', $action_filter );
			\remove_filter( 'wp_doing_ajax', $ajax_filter );
			$_REQUEST = $original_request;
		}

		self::collect_failure(
			$failures,
			true === $default_protected
				&& false === $unknown_protected
				&& true === $custom_protected
				&& false === \has_filter( 'wp_doing_ajax', $ajax_filter )
				&& false === \has_filter( 'wp_protected_ajax_actions', $action_filter ),
			'is_protected_ajax_action default/custom/filter locality',
			array(
				'defaultProtected' => $default_protected,
				'unknownProtected' => $unknown_protected,
				'customProtected'  => $custom_protected,
				'customAction'     => $custom_action,
				'ajaxFilterAfter'  => \has_filter( 'wp_doing_ajax', $ajax_filter ),
				'actionFilterAfter' => \has_filter( 'wp_protected_ajax_actions', $action_filter ),
			)
		);

		return self::result(
			$ctx,
			'environment-load.request-sapi-guards.filters',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_json_xml_request_guards( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$original_server = $_SERVER;
		$original_get    = $_GET;

		$media_cases = array(
			'application/json'                  => true,
			'application/activity+json'         => true,
			'application/json+oembed'           => true,
			'text/html, application/json;q=0.9' => true,
			'text/json'                         => false,
			'application/jsonp'                 => false,
			$ctx->ascii( 0, 18 )                => false,
		);

		try {
			foreach ( $media_cases as $media_type => $expected ) {
				$actual = \wp_is_json_media_type( $media_type );
				self::collect_failure(
					$failures,
					$actual === $expected,
					"wp_is_json_media_type {$media_type}",
					array(
						'mediaType' => $media_type,
						'expected'  => $expected,
						'actual'    => $actual,
					)
				);
			}

			$request_cases = array(
				array(
					'label'        => 'accept-json',
					'server'       => array( 'HTTP_ACCEPT' => 'text/html, application/json' ),
					'jsonExpected' => true,
					'xmlExpected'  => false,
				),
				array(
					'label'        => 'content-type-json',
					'server'       => array( 'CONTENT_TYPE' => 'application/problem+json; charset=UTF-8' ),
					'jsonExpected' => true,
					'xmlExpected'  => false,
				),
				array(
					'label'        => 'accept-xml',
					'server'       => array( 'HTTP_ACCEPT' => 'text/html, application/rss+xml' ),
					'jsonExpected' => false,
					'xmlExpected'  => true,
				),
				array(
					'label'        => 'content-type-xml-exact',
					'server'       => array( 'CONTENT_TYPE' => 'text/xml' ),
					'jsonExpected' => false,
					'xmlExpected'  => true,
				),
				array(
					'label'        => 'content-type-xml-with-charset-fails-closed',
					'server'       => array( 'CONTENT_TYPE' => 'text/xml; charset=UTF-8' ),
					'jsonExpected' => false,
					'xmlExpected'  => false,
				),
			);

			foreach ( $request_cases as $case ) {
				$_SERVER = array_merge( self::default_server(), $case['server'] );
				$json    = \wp_is_json_request();
				$xml     = \wp_is_xml_request();
				self::collect_failure(
					$failures,
					$json === $case['jsonExpected'] && $xml === $case['xmlExpected'],
					"JSON/XML request guard {$case['label']}",
					array(
						'case'         => $case['label'],
						'jsonExpected' => $case['jsonExpected'],
						'jsonActual'   => $json,
						'xmlExpected'  => $case['xmlExpected'],
						'xmlActual'    => $xml,
					)
				);
			}

			$_GET = array( '_jsonp' => 'componentFuzzCallback_' . $ctx->int( 1, 999 ) );
			$jsonp_enabled = \wp_is_jsonp_request();
			$disable_jsonp = static fn() => false;
			\add_filter( 'rest_jsonp_enabled', $disable_jsonp );
			try {
				$jsonp_disabled = \wp_is_jsonp_request();
			} finally {
				\remove_filter( 'rest_jsonp_enabled', $disable_jsonp );
			}
			$_GET = array( '_jsonp' => 'bad-callback()' );
			$jsonp_invalid = \wp_is_jsonp_request();

			self::collect_failure(
				$failures,
				true === $jsonp_enabled
					&& false === $jsonp_disabled
					&& false === $jsonp_invalid
					&& false === \has_filter( 'rest_jsonp_enabled', $disable_jsonp ),
				'wp_is_jsonp_request callback validation and filter locality',
				array(
					'enabled'     => $jsonp_enabled,
					'disabled'    => $jsonp_disabled,
					'invalid'     => $jsonp_invalid,
					'filterAfter' => \has_filter( 'rest_jsonp_enabled', $disable_jsonp ),
				)
			);
		} finally {
			$_SERVER = $original_server;
			$_GET    = $original_get;
		}

		return self::result(
			$ctx,
			'environment-load.json-xml-request-guards.media-types',
			array() === $failures,
			array(
				'mediaCases' => count( $media_cases ),
				'failures'   => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_generated_request_media_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$original_server = $_SERVER;
		$cases           = self::request_media_header_cases( $ctx );
		$overlap_cases   = 0;

		try {
			foreach ( $cases as $index => $case ) {
				$_SERVER = self::default_server();

				if ( null !== $case['accept'] ) {
					$_SERVER['HTTP_ACCEPT'] = $case['accept'];
				}

				if ( null !== $case['contentType'] ) {
					$_SERVER['CONTENT_TYPE'] = $case['contentType'];
				}

				$server_before = $_SERVER;
				$expected      = array(
					'jsonMediaAccept'  => null !== $case['accept'] && self::expected_json_media_type( $case['accept'] ),
					'jsonMediaContent' => null !== $case['contentType'] && self::expected_json_media_type( $case['contentType'] ),
					'jsonRequest'      => ( null !== $case['accept'] && self::expected_json_media_type( $case['accept'] ) )
						|| ( null !== $case['contentType'] && self::expected_json_media_type( $case['contentType'] ) ),
					'xmlRequest'       => self::expected_xml_accept_header( $case['accept'] )
						|| self::expected_xml_content_type( $case['contentType'] ),
				);
				$actual        = array(
					'jsonMediaAccept'  => null !== $case['accept'] ? \wp_is_json_media_type( $case['accept'] ) : false,
					'jsonMediaContent' => null !== $case['contentType'] ? \wp_is_json_media_type( $case['contentType'] ) : false,
					'jsonRequest'      => \wp_is_json_request(),
					'xmlRequest'       => \wp_is_xml_request(),
				);
				$repeat        = array(
					'jsonRequest' => \wp_is_json_request(),
					'xmlRequest'  => \wp_is_xml_request(),
				);

				if ( $expected['jsonRequest'] && $expected['xmlRequest'] ) {
					++$overlap_cases;
				}

				self::collect_failure(
					$failures,
					$actual === $expected
						&& $repeat['jsonRequest'] === $actual['jsonRequest']
						&& $repeat['xmlRequest'] === $actual['xmlRequest']
						&& $_SERVER === $server_before,
					"Generated JSON/XML request media matrix case {$index}",
					array(
						'label'        => $case['label'],
						'accept'       => $case['accept'],
						'contentType'  => $case['contentType'],
						'expected'     => $expected,
						'actual'       => $actual,
						'repeat'       => $repeat,
						'serverBefore' => $server_before,
						'serverAfter'  => $_SERVER,
					)
				);
			}
		} finally {
			$_SERVER = $original_server;
		}

		return self::result(
			$ctx,
			'environment-load.request-media.generated-json-xml-matrix',
			array() === $failures,
			array(
				'cases'          => count( $cases ),
				'generatedCases' => self::REQUEST_MEDIA_GENERATED_CASES,
				'overlapCases'   => $overlap_cases,
				'failures'       => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function request_media_header_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'       => 'json-accept-uppercase',
				'accept'      => 'text/html, APPLICATION/VND.WP+JSON; q=0.9',
				'contentType' => null,
			),
			array(
				'label'       => 'json-content-type-with-charset',
				'accept'      => null,
				'contentType' => 'application/problem+json; charset=UTF-8',
			),
			array(
				'label'       => 'xml-accept-with-json-content-type',
				'accept'      => 'text/html, application/rss+xml',
				'contentType' => 'application/json',
			),
			array(
				'label'       => 'json-accept-with-exact-xml-content-type',
				'accept'      => 'application/json;q=1.0',
				'contentType' => 'text/xml',
			),
			array(
				'label'       => 'xml-content-type-parameters-fail-closed',
				'accept'      => 'text/html',
				'contentType' => 'text/xml; charset=UTF-8',
			),
			array(
				'label'       => 'xml-case-sensitive-negative',
				'accept'      => 'application/RSS+XML',
				'contentType' => 'TEXT/XML',
			),
			array(
				'label'       => 'json-oembed-vendor-overlap',
				'accept'      => 'application/vnd.component+json+oembed',
				'contentType' => 'application/xml+oembed',
			),
			array(
				'label'       => 'non-json-lookalikes',
				'accept'      => 'application/jsonp, text/json, application/json+xml',
				'contentType' => 'application/hal+json+zip',
			),
		);

		for ( $i = 0; $i < self::REQUEST_MEDIA_GENERATED_CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'case-' . $i );
			$cases[]  = array(
				'label'       => 'generated-' . $i,
				'accept'      => self::generated_media_header( $case_ctx->fork( 'accept' ), true ),
				'contentType' => self::generated_media_header( $case_ctx->fork( 'content-type' ), false ),
			);
		}

		return $cases;
	}

	private static function generated_media_header( \ComponentFuzz\FuzzContext $ctx, bool $allow_list ): ?string {
		if ( $ctx->bool( 20 ) ) {
			return null;
		}

		$json_subtype = $ctx->choice(
			array(
				'json',
				self::media_token( $ctx->fork( 'json-vendor' ) ) . '+json',
				'json+oembed',
				self::media_token( $ctx->fork( 'json-oembed-vendor' ) ) . '+json+oembed',
			)
		);
		$pool = array(
			'application/' . $json_subtype,
			'application/' . $json_subtype . '; charset=UTF-8',
			'application/' . strtoupper( $json_subtype ),
			'text/html',
			'text/json',
			'application/jsonp',
			'application/json+xml',
			'application/' . self::media_token( $ctx->fork( 'bad-json' ) ) . '+json+zip',
			'text/xml',
			'application/rss+xml',
			'application/atom+xml',
			'application/rdf+xml',
			'application/xml+oembed',
			'TEXT/XML',
			$ctx->ascii( 0, 18 ),
		);

		if ( ! $allow_list ) {
			return $ctx->choice( $pool );
		}

		$parts = array();
		$count = $ctx->int( 1, 4 );
		for ( $i = 0; $i < $count; ++$i ) {
			$part = $ctx->choice( $pool );
			if ( $ctx->bool( 40 ) ) {
				$part .= '; q=0.' . $ctx->int( 1, 9 );
			}
			$parts[] = $part;
		}

		return implode( $ctx->choice( array( ', ', ',', ' , ' ) ), $parts );
	}

	private static function expected_json_media_type( string $header ): bool {
		foreach ( explode( ',', $header ) as $part ) {
			$media_type = strtolower( trim( explode( ';', $part, 2 )[0] ) );
			if ( '' === $media_type ) {
				continue;
			}

			$pieces = explode( '/', $media_type, 2 );
			if ( 2 !== count( $pieces ) || 'application' !== $pieces[0] ) {
				continue;
			}

			$subtype = $pieces[1];
			if ( 'json' === $subtype || 'json+oembed' === $subtype ) {
				return true;
			}

			if ( self::ends_with( $subtype, '+json' ) || self::ends_with( $subtype, '+json+oembed' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function expected_xml_accept_header( ?string $header ): bool {
		if ( null === $header ) {
			return false;
		}

		foreach ( self::xml_media_types() as $type ) {
			if ( str_contains( $header, $type ) ) {
				return true;
			}
		}

		return false;
	}

	private static function expected_xml_content_type( ?string $content_type ): bool {
		return null !== $content_type && in_array( $content_type, self::xml_media_types(), true );
	}

	private static function xml_media_types(): array {
		return array(
			'text/xml',
			'application/rss+xml',
			'application/atom+xml',
			'application/rdf+xml',
			'text/xml+oembed',
			'application/xml+oembed',
		);
	}

	private static function media_token( \ComponentFuzz\FuzzContext $ctx ): string {
		$token = strtolower( preg_replace( '/[^a-zA-Z0-9.-]+/', '', $ctx->identifier( 3, 10 ) ) );

		return '' === $token ? 'component' : $token;
	}

	private static function ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}

		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	private static function check_https_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows     = array();
		$failures = array();
		$original_server = $_SERVER;

		try {
			$_SERVER = self::default_server();

			foreach ( self::https_url_cases() as $index => $case ) {
				\update_option( 'home', $case['home'] );
				\update_option( 'siteurl', $case['siteurl'] );

				$home_using_https = \wp_is_home_url_using_https();
				$site_using_https = \wp_is_site_url_using_https();
				$using_https      = \wp_is_using_https();

				self::collect_failure(
					$failures,
					$home_using_https === $case['homeHttps']
						&& $site_using_https === $case['siteHttps']
						&& $using_https === ( $case['homeHttps'] && $case['siteHttps'] ),
					"HTTPS URL state case {$index}",
					array(
						'case'            => $case,
						'homeUsingHttps'  => $home_using_https,
						'siteUsingHttps'  => $site_using_https,
						'usingHttps'      => $using_https,
					)
				);
			}

			\update_option( 'home', 'https://example.test' );
			\update_option( 'siteurl', 'https://example.test/wp' );
			\update_option( 'https_migration_required', true );

			$content  = 'Visit http://example.test/path and escaped http:\/\/example.test\/path but not http://other.test/path.';
			$replaced = \wp_replace_insecure_home_url( $content );
			$should_replace = \wp_should_replace_insecure_home_url();
			$block_replacement = static fn() => false;
			\add_filter( 'wp_should_replace_insecure_home_url', $block_replacement );
			try {
				$blocked = \wp_replace_insecure_home_url( $content );
			} finally {
				\remove_filter( 'wp_should_replace_insecure_home_url', $block_replacement );
			}

			self::collect_failure(
				$failures,
				true === $should_replace
					&& str_contains( $replaced, 'https://example.test/path' )
					&& str_contains( $replaced, 'https:\/\/example.test\/path' )
					&& str_contains( $replaced, 'http://other.test/path' )
					&& $blocked === $content
					&& false === \has_filter( 'wp_should_replace_insecure_home_url', $block_replacement ),
				'wp_replace_insecure_home_url scoped replacement',
				array(
					'shouldReplace' => $should_replace,
					'replaced'      => $replaced,
					'blocked'       => $blocked,
					'filterAfter'   => \has_filter( 'wp_should_replace_insecure_home_url', $block_replacement ),
				)
			);

			$error_filter = static function () {
				return new \WP_Error( 'synthetic_https_failure', 'Synthetic HTTPS failure.' );
			};
			\add_filter( 'pre_wp_get_https_detection_errors', $error_filter );
			try {
				$errors    = \wp_get_https_detection_errors();
				$supported = \wp_is_https_supported();
			} finally {
				\remove_filter( 'pre_wp_get_https_detection_errors', $error_filter );
			}

			$success_filter = static function () {
				return new \WP_Error();
			};
			\add_filter( 'pre_wp_get_https_detection_errors', $success_filter );
			try {
				$success_errors    = \wp_get_https_detection_errors();
				$success_supported = \wp_is_https_supported();
			} finally {
				\remove_filter( 'pre_wp_get_https_detection_errors', $success_filter );
			}

			self::collect_failure(
				$failures,
				isset( $errors['synthetic_https_failure'] )
					&& false === $supported
					&& array() === $success_errors
					&& true === $success_supported
					&& false === \has_filter( 'pre_wp_get_https_detection_errors', $error_filter )
					&& false === \has_filter( 'pre_wp_get_https_detection_errors', $success_filter ),
				'wp_get_https_detection_errors short-circuit avoids network',
				array(
					'errors'           => array_keys( $errors ),
					'supported'        => $supported,
					'successErrors'    => $success_errors,
					'successSupported' => $success_supported,
				)
			);
		} finally {
			$_SERVER = $original_server;
		}

		$rows[] = self::result(
			$ctx,
			'environment-load.https.urls-migration-detection',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);

		$rows[] = self::check_local_html_output( $ctx->fork( 'local-html' ) );

		return $rows;
	}

	private static function https_url_cases(): array {
		return array(
			array(
				'home'      => 'https://example.test',
				'siteurl'   => 'https://example.test/wp',
				'homeHttps' => true,
				'siteHttps' => true,
			),
			array(
				'home'      => 'http://example.test',
				'siteurl'   => 'https://example.test/wp',
				'homeHttps' => false,
				'siteHttps' => true,
			),
			array(
				'home'      => 'https://example.test',
				'siteurl'   => 'http://example.test/wp',
				'homeHttps' => true,
				'siteHttps' => false,
			),
			array(
				'home'      => 'http://example.test',
				'siteurl'   => 'http://example.test/wp',
				'homeHttps' => false,
				'siteHttps' => false,
			),
		);
	}

	private static function check_local_html_output( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'rsd_link' ) ) {
			return $ctx->skip(
				'environment-load.https.local-html-output',
				'rsd_link() is unavailable, so local HTML ownership detection cannot be exercised.'
			);
		}

		$failures = array();
		$no_signal = \wp_is_local_html_output( '<html><head></head><body>component fuzz</body></html>' );
		\add_action( 'wp_head', 'rsd_link' );
		try {
			$pattern = preg_replace( '#^https?:(?=//)#', '', esc_url( site_url( 'xmlrpc.php?rsd', 'rpc' ) ) );
			$local   = \wp_is_local_html_output( '<html><head><link rel="EditURI" href="' . $pattern . '" /></head></html>' );
			$foreign = \wp_is_local_html_output( '<html><head><link rel="EditURI" href="//foreign.test/xmlrpc.php?rsd" /></head></html>' );
		} finally {
			\remove_action( 'wp_head', 'rsd_link' );
		}

		self::collect_failure(
			$failures,
			null === $no_signal
				&& true === $local
				&& false === $foreign
				&& false === \has_filter( 'wp_head', 'rsd_link' ),
			'wp_is_local_html_output RSD signal detection',
			array(
				'noSignal'    => $no_signal,
				'local'       => $local,
				'foreign'     => $foreign,
				'pattern'     => $pattern ?? null,
				'actionAfter' => \has_filter( 'wp_head', 'rsd_link' ),
			)
		);

		return self::result(
			$ctx,
			'environment-load.https.local-html-output',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_utf8_validation_and_scanning( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::utf8_cases( $ctx );

		foreach ( $cases as $index => $bytes ) {
			$valid          = \wp_is_valid_utf8( $bytes );
			$fallback_valid = \_wp_is_valid_utf8_fallback( $bytes );
			$scrubbed       = \wp_scrub_utf8( $bytes );
			$rescrubbed     = \wp_scrub_utf8( $scrubbed );
			$count          = \_wp_utf8_codepoint_count( $bytes );
			$scrubbed_count = \_wp_utf8_codepoint_count( $scrubbed );
			$found          = 0;
			$span           = \_wp_utf8_codepoint_span( $scrubbed, 0, $scrubbed_count, $found );
			$scan           = self::scan_utf8_case( $bytes );

			self::collect_failure(
				$failures,
				$valid === $fallback_valid
					&& \wp_is_valid_utf8( $scrubbed )
					&& $scrubbed === $rescrubbed
					&& $count === $scrubbed_count
					&& $span === strlen( $scrubbed )
					&& $found === $scrubbed_count
					&& $scan['ok']
					&& $scan['count'] === $count
					&& $scan['hasNoncharacters'] === \wp_has_noncharacters( $bytes ),
				"UTF-8 validation/scrub/scan agreement case {$index}",
				array(
					'bytes'             => $bytes,
					'valid'             => $valid,
					'fallbackValid'     => $fallback_valid,
					'scrubbed'          => $scrubbed,
					'count'             => $count,
					'scrubbedCount'     => $scrubbed_count,
					'span'              => $span,
					'found'             => $found,
					'scan'              => $scan,
					'hasNoncharacters'  => \wp_has_noncharacters( $bytes ),
				)
			);
		}

		self::collect_failure(
			$failures,
			true === \wp_has_noncharacters( "\xEF\xBF\xBE" )
				&& true === \wp_has_noncharacters( "\xF4\x8F\xBF\xBF" )
				&& false === \wp_has_noncharacters( 'plain text' )
				&& false === \wp_has_noncharacters( "invalid \xC0 byte" ),
			'wp_has_noncharacters detects only noncharacter byte sequences',
			array(
				'fffe'    => \wp_has_noncharacters( "\xEF\xBF\xBE" ),
				'max'     => \wp_has_noncharacters( "\xF4\x8F\xBF\xBF" ),
				'plain'   => \wp_has_noncharacters( 'plain text' ),
				'invalid' => \wp_has_noncharacters( "invalid \xC0 byte" ),
			)
		);

		return self::result(
			$ctx,
			'environment-load.utf8.validation-scrub-scan',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function utf8_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			'',
			'plain ascii',
			"Pi\xC3\xB1a",
			"snowman \xE2\x98\x83",
			"invalid \xC0 byte",
			"truncated \xE2\x9C",
			"surrogate \xED\xA0\x80",
			"nonchar \xEF\xBF\xBE",
		);

		for ( $i = 0; $i < self::UTF8_CASES; ++$i ) {
			$cases[] = $ctx->bytes( 0, 24 );
		}

		return $cases;
	}

	private static function scan_utf8_case( string $bytes ): array {
		$length            = strlen( $bytes );
		$at                = 0;
		$count             = 0;
		$invalid_length    = 0;
		$has_noncharacters = false;
		$guard             = 0;

		while ( $at < $length ) {
			$before          = $at;
			$scan_has_nonchar = false;
			$found           = \_wp_scan_utf8( $bytes, $at, $invalid_length, null, null, $scan_has_nonchar );
			$count          += $found;
			$has_noncharacters = $has_noncharacters || $scan_has_nonchar;

			if ( $invalid_length > 0 ) {
				++$count;
				$at += $invalid_length;
			}

			if ( $at <= $before && 0 === $invalid_length ) {
				return array(
					'ok'      => false,
					'count'   => $count,
					'at'      => $at,
					'before'  => $before,
					'reason'  => 'scan did not advance',
				);
			}

			if ( ++$guard > $length + 4 ) {
				return array(
					'ok'     => false,
					'count'  => $count,
					'at'     => $at,
					'reason' => 'scan guard exceeded',
				);
			}
		}

		return array(
			'ok'               => true,
			'count'            => $count,
			'at'               => $at,
			'hasNoncharacters' => $has_noncharacters,
		);
	}

	private static function check_utf8_compat_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$codepoints = array(
			0x00,
			0x24,
			0x7F,
			0x80,
			0x7FF,
			0x800,
			0xFDD0,
			0x1F600,
			$ctx->int( 0x100, 0x2FFF ),
		);

		foreach ( $codepoints as $codepoint ) {
			$chr = \_mb_chr( $codepoint, 'UTF-8' );
			$ord = false !== $chr ? \_mb_ord( $chr, 'UTF-8' ) : false;
			$wrapped = false !== $chr ? 'A' . $chr . 'B' : '';

			self::collect_failure(
				$failures,
				is_string( $chr )
					&& $ord === $codepoint
					&& 1 === \_mb_strlen( $chr, 'UTF-8' )
					&& $chr === \_mb_substr( $wrapped, 1, 1, 'UTF-8' ),
				"_mb_chr/_mb_ord round trip {$codepoint}",
				array(
					'codepoint' => $codepoint,
					'chr'       => $chr,
					'ord'       => $ord,
					'substr'    => false !== $chr ? \_mb_substr( $wrapped, 1, 1, 'UTF-8' ) : null,
				)
			);
		}

		foreach ( array( -1, 0xD800, 0xDFFF, 0x110000 ) as $invalid ) {
			self::collect_failure(
				$failures,
				false === \_mb_chr( $invalid, 'UTF-8' ),
				"_mb_chr rejects invalid codepoint {$invalid}",
				array(
					'codepoint' => $invalid,
					'actual'    => \_mb_chr( $invalid, 'UTF-8' ),
				)
			);
		}

		$charset_cases = array(
			'UTF-8'        => true,
			'utf8'         => true,
			'Utf-8'        => true,
			'UTF 8'        => false,
			'ISO-8859-1'   => false,
			$ctx->ascii( 0, 12 ) => false,
		);
		foreach ( $charset_cases as $charset => $expected ) {
			$actual = \_is_utf8_charset( $charset );
			self::collect_failure(
				$failures,
				$actual === $expected,
				"_is_utf8_charset {$charset}",
				array(
					'charset'  => $charset,
					'expected' => $expected,
					'actual'   => $actual,
				)
			);
		}

		$latin1  = "ASCII \xA3 \xF1 " . chr( $ctx->int( 0x80, 0xFF ) );
		$encoded = \_wp_utf8_encode_fallback( $latin1 );
		$decoded = \_wp_utf8_decode_fallback( $encoded );
		$invalid_decoded = \_wp_utf8_decode_fallback( "bad \xC0 utf8" );

		self::collect_failure(
			$failures,
			\wp_is_valid_utf8( $encoded )
				&& $decoded === $latin1
				&& is_string( $invalid_decoded ),
			'_wp_utf8_encode_fallback/_wp_utf8_decode_fallback latin1 round trip',
			array(
				'latin1'         => $latin1,
				'encoded'        => $encoded,
				'decoded'        => $decoded,
				'invalidDecoded' => $invalid_decoded,
			)
		);

		$sample = \wp_scrub_utf8( $ctx->text( 4, 28 ) );
		$start  = $ctx->int( -2, 4 );
		$length = $ctx->int( 0, 6 );
		$substr = \_mb_substr( $sample, $start, $length, 'UTF-8' );
		self::collect_failure(
			$failures,
			\wp_is_valid_utf8( $substr )
				&& \_mb_strlen( $substr, 'UTF-8' ) <= $length,
			'_mb_substr bounded UTF-8 output',
			array(
				'sample' => $sample,
				'start'  => $start,
				'length' => $length,
				'substr' => $substr,
				'strlen' => \_mb_strlen( $substr, 'UTF-8' ),
			)
		);

		return self::result(
			$ctx,
			'environment-load.utf8.compat-helpers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function default_server(): array {
		return array(
			'HTTP_HOST'       => 'example.test',
			'PHP_SELF'        => '/index.php',
			'REQUEST_URI'     => '/component-fuzz/',
			'SCRIPT_NAME'     => '/index.php',
			'SERVER_PROTOCOL' => 'HTTP/1.1',
			'SERVER_SOFTWARE' => 'ComponentFuzz',
		);
	}

	private static function safe_basic_auth_token( string $token ): string {
		$token = strtolower( preg_replace( '/[^a-zA-Z0-9_.-]+/', '_', $token ) );
		$token = trim( (string) $token, '_' );

		return '' === $token ? 'token' : $token;
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach ( array( 'PHP_SELF', 'current_screen', 'pagenow', 'wp_actions', 'wp_current_filter', 'wp_filter', 'wp_filters' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		$superglobals = array();
		foreach ( array( '_COOKIE', '_ENV', '_GET', '_POST', '_REQUEST', '_SERVER' ) as $name ) {
			$superglobals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'globals'      => $globals,
			'superglobals' => $superglobals,
			'env'          => function_exists( 'getenv' ) ? getenv( 'WP_ENVIRONMENT_TYPE' ) : false,
			'installing'   => function_exists( 'wp_installing' ) ? \wp_installing() : false,
			'options'      => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['superglobals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		if ( function_exists( 'putenv' ) ) {
			self::set_env_var( 'WP_ENVIRONMENT_TYPE', $snapshot['env'] );
		}

		if ( function_exists( 'wp_installing' ) ) {
			\wp_installing( $snapshot['installing'] );
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub && is_array( $snapshot['options'] ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		if ( ( function_exists( 'getenv' ) ? getenv( 'WP_ENVIRONMENT_TYPE' ) : false ) !== $snapshot['env'] ) {
			return false;
		}

		if ( function_exists( 'wp_installing' ) && \wp_installing() !== $snapshot['installing'] ) {
			return false;
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub && is_array( $snapshot['options'] ) ) {
			if ( $GLOBALS['wpdb']->component_fuzz_get_options() !== $snapshot['options'] ) {
				return false;
			}
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
			if ( $entry['exists'] && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		foreach ( $snapshot['superglobals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
			if ( $entry['exists'] && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function set_env_var( string $name, $value ): void {
		if ( false === $value ) {
			putenv( $name );
			return;
		}

		putenv( $name . '=' . (string) $value );
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
			if ( $value instanceof \Closure ) {
				return $value;
			}

			try {
				return clone $value;
			} catch ( \Throwable $e ) {
				return $value;
			}
		}

		return $value;
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
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
