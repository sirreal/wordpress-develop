<?php
namespace ComponentFuzz\Surfaces;

final class EmailSurface {
	public const NAME = 'email';

	private const GENERATED_CASES = 32;
	private const GENERATED_VIEW_CASES = 10;
	private const GENERATED_DOMAIN_ALIAS_CASES = 8;
	private const GENERATED_LOCALPART_ALIAS_CASES = 8;
	private const GENERATED_UNICODE_MATRIX_CASES = 12;
	private const GENERATED_MALFORMED_VARIANT_CASES = 12;
	private const GENERATED_UTF8_LOCALPART_ORACLE_CASES = 16;
	private const GENERATED_USER_SEARCH_CASES = 8;
	private const WHATWG_ASCII_EMAIL_REGEX = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@'
		. '[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?'
		. '(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'email.bootstrap-apis-available',
					'Required WordPress email APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$unicode_missing = self::missing_unicode_requirements();
		if ( array() !== $unicode_missing ) {
			return array_merge(
				self::check_core_ascii_email_baseline( $ctx ),
				array(
					$ctx->skip(
						'email.unicode-email-optional-apis-available',
						'Optional WordPress Unicode email APIs are unavailable in this checkout.',
						array( 'missing' => implode( ', ', $unicode_missing ) )
					),
				)
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_hook_globals();

		try {
			$cases = self::cases( $ctx );

			$rows = array_merge( $rows, self::check_utf8mb4_filter_gate( $ctx ) );

			self::install_email_filters( 'unicode' );
			$rows[] = self::check_unicode_filters( $ctx );
			$rows = array_merge( $rows, self::check_direct_filter_callbacks( $ctx ) );
			$rows = array_merge( $rows, self::check_disabled_filter_fail_closed( $ctx ) );
			$rows = array_merge( $rows, self::check_whatwg_examples( $ctx ) );
			$rows = array_merge( $rows, self::check_whatwg_ascii_oracle( $ctx ) );
			$rows = array_merge( $rows, self::check_sanitizer_recovery( $ctx ) );
			$rows = array_merge( $rows, self::check_malformed_utf8_byte_matrix( $ctx ) );
			$rows = array_merge( $rows, self::check_unicode_localpart_byte_boundaries( $ctx ) );
			$rows = array_merge( $rows, self::check_generated_utf8_localpart_oracle( $ctx ) );
			$rows = array_merge( $rows, self::check_construction_mode_consistency( $ctx ) );
			$rows = array_merge( $rows, self::check_generated_unicode_filter_view_matrix( $ctx ) );
			$rows = array_merge( $rows, self::check_generated_malformed_variant_matrix( $ctx ) );

			foreach ( $cases as $case_index => $case ) {
				$rows = array_merge( $rows, self::check_unicode_case( $ctx, $case_index, $case ) );
			}

			$rows = array_merge( $rows, self::check_distinct_localparts( $ctx ) );
			$rows = array_merge( $rows, self::check_normalization_sensitive_localparts( $ctx ) );
			$rows = array_merge( $rows, self::check_comment_author_email_filters( $ctx ) );
			$rows = array_merge( $rows, self::check_rest_email_schema_filter_modes( $ctx ) );
			$rows = array_merge( $rows, self::check_user_email_indexes_distinct_localparts( $ctx ) );
			$rows = array_merge( $rows, self::check_user_email_indexes_generated_localpart_aliases( $ctx ) );
			$rows = array_merge( $rows, self::check_user_email_search_unicode_terms( $ctx ) );
			$rows = array_merge( $rows, self::check_user_email_indexes_distinct_domains( $ctx ) );
			$rows = array_merge( $rows, self::check_user_email_indexes_canonical_domain_aliases( $ctx ) );
			$rows = array_merge( $rows, self::check_password_reset_unicode_email_paths( $ctx ) );
			$rows = array_merge( $rows, self::check_punycode_views( $ctx ) );
			$rows = array_merge( $rows, self::check_idn_views( $ctx ) );
			$rows = array_merge( $rows, self::check_extension_address_views( $ctx ) );
			$rows = array_merge( $rows, self::check_make_clickable_email_rendering( $ctx ) );
			$rows = array_merge( $rows, self::check_length_boundaries( $ctx ) );

			self::install_email_filters( 'ascii' );
			$rows[] = self::check_ascii_filters( $ctx );

			foreach ( $cases as $case_index => $case ) {
				$rows = array_merge( $rows, self::check_ascii_case( $ctx, $case_index, $case ) );
			}
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'email.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_hook_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'add_filter',
				'has_filter',
				'remove_all_filters',
				'email_exists',
				'get_user_by',
				'is_email',
				'is_wp_error',
				'make_clickable',
				'sanitize_email',
				'wp_cache_flush',
				'wp_insert_user',
				'wp_is_valid_utf8',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_User' ) ) {
			$missing[] = 'class WP_User';
		}

		return $missing;
	}

	private static function missing_unicode_requirements(): array {
		$missing = array();
		foreach (
			array(
				'wp_is_unicode_email',
				'wp_sanitize_unicode_email',
				'wp_is_ascii_email',
				'wp_sanitize_ascii_email',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Email_Address' ) ) {
			$missing[] = 'class WP_Email_Address';
		}

		return $missing;
	}

	private static function check_core_ascii_email_baseline( \ComponentFuzz\FuzzContext $ctx ): array {
		$valid         = 'user+tag@example.com';
		$unicode       = "jos\u{00E9}@example.com";
		$invalid_cases = array(
			'missing-at'  => 'missing-at.example.com',
			'empty-local' => '@example.com',
			'empty-domain' => 'user@',
			'double-at'   => 'bad@@example.com',
		);
		$failures      = array();
		$observed      = array();

		$valid_is_email  = self::call( static fn() => \is_email( $valid ) );
		$valid_sanitized = self::call( static fn() => \sanitize_email( $valid ) );

		if (
			$valid_is_email['threw'] ||
			$valid_sanitized['threw'] ||
			$valid !== $valid_is_email['value'] ||
			$valid !== $valid_sanitized['value']
		) {
			$failures[] = array(
				'label'         => 'ascii-valid',
				'input'         => self::describe_string( $valid ),
				'isEmail'       => self::describe_call( $valid_is_email ),
				'sanitizeEmail' => self::describe_call( $valid_sanitized ),
			);
		}

		$observed[] = array(
			'label'         => 'ascii-valid',
			'input'         => self::describe_string( $valid ),
			'isEmail'       => self::describe_call( $valid_is_email ),
			'sanitizeEmail' => self::describe_call( $valid_sanitized ),
		);

		foreach ( $invalid_cases as $label => $input ) {
			$is_email  = self::call( static fn() => \is_email( $input ) );
			$sanitized = self::call( static fn() => \sanitize_email( $input ) );

			if (
				$is_email['threw'] ||
				$sanitized['threw'] ||
				false !== $is_email['value'] ||
				'' !== $sanitized['value']
			) {
				$failures[] = array(
					'label'         => $label,
					'input'         => self::describe_string( $input ),
					'isEmail'       => self::describe_call( $is_email ),
					'sanitizeEmail' => self::describe_call( $sanitized ),
				);
			}

			$observed[] = array(
				'label'         => $label,
				'input'         => self::describe_string( $input ),
				'isEmail'       => self::describe_call( $is_email ),
				'sanitizeEmail' => self::describe_call( $sanitized ),
			);
		}

		$unicode_is_email  = self::call( static fn() => \is_email( $unicode ) );
		$unicode_sanitized = self::call( static fn() => \sanitize_email( $unicode ) );
		$observed[]        = array(
			'label'         => 'unicode-observed-without-optional-oracles',
			'input'         => self::describe_string( $unicode ),
			'isEmail'       => self::describe_call( $unicode_is_email ),
			'sanitizeEmail' => self::describe_call( $unicode_sanitized ),
		);

		return array(
			$ctx->result(
				'email.core-ascii-baseline-without-unicode-oracles',
				array() === $failures,
				array(
					'observed' => $observed,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_unicode_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$sample    = "gr\u{00E5}@example.org";
		$is_email  = self::call( static fn() => \is_email( $sample ) );
		$sanitized = self::call( static fn() => \sanitize_email( $sample ) );
		$ok        = ! $is_email['threw']
			&& ! $sanitized['threw']
			&& $sample === $is_email['value']
			&& $sample === $sanitized['value']
			&& 10 === \has_filter( 'is_email', 'wp_is_unicode_email' )
			&& 10 === \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' )
			&& false === \has_filter( 'is_email', 'wp_is_ascii_email' )
			&& false === \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' );

		return $ctx->result(
			'email.scoped-unicode-filters-active',
			$ok,
			array(
				'sample'        => self::describe_string( $sample ),
				'isEmail'       => self::describe_call( $is_email ),
				'sanitizeEmail' => self::describe_call( $sanitized ),
				'isFilter'      => \has_filter( 'is_email', 'wp_is_unicode_email' ),
				'sanitizeFilter' => \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' ),
			)
		);
	}

	private static function check_utf8mb4_filter_gate( \ComponentFuzz\FuzzContext $ctx ): array {
		$unicode  = "jos\u{00E9}@example.org";
		$ascii    = 'user@example.com';
		$charsets = array(
			array( 'charset' => 'utf8mb4', 'unicode' => true ),
			array( 'charset' => 'utf8', 'unicode' => false ),
			array( 'charset' => 'latin1', 'unicode' => false ),
			array( 'charset' => '', 'unicode' => false ),
		);
		$failures = array();
		$observed = array();

		$hook_snapshot = self::snapshot_hook_globals();
		$wpdb_snapshot = self::snapshot_wpdb_charset();

		try {
			foreach ( $charsets as $case ) {
				self::restore_hook_globals( $hook_snapshot );
				self::install_email_filters_from_default_filters( $case['charset'] );

				$is_unicode       = self::call( static fn() => \is_email( $unicode ) );
				$sanitize_unicode = self::call( static fn() => \sanitize_email( $unicode ) );
				$is_ascii         = self::call( static fn() => \is_email( $ascii ) );
				$sanitize_ascii   = self::call( static fn() => \sanitize_email( $ascii ) );
				$unicode_filter   = \has_filter( 'is_email', 'wp_is_unicode_email' );
				$unicode_sanitize = \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' );
				$ascii_filter     = \has_filter( 'is_email', 'wp_is_ascii_email' );
				$ascii_sanitize   = \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' );

				$expected_unicode          = $case['unicode'] ? $unicode : false;
				$expected_sanitize_unicode = $case['unicode'] ? $unicode : '';
				$expected_unicode_filter   = $case['unicode'] ? 10 : false;
				$expected_ascii_filter     = $case['unicode'] ? false : 10;
				$ok                        = ! $is_unicode['threw']
					&& ! $sanitize_unicode['threw']
					&& ! $is_ascii['threw']
					&& ! $sanitize_ascii['threw']
					&& $expected_unicode === $is_unicode['value']
					&& $expected_sanitize_unicode === $sanitize_unicode['value']
					&& $ascii === $is_ascii['value']
					&& $ascii === $sanitize_ascii['value']
					&& $expected_unicode_filter === $unicode_filter
					&& $expected_unicode_filter === $unicode_sanitize
					&& $expected_ascii_filter === $ascii_filter
					&& $expected_ascii_filter === $ascii_sanitize;

				if ( ! $ok ) {
					$failures[] = array(
						'charset'         => $case['charset'],
						'expectsUnicode'  => $case['unicode'],
						'isUnicode'       => self::describe_call( $is_unicode ),
						'sanitizeUnicode' => self::describe_call( $sanitize_unicode ),
						'isAscii'         => self::describe_call( $is_ascii ),
						'sanitizeAscii'   => self::describe_call( $sanitize_ascii ),
						'unicodeFilter'   => $unicode_filter,
						'unicodeSanitize' => $unicode_sanitize,
						'asciiFilter'     => $ascii_filter,
						'asciiSanitize'   => $ascii_sanitize,
					);
				}

				$observed[] = array(
					'charset'          => $case['charset'],
					'unicodeEnabled'   => $case['unicode'],
					'isEmailFilter'    => false !== $unicode_filter ? 'unicode' : 'ascii',
					'sanitizeFilter'   => false !== $unicode_sanitize ? 'unicode' : 'ascii',
					'unicodeAccepted'  => ! $is_unicode['threw'] && false !== $is_unicode['value'],
					'unicodeSanitized' => ! $sanitize_unicode['threw'] && '' !== $sanitize_unicode['value'],
				);
			}
		} finally {
			self::restore_hook_globals( $hook_snapshot );
			self::restore_wpdb_charset( $wpdb_snapshot );
		}

		return array(
			$ctx->result(
				'email.default-filters.utf8mb4-gate',
				array() === $failures,
				array(
					'unicode' => self::describe_string( $unicode ),
					'ascii'    => self::describe_string( $ascii ),
					'observed' => $observed,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_ascii_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$unicode   = "gr\u{00E5}@gr\u{00E5}.org";
		$ascii     = 'user@example.com';
		$is_ascii  = self::call( static fn() => \is_email( $ascii ) );
		$san_ascii = self::call( static fn() => \sanitize_email( $ascii ) );
		$is_uni    = self::call( static fn() => \is_email( $unicode ) );
		$san_uni   = self::call( static fn() => \sanitize_email( $unicode ) );
		$ok        = ! $is_ascii['threw']
			&& ! $san_ascii['threw']
			&& ! $is_uni['threw']
			&& ! $san_uni['threw']
			&& $ascii === $is_ascii['value']
			&& $ascii === $san_ascii['value']
			&& false === $is_uni['value']
			&& '' === $san_uni['value']
			&& 10 === \has_filter( 'is_email', 'wp_is_ascii_email' )
			&& 10 === \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' )
			&& false === \has_filter( 'is_email', 'wp_is_unicode_email' )
			&& false === \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' );

		return $ctx->result(
			'email.scoped-ascii-filters-active',
			$ok,
			array(
				'ascii'          => self::describe_string( $ascii ),
				'unicode'        => self::describe_string( $unicode ),
				'isAscii'        => self::describe_call( $is_ascii ),
				'sanitizeAscii'  => self::describe_call( $san_ascii ),
				'isUnicode'      => self::describe_call( $is_uni ),
				'sanitizeUnicode' => self::describe_call( $san_uni ),
			)
		);
	}

	private static function check_direct_filter_callbacks( \ComponentFuzz\FuzzContext $ctx ): array {
		$unicode              = "gr\u{00E5}@example.org";
		$ascii                = 'user@example.com';
		$punycode             = 'books@xn--bcher-kva.de';
		$punycode_unicode     = "books@b\u{00FC}cher.de";
		$sentinel             = 'sentinel@example.com';
		$is_unicode           = self::call( static fn() => \wp_is_unicode_email( false, $unicode, null ) );
		$sanitize_unicode     = self::call( static fn() => \wp_sanitize_unicode_email( '', $unicode, null ) );
		$is_ascii             = self::call( static fn() => \wp_is_ascii_email( false, $ascii, null ) );
		$sanitize_ascii       = self::call( static fn() => \wp_sanitize_ascii_email( '', $ascii, null ) );
		$is_unicode_as_ascii  = self::call( static fn() => \wp_is_ascii_email( false, $unicode, null ) );
		$sanitize_unicode_as_ascii = self::call( static fn() => \wp_sanitize_ascii_email( '', $unicode, null ) );
		$is_unicode_context   = self::call( static fn() => \wp_is_unicode_email( $sentinel, 'not an address', 'local_invalid_chars' ) );
		$is_ascii_context     = self::call( static fn() => \wp_is_ascii_email( $sentinel, 'not an address', 'domain_no_periods' ) );
		$is_punycode_unicode  = self::call( static fn() => \wp_is_unicode_email( false, $punycode, null ) );
		$sanitize_punycode_unicode = self::call( static fn() => \wp_sanitize_unicode_email( '', $punycode, null ) );

		$punycode_ok = ! $is_punycode_unicode['threw'] && ! $sanitize_punycode_unicode['threw'] && (
			! self::has_idn()
				? false === $is_punycode_unicode['value'] && '' === $sanitize_punycode_unicode['value']
				: $punycode_unicode === $is_punycode_unicode['value'] && $punycode_unicode === $sanitize_punycode_unicode['value']
		);

		$ok = ! $is_unicode['threw']
			&& ! $sanitize_unicode['threw']
			&& ! $is_ascii['threw']
			&& ! $sanitize_ascii['threw']
			&& ! $is_unicode_as_ascii['threw']
			&& ! $sanitize_unicode_as_ascii['threw']
			&& ! $is_unicode_context['threw']
			&& ! $is_ascii_context['threw']
			&& ! $is_punycode_unicode['threw']
			&& ! $sanitize_punycode_unicode['threw']
			&& $unicode === $is_unicode['value']
			&& $unicode === $sanitize_unicode['value']
			&& $ascii === $is_ascii['value']
			&& $ascii === $sanitize_ascii['value']
			&& false === $is_unicode_as_ascii['value']
			&& '' === $sanitize_unicode_as_ascii['value']
			&& $sentinel === $is_unicode_context['value']
			&& $sentinel === $is_ascii_context['value']
			&& $punycode_ok;

		return array(
			$ctx->result(
				'email.filter-callbacks.direct-contracts',
				$ok,
				array(
					'unicode'              => self::describe_call( $is_unicode ),
					'sanitizeUnicode'      => self::describe_call( $sanitize_unicode ),
					'ascii'                => self::describe_call( $is_ascii ),
					'sanitizeAscii'        => self::describe_call( $sanitize_ascii ),
					'unicodeAsAscii'       => self::describe_call( $is_unicode_as_ascii ),
					'sanitizeUnicodeAsAscii' => self::describe_call( $sanitize_unicode_as_ascii ),
					'unicodeContext'       => self::describe_call( $is_unicode_context ),
					'asciiContext'         => self::describe_call( $is_ascii_context ),
					'punycodeUnicode'      => self::describe_call( $is_punycode_unicode ),
					'sanitizePunycodeUnicode' => self::describe_call( $sanitize_punycode_unicode ),
				)
			),
		);
	}

	private static function check_disabled_filter_fail_closed( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases    = array(
			array( 'label' => 'ascii', 'input' => 'user@example.com' ),
			array( 'label' => 'unicode-local', 'input' => "gr\u{00E5}@example.com" ),
			array( 'label' => 'unicode-domain', 'input' => "mail@gr\u{00E5}.org" ),
			array( 'label' => 'punycode-domain', 'input' => 'mail@xn--bcher-kva.de' ),
		);
		$failures = array();
		$observed = array();
		$snapshot = self::snapshot_hook_globals();

		try {
			\remove_all_filters( 'is_email' );
			\remove_all_filters( 'sanitize_email' );

			foreach ( $cases as $case ) {
				$is_email  = self::call( static fn() => \is_email( $case['input'] ) );
				$sanitized = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$ok        = ! $is_email['threw']
					&& ! $sanitized['threw']
					&& false === $is_email['value']
					&& '' === $sanitized['value'];

				if ( ! $ok ) {
					$failures[] = array(
						'label'         => $case['label'],
						'input'         => self::describe_string( $case['input'] ),
						'isEmail'       => self::describe_call( $is_email ),
						'sanitizeEmail' => self::describe_call( $sanitized ),
					);
				}

				$observed[] = array(
					'label'         => $case['label'],
					'input'         => self::describe_string( $case['input'] ),
					'isEmail'       => self::describe_call( $is_email ),
					'sanitizeEmail' => self::describe_call( $sanitized ),
				);
			}
		} finally {
			self::restore_hook_globals( $snapshot );
		}

		return array(
			$ctx->result(
				'email.filters.disabled-fail-closed',
				array() === $failures,
				array(
					'observed' => $observed,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_whatwg_examples( \ComponentFuzz\FuzzContext $ctx ): array {
		$valid = array(
			array( 'label' => 'ascii-atext-local', 'input' => 'azAZ09.!#$%&\'*+/=?^_`{|}~-@example.com', 'expected' => 'azAZ09.!#$%&\'*+/=?^_`{|}~-@example.com' ),
			array( 'label' => 'single-label-domain', 'input' => 'a@b', 'expected' => 'a@b' ),
			array( 'label' => 'consecutive-local-dots', 'input' => 'first..last@example.com', 'expected' => 'first..last@example.com' ),
			array( 'label' => 'subdomain-hyphen', 'input' => 'user@sub-domain.example', 'expected' => 'user@sub-domain.example' ),
			array( 'label' => 'latin-local', 'input' => "jos\u{00E9}@example.com", 'expected' => "jos\u{00E9}@example.com" ),
			array( 'label' => 'combining-local', 'input' => "jose\u{0301}@example.com", 'expected' => "jose\u{0301}@example.com" ),
			array( 'label' => 'devanagari-local', 'input' => "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}@example.com", 'expected' => "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}@example.com" ),
			array( 'label' => 'arabic-local', 'input' => "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}@example.com", 'expected' => "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}@example.com" ),
			array( 'label' => 'greek-local', 'input' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}@example.com", 'expected' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}@example.com" ),
			array( 'label' => 'cyrillic-local', 'input' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@example.com", 'expected' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@example.com" ),
			array( 'label' => 'hiragana-local', 'input' => "\u{3086}\u{3046}\u{3056}\u{3042}@example.com", 'expected' => "\u{3086}\u{3046}\u{3056}\u{3042}@example.com" ),
		);
		if ( self::has_idn() ) {
			$valid[] = array( 'label' => 'arabic-address', 'input' => "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}@\u{0645}\u{062B}\u{0627}\u{0644}.\u{0625}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}", 'expected' => "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}@\u{0645}\u{062B}\u{0627}\u{0644}.\u{0625}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}" );
			$valid[] = array( 'label' => 'cjk-address', 'input' => "\u{7528}\u{6237}@\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}", 'expected' => "\u{7528}\u{6237}@\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" );
			$valid[] = array( 'label' => 'greek-address', 'input' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}@\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}", 'expected' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}@\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" );
			$valid[] = array( 'label' => 'cyrillic-address', 'input' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}", 'expected' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}" );
			$valid[] = array( 'label' => 'hiragana-address', 'input' => "\u{3086}\u{3046}\u{3056}\u{3042}@\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}", 'expected' => "\u{3086}\u{3046}\u{3056}\u{3042}@\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}" );
		}
		$invalid = array(
			array( 'label' => 'quoted-rfc5322-local', 'input' => '"quoted"@example.com' ),
			array( 'label' => 'comment-local', 'input' => 'user(comment)@example.com' ),
			array( 'label' => 'domain-literal', 'input' => 'user@[127.0.0.1]' ),
			array( 'label' => 'domain-underscore', 'input' => 'user@example_corp.com' ),
			array( 'label' => 'leading-domain-hyphen', 'input' => 'user@-example.com' ),
			array( 'label' => 'trailing-domain-hyphen', 'input' => 'user@example-.com' ),
			array( 'label' => 'leading-combining-local', 'input' => "\u{0301}bad@example.com" ),
			array( 'label' => 'leading-combining-domain', 'input' => "user@\u{0301}bad.example" ),
			array( 'label' => 'fullwidth-at-separator', 'input' => "user\u{FF20}example.com" ),
			array( 'label' => 'ideographic-dot-separator', 'input' => "user@example\u{3002}com" ),
			array( 'label' => 'zero-width-local', 'input' => "zero\u{200D}width@example.com" ),
			array( 'label' => 'line-break-local', 'input' => "line\nbreak@example.com" ),
		);
		$failures = array();

		foreach ( $valid as $case ) {
			$parsed    = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
			$is_email  = self::call( static fn() => \is_email( $case['input'] ) );
			$sanitized = self::call( static fn() => \sanitize_email( $case['input'] ) );
			$expected  = $case['expected'];

			if (
				$parsed['threw'] ||
				$is_email['threw'] ||
				$sanitized['threw'] ||
				! ( $parsed['value'] instanceof \WP_Email_Address ) ||
				$expected !== $parsed['value']->get_unicode_address() ||
				$expected !== $is_email['value'] ||
				$expected !== $sanitized['value']
			) {
				$failures[] = array(
					'label'         => $case['label'],
					'input'         => self::describe_string( $case['input'] ),
					'expected'      => self::describe_string( $expected ),
					'expectedValid' => true,
					'parsed'        => self::describe_call( $parsed ),
					'isEmail'       => self::describe_call( $is_email ),
					'sanitizeEmail' => self::describe_call( $sanitized ),
				);
			}
		}

		foreach ( $invalid as $case ) {
			$parsed    = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
			$is_email  = self::call( static fn() => \is_email( $case['input'] ) );
			$sanitized = self::call( static fn() => \sanitize_email( $case['input'] ) );

			if (
				$parsed['threw'] ||
				$is_email['threw'] ||
				$sanitized['threw'] ||
				null !== $parsed['value'] ||
				false !== $is_email['value'] ||
				'' !== $sanitized['value']
			) {
				$failures[] = array(
					'label'         => $case['label'],
					'input'         => self::describe_string( $case['input'] ),
					'expectedValid' => false,
					'parsed'        => self::describe_call( $parsed ),
					'isEmail'       => self::describe_call( $is_email ),
					'sanitizeEmail' => self::describe_call( $sanitized ),
				);
			}
		}

		return array(
			$ctx->result(
				'email.whatwg-style.examples',
				array() === $failures,
				array(
					'validCount'   => count( $valid ),
					'invalidCount' => count( $invalid ),
					'failures'     => $failures,
				)
			),
		);
	}

	private static function check_whatwg_ascii_oracle( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'label' => 'single-label-domain', 'input' => 'a@b' ),
			array( 'label' => 'single-label-domain-long-local', 'input' => 'first.last@example' ),
			array( 'label' => 'consecutive-local-dots', 'input' => 'first..last@example.com' ),
			array( 'label' => 'leading-local-dot', 'input' => '.start@example.com' ),
			array( 'label' => 'trailing-local-dot', 'input' => 'end.@example.com' ),
			array( 'label' => 'subdomain-hyphen', 'input' => 'user@sub-domain.example' ),
			array( 'label' => 'quoted-local', 'input' => '"quoted"@example.com' ),
			array( 'label' => 'comment-local', 'input' => 'user(comment)@example.com' ),
			array( 'label' => 'domain-underscore', 'input' => 'user@example_corp.com' ),
			array( 'label' => 'leading-domain-hyphen', 'input' => 'user@-example.com' ),
			array( 'label' => 'trailing-domain-hyphen', 'input' => 'user@example-.com' ),
			array( 'label' => 'domain-literal', 'input' => 'user@[127.0.0.1]' ),
			array( 'label' => 'line-break-local', 'input' => "line\nbreak@example.com" ),
		);
		$failures = array();

		foreach ( $cases as $case ) {
			$expected_valid     = 1 === preg_match( self::WHATWG_ASCII_EMAIL_REGEX, $case['input'] );
			$parsed             = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
			$is_email           = self::call( static fn() => \is_email( $case['input'] ) );
			$sanitized          = self::call( static fn() => \sanitize_email( $case['input'] ) );
			$expected_is_email  = $expected_valid ? $case['input'] : false;
			$expected_sanitized = $expected_valid ? $case['input'] : '';
			$actual_valid       = $parsed['value'] instanceof \WP_Email_Address;

			if (
				$parsed['threw'] ||
				$is_email['threw'] ||
				$sanitized['threw'] ||
				$expected_valid !== $actual_valid ||
				$expected_is_email !== $is_email['value'] ||
				$expected_sanitized !== $sanitized['value']
			) {
				$failures[] = array(
					'label'            => $case['label'],
					'input'            => self::describe_string( $case['input'] ),
					'expectedValid'    => $expected_valid,
					'actualValid'      => $actual_valid,
					'parsed'           => self::describe_call( $parsed ),
					'isEmail'          => self::describe_call( $is_email ),
					'sanitizeEmail'    => self::describe_call( $sanitized ),
					'whatwgAsciiRegex' => self::WHATWG_ASCII_EMAIL_REGEX,
				);
			}
		}

		return array(
			$ctx->result(
				'email.whatwg-ascii.oracle-agreement',
				array() === $failures,
				array(
					'caseCount' => count( $cases ),
					'failures'  => $failures,
				)
			),
		);
	}

	private static function check_sanitizer_recovery( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'    => 'separator-whitespace',
				'input'    => " info @ example . com. \t",
				'expected' => 'info@example.com',
			),
			array(
				'label'    => 'display-name-ascii',
				'input'    => 'Display Name <user@example.com>',
				'expected' => 'user@example.com',
			),
			array(
				'label'    => 'display-name-unicode',
				'input'    => "Display Name <jos\u{00E9} @ gr\u{00E5} . org.>",
				'expected' => "jos\u{00E9}@gr\u{00E5}.org",
			),
			array(
				'label'    => 'quoted-display-name-unicode',
				'input'    => "\"\u{00C5}sa Example\" <gr\u{00E5} @ example . com.>",
				'expected' => "gr\u{00E5}@example.com",
			),
			array(
				'label'    => 'nbsp-around-separators',
				'input'    => "user\u{00A0}@\u{00A0}example.com",
				'expected' => 'user@example.com',
			),
			array(
				'label'    => 'soft-hyphen-before-dot',
				'input'    => "info@example\u{00AD}.com",
				'expected' => 'info@example.com',
			),
		);

		if ( self::has_idn() ) {
			$cases[] = array(
				'label'    => 'punycode-with-separators',
				'input'    => 'books @ xn--bcher-kva . de.',
				'expected' => "books@b\u{00FC}cher.de",
			);
		}

		$failures = array();
		foreach ( $cases as $case ) {
			$raw_is_email = self::call( static fn() => \is_email( $case['input'] ) );
			$sanitized    = self::call( static fn() => \sanitize_email( $case['input'] ) );
			$again        = self::call( static fn() => \sanitize_email( $case['expected'] ) );
			$expected_is  = self::call( static fn() => \is_email( $case['expected'] ) );
			$parsed       = self::call( static fn() => \WP_Email_Address::from_string( $case['expected'], 'unicode' ) );

			if (
				$raw_is_email['threw'] ||
				$sanitized['threw'] ||
				$again['threw'] ||
				$expected_is['threw'] ||
				$parsed['threw'] ||
				$case['expected'] !== $sanitized['value'] ||
				$case['expected'] !== $again['value'] ||
				$case['expected'] !== $expected_is['value'] ||
				! ( $parsed['value'] instanceof \WP_Email_Address )
			) {
				$failures[] = array(
					'label'           => $case['label'],
					'input'           => self::describe_string( $case['input'] ),
					'expected'        => self::describe_string( $case['expected'] ),
					'rawIsEmail'      => self::describe_call( $raw_is_email ),
					'sanitizeEmail'   => self::describe_call( $sanitized ),
					'sanitizeAgain'   => self::describe_call( $again ),
					'expectedIsEmail' => self::describe_call( $expected_is ),
					'parsedExpected'  => self::describe_call( $parsed ),
				);
			}
		}

		return array(
			$ctx->result(
				'email.sanitize-email.recovery-contracts',
				array() === $failures,
				array(
					'caseCount' => count( $cases ),
					'failures'  => $failures,
				)
			),
		);
	}

	private static function check_malformed_utf8_byte_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$fragments = array(
			array( 'label' => 'lone-continuation', 'bytes' => "\x80" ),
			array( 'label' => 'truncated-two-byte', 'bytes' => "\xC3" ),
			array( 'label' => 'truncated-three-byte', 'bytes' => "\xE2\x82" ),
			array( 'label' => 'truncated-four-byte', 'bytes' => "\xF0\x9F\x98" ),
			array( 'label' => 'overlong-slash', 'bytes' => "\xC0\xAF" ),
			array( 'label' => 'surrogate-codepoint', 'bytes' => "\xED\xA0\x80" ),
			array( 'label' => 'impossible-leading-byte', 'bytes' => "\xFE" ),
		);
		$failures  = array();
		$observed  = array();

		foreach ( $fragments as $fragment ) {
			$inputs = array(
				array(
					'label'   => $fragment['label'] . '-local',
					'segment' => 'local',
					'input'   => 'bad' . $fragment['bytes'] . '@example.com',
				),
				array(
					'label'   => $fragment['label'] . '-domain-label',
					'segment' => 'domain',
					'input'   => 'user@bad' . $fragment['bytes'] . '.example',
				),
				array(
					'label'   => $fragment['label'] . '-domain-suffix',
					'segment' => 'domain',
					'input'   => 'user@example.' . $fragment['bytes'],
				),
			);

			foreach ( $inputs as $case ) {
				$is_valid_utf8 = self::call( static fn() => \wp_is_valid_utf8( $case['input'] ) );
				$is_email      = self::call( static fn() => \is_email( $case['input'] ) );
				$sanitized     = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$unicode_parse = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
				$ascii_parse   = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'ascii' ) );
				$ok            = ! $is_valid_utf8['threw']
					&& ! $is_email['threw']
					&& ! $sanitized['threw']
					&& ! $unicode_parse['threw']
					&& ! $ascii_parse['threw']
					&& false === $is_valid_utf8['value']
					&& false === $is_email['value']
					&& '' === $sanitized['value']
					&& null === $unicode_parse['value']
					&& null === $ascii_parse['value'];

				if ( ! $ok ) {
					$failures[] = array(
						'label'       => $case['label'],
						'segment'     => $case['segment'],
						'input'       => self::describe_string( $case['input'] ),
						'validUtf8'   => self::describe_call( $is_valid_utf8 ),
						'isEmail'     => self::describe_call( $is_email ),
						'sanitizeEmail' => self::describe_call( $sanitized ),
						'unicodeParse' => self::describe_call( $unicode_parse ),
						'asciiParse'  => self::describe_call( $ascii_parse ),
					);
				}

				$observed[] = array(
					'label'     => $case['label'],
					'segment'   => $case['segment'],
					'bytes'     => strlen( $case['input'] ),
					'rejected'  => $ok,
				);
			}
		}

		return array(
			$ctx->result(
				'email.invalid-utf8.byte-matrix-rejected',
				array() === $failures,
				array(
					'caseCount' => count( $observed ),
					'observed'  => $observed,
					'failures'  => $failures,
				)
			),
		);
	}

	private static function check_unicode_localpart_byte_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'      => 'latin-two-byte-local-64-bytes',
				'local'      => str_repeat( "\u{00E5}", 32 ),
				'localBytes' => 64,
			),
			array(
				'label'      => 'latin-two-byte-local-65-bytes',
				'local'      => str_repeat( "\u{00E5}", 32 ) . 'a',
				'localBytes' => 65,
			),
			array(
				'label'      => 'combining-local-64-bytes',
				'local'      => str_repeat( "e\u{0301}", 21 ) . 'x',
				'localBytes' => 64,
			),
			array(
				'label'      => 'combining-local-65-bytes',
				'local'      => str_repeat( "e\u{0301}", 21 ) . 'xy',
				'localBytes' => 65,
			),
			array(
				'label'      => 'cjk-local-63-bytes',
				'local'      => str_repeat( "\u{7528}", 21 ),
				'localBytes' => 63,
			),
			array(
				'label'      => 'cjk-local-66-bytes',
				'local'      => str_repeat( "\u{7528}", 22 ),
				'localBytes' => 66,
			),
		);
		$failures = array();
		$observed = array();

		foreach ( $cases as $case ) {
			$input          = $case['local'] . '@example.com';
			$parsed         = self::call( static fn() => \WP_Email_Address::from_string( $input, 'unicode' ) );
			$is_email       = self::call( static fn() => \is_email( $input ) );
			$sanitized      = self::call( static fn() => \sanitize_email( $input ) );
			$ascii_parse    = self::call( static fn() => \WP_Email_Address::from_string( $input, 'ascii' ) );
			$ascii_is       = self::call( static fn() => \wp_is_ascii_email( false, $input, null ) );
			$ascii_sanitize = self::call( static fn() => \wp_sanitize_ascii_email( '', $input, null ) );
			$email          = $parsed['value'] ?? null;
			$ok             = ! $parsed['threw']
				&& ! $is_email['threw']
				&& ! $sanitized['threw']
				&& ! $ascii_parse['threw']
				&& ! $ascii_is['threw']
				&& ! $ascii_sanitize['threw']
				&& $email instanceof \WP_Email_Address
				&& $case['localBytes'] === strlen( $case['local'] )
				&& \wp_is_valid_utf8( $case['local'] )
				&& $case['local'] === $email->get_localpart()
				&& 'example.com' === $email->get_ascii_domain()
				&& 'example.com' === $email->get_unicode_domain()
				&& $input === $email->get_ascii_address()
				&& $input === $email->get_unicode_address()
				&& $input === $is_email['value']
				&& $input === $sanitized['value']
				&& null === $ascii_parse['value']
				&& false === $ascii_is['value']
				&& '' === $ascii_sanitize['value'];

			if ( ! $ok ) {
				$failures[] = array(
					'label'         => $case['label'],
					'input'         => self::describe_string( $input ),
					'localBytes'    => strlen( $case['local'] ),
					'expectedBytes' => $case['localBytes'],
					'parsed'        => self::describe_call( $parsed ),
					'isEmail'       => self::describe_call( $is_email ),
					'sanitizeEmail' => self::describe_call( $sanitized ),
					'asciiParse'    => self::describe_call( $ascii_parse ),
					'asciiIsEmail'  => self::describe_call( $ascii_is ),
					'asciiSanitize' => self::describe_call( $ascii_sanitize ),
				);
			}

			$observed[] = array(
				'label'         => $case['label'],
				'localBytes'    => strlen( $case['local'] ),
				'addressBytes'  => strlen( $input ),
				'unicodeValid'  => $email instanceof \WP_Email_Address,
				'asciiRejected' => ! $ascii_parse['threw'] && null === $ascii_parse['value'],
			);
		}

		return array(
			$ctx->result(
				'email.wp-email-address.unicode-localpart-byte-boundaries',
				array() === $failures,
				array(
					'observed' => $observed,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_generated_utf8_localpart_oracle( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases         = self::generated_utf8_localpart_oracle_cases( $ctx->fork( 'utf8-localpart-oracle' ) );
		$failures      = array();
		$observed      = array();
		$hook_snapshot = self::snapshot_hook_globals();
		$wpdb_snapshot = self::snapshot_wpdb_charset();

		try {
			foreach ( $cases as $case ) {
				if ( null !== $case['conversionError'] ) {
					$failures[] = array(
						'label'  => $case['label'],
						'domain' => $case['domainLabel'],
						'error'  => $case['conversionError'],
					);
					continue;
				}

				self::install_email_filters( 'unicode' );
				$unicode_parse     = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
				$unicode_is_email  = self::call( static fn() => \is_email( $case['input'] ) );
				$unicode_sanitized = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$direct_unicode_is = self::call( static fn() => \wp_is_unicode_email( false, $case['input'], null ) );
				$direct_unicode_sanitize = self::call( static fn() => \wp_sanitize_unicode_email( '', $case['input'], null ) );
				$ascii_view_parse  = $case['expectedUnicodeValid']
					? self::call( static fn() => \WP_Email_Address::from_string( $case['expectedAsciiAddress'], 'unicode' ) )
					: array( 'threw' => false, 'value' => null );
				$unicode_view_parse = $case['expectedUnicodeValid']
					? self::call( static fn() => \WP_Email_Address::from_string( $case['expectedUnicodeAddress'], 'unicode' ) )
					: array( 'threw' => false, 'value' => null );

				self::install_email_filters( 'ascii' );
				$ascii_parse     = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'ascii' ) );
				$ascii_is_email  = self::call( static fn() => \is_email( $case['input'] ) );
				$ascii_sanitized = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$direct_ascii_is = self::call( static fn() => \wp_is_ascii_email( false, $case['input'], null ) );
				$direct_ascii_sanitize = self::call( static fn() => \wp_sanitize_ascii_email( '', $case['input'], null ) );

				$unicode_email      = $unicode_parse['value'] ?? null;
				$ascii_email        = $ascii_parse['value'] ?? null;
				$ascii_view_email   = $ascii_view_parse['value'] ?? null;
				$unicode_view_email = $unicode_view_parse['value'] ?? null;
				$expected_views     = array(
					'localpart'      => $case['local'],
					'asciiDomain'    => $case['asciiDomain'],
					'unicodeDomain'  => $case['unicodeDomain'],
					'asciiAddress'   => $case['expectedAsciiAddress'],
					'unicodeAddress' => $case['expectedUnicodeAddress'],
				);
				$expected_unicode_is_email  = $case['expectedUnicodeValid'] ? $case['expectedUnicodeAddress'] : false;
				$expected_unicode_sanitized = $case['expectedUnicodeValid'] ? $case['expectedUnicodeAddress'] : '';
				$expected_ascii_is_email    = $case['expectedAsciiValid'] ? $case['expectedUnicodeAddress'] : false;
				$expected_ascii_sanitized   = $case['expectedAsciiValid'] ? $case['expectedUnicodeAddress'] : '';

				$unicode_parse_ok = ! $unicode_parse['threw']
					&& (
						$case['expectedUnicodeValid']
							? $unicode_email instanceof \WP_Email_Address
								&& $expected_views === self::address_raw_views( $unicode_email )
							: null === $unicode_email
					);

				$unicode_filter_ok = ! $unicode_is_email['threw']
					&& ! $unicode_sanitized['threw']
					&& ! $direct_unicode_is['threw']
					&& ! $direct_unicode_sanitize['threw']
					&& $expected_unicode_is_email === $unicode_is_email['value']
					&& $expected_unicode_sanitized === $unicode_sanitized['value']
					&& $expected_unicode_is_email === $direct_unicode_is['value']
					&& $expected_unicode_sanitized === $direct_unicode_sanitize['value'];

				$ascii_mode_ok = ! $ascii_parse['threw']
					&& ! $ascii_is_email['threw']
					&& ! $ascii_sanitized['threw']
					&& ! $direct_ascii_is['threw']
					&& ! $direct_ascii_sanitize['threw']
					&& (
						$case['expectedAsciiValid']
							? $ascii_email instanceof \WP_Email_Address
								&& $expected_views === self::address_raw_views( $ascii_email )
							: null === $ascii_email
					)
					&& $expected_ascii_is_email === $ascii_is_email['value']
					&& $expected_ascii_sanitized === $ascii_sanitized['value']
					&& $expected_ascii_is_email === $direct_ascii_is['value']
					&& $expected_ascii_sanitized === $direct_ascii_sanitize['value'];

				$view_roundtrip_ok = ! $case['expectedUnicodeValid'] || (
					! $ascii_view_parse['threw']
					&& ! $unicode_view_parse['threw']
					&& $ascii_view_email instanceof \WP_Email_Address
					&& $unicode_view_email instanceof \WP_Email_Address
					&& $expected_views === self::address_raw_views( $ascii_view_email )
					&& $expected_views === self::address_raw_views( $unicode_view_email )
					&& $unicode_email instanceof \WP_Email_Address
					&& self::address_round_trip_ok( $unicode_email )
				);

				$ok = $unicode_parse_ok
					&& $unicode_filter_ok
					&& $ascii_mode_ok
					&& $view_roundtrip_ok;

				if ( ! $ok ) {
					$failures[] = array(
						'label'              => $case['label'],
						'input'              => self::describe_string( $case['input'] ),
						'local'              => self::describe_string( $case['local'] ),
						'profile'            => $case['profile'],
						'domainLabel'        => $case['domainLabel'],
						'inputDomainView'    => $case['inputDomainView'],
						'expectedUnicodeValid' => $case['expectedUnicodeValid'],
						'expectedAsciiValid' => $case['expectedAsciiValid'],
						'expectedViews'      => self::describe_value( $expected_views ),
						'unicodeParse'       => self::describe_call( $unicode_parse ),
						'unicodeIsEmail'     => self::describe_call( $unicode_is_email ),
						'unicodeSanitized'   => self::describe_call( $unicode_sanitized ),
						'directUnicodeIs'    => self::describe_call( $direct_unicode_is ),
						'directUnicodeSanitize' => self::describe_call( $direct_unicode_sanitize ),
						'asciiParse'         => self::describe_call( $ascii_parse ),
						'asciiIsEmail'       => self::describe_call( $ascii_is_email ),
						'asciiSanitized'     => self::describe_call( $ascii_sanitized ),
						'directAsciiIs'      => self::describe_call( $direct_ascii_is ),
						'directAsciiSanitize' => self::describe_call( $direct_ascii_sanitize ),
						'asciiViewParse'     => self::describe_call( $ascii_view_parse ),
						'unicodeViewParse'   => self::describe_call( $unicode_view_parse ),
						'unicodeParseOk'     => $unicode_parse_ok,
						'unicodeFilterOk'    => $unicode_filter_ok,
						'asciiModeOk'        => $ascii_mode_ok,
						'viewRoundtripOk'    => $view_roundtrip_ok,
					);
				}

				$observed[] = array(
					'label'                => $case['label'],
					'profile'              => $case['profile'],
					'domainLabel'          => $case['domainLabel'],
					'inputDomainView'      => $case['inputDomainView'],
					'localUtf8'            => ! self::is_ascii( $case['local'] ),
					'localValidUtf8'       => \wp_is_valid_utf8( $case['local'] ),
					'expectedUnicodeValid' => $case['expectedUnicodeValid'],
					'expectedAsciiValid'   => $case['expectedAsciiValid'],
					'acceptedUnicode'      => $unicode_email instanceof \WP_Email_Address,
					'acceptedAscii'        => $ascii_email instanceof \WP_Email_Address,
				);
			}
		} finally {
			self::restore_hook_globals( $hook_snapshot );
			self::restore_wpdb_charset( $wpdb_snapshot );
		}

		return array(
			$ctx->result(
				'email.generated-utf8-localpart.oracle-agreement',
				array() === $failures,
				array(
					'caseCount' => count( $cases ),
					'observed'  => $observed,
					'failures'  => $failures,
				)
			),
		);
	}

	private static function check_construction_mode_consistency( \ComponentFuzz\FuzzContext $ctx ): array {
		$base_cases = array(
			array(
				'label'           => 'ascii-plus-subdomain',
				'input'           => 'USER+tag@example.co.uk',
				'localpart'       => 'USER+tag',
				'asciiDomain'     => 'example.co.uk',
				'unicodeDomain'   => 'example.co.uk',
				'asciiModeValid'  => true,
			),
			array(
				'label'           => 'whatwg-single-label-domain',
				'input'           => 'a@b',
				'localpart'       => 'a',
				'asciiDomain'     => 'b',
				'unicodeDomain'   => 'b',
				'asciiModeValid'  => true,
			),
			array(
				'label'           => 'whatwg-local-dots',
				'input'           => 'first..last@example.com',
				'localpart'       => 'first..last',
				'asciiDomain'     => 'example.com',
				'unicodeDomain'   => 'example.com',
				'asciiModeValid'  => true,
			),
			array(
				'label'           => 'unicode-local-ascii-domain',
				'input'           => "gr\u{00E5}@example.com",
				'localpart'       => "gr\u{00E5}",
				'asciiDomain'     => 'example.com',
				'unicodeDomain'   => 'example.com',
				'asciiModeValid'  => false,
			),
		);

		$base_result = self::evaluate_construction_mode_cases( $base_cases );
		$rows        = array(
			$ctx->result(
				'email.wp-email-address.construction-mode-consistency',
				array() === $base_result['failures'],
				$base_result
			),
		);

		if ( ! self::has_idn() ) {
			$rows[] = $ctx->skip(
				'email.wp-email-address.idn-construction-mode-consistency',
				'idn_to_ascii() or idn_to_utf8() is unavailable.'
			);

			return $rows;
		}

		$idn_cases  = array(
			array(
				'label'           => 'punycode-domain',
				'input'           => 'books@xn--bcher-kva.de',
				'localpart'       => 'books',
				'asciiDomain'     => 'xn--bcher-kva.de',
				'unicodeDomain'   => "b\u{00FC}cher.de",
				'asciiModeValid'  => false,
			),
			array(
				'label'           => 'unicode-domain',
				'input'           => "books@b\u{00FC}cher.de",
				'localpart'       => 'books',
				'asciiDomain'     => 'xn--bcher-kva.de',
				'unicodeDomain'   => "b\u{00FC}cher.de",
				'asciiModeValid'  => false,
			),
			array(
				'label'           => 'unicode-local-punycode-domain',
				'input'           => "jose\u{0301}@xn--bcher-kva.de",
				'localpart'       => "jose\u{0301}",
				'asciiDomain'     => 'xn--bcher-kva.de',
				'unicodeDomain'   => "b\u{00FC}cher.de",
				'asciiModeValid'  => false,
			),
		);
		$idn_result = self::evaluate_construction_mode_cases( $idn_cases );

		$rows[] = $ctx->result(
			'email.wp-email-address.idn-construction-mode-consistency',
			array() === $idn_result['failures'],
			$idn_result
		);

		return $rows;
	}

	private static function evaluate_construction_mode_cases( array $cases ): array {
		$failures = array();
		$observed = array();

		foreach ( $cases as $case ) {
			$unicode_parse = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
			$ascii_parse   = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'ascii' ) );
			$unicode_email = $unicode_parse['value'] ?? null;
			$ascii_email   = $ascii_parse['value'] ?? null;
			$expected      = array(
				'localpart'      => $case['localpart'],
				'asciiDomain'    => $case['asciiDomain'],
				'unicodeDomain'  => $case['unicodeDomain'],
				'asciiAddress'   => $case['localpart'] . '@' . $case['asciiDomain'],
				'unicodeAddress' => $case['localpart'] . '@' . $case['unicodeDomain'],
			);
			$unicode_views = $unicode_email instanceof \WP_Email_Address
				? self::address_raw_views( $unicode_email )
				: null;
			$ascii_views   = $ascii_email instanceof \WP_Email_Address
				? self::address_raw_views( $ascii_email )
				: null;

			$unicode_ok = ! $unicode_parse['threw']
				&& $unicode_email instanceof \WP_Email_Address
				&& $expected === $unicode_views;
			$ascii_ok   = $case['asciiModeValid']
				? (
					! $ascii_parse['threw']
					&& $ascii_email instanceof \WP_Email_Address
					&& $expected === $ascii_views
					&& $unicode_views === $ascii_views
				)
				: ! $ascii_parse['threw'] && null === $ascii_email;
			$ok         = $unicode_ok && $ascii_ok;

			if ( ! $ok ) {
				$failures[] = array(
					'label'        => $case['label'],
					'input'        => self::describe_string( $case['input'] ),
					'expected'     => self::describe_value( $expected ),
					'unicodeParse' => self::describe_call( $unicode_parse ),
					'asciiParse'   => self::describe_call( $ascii_parse ),
					'unicodeViews' => self::describe_value( $unicode_views ),
					'asciiViews'   => self::describe_value( $ascii_views ),
				);
			}

			$observed[] = array(
				'label'          => $case['label'],
				'input'          => self::describe_string( $case['input'] ),
				'asciiModeValid' => $case['asciiModeValid'],
				'unicodeViews'   => self::describe_value( $unicode_views ),
				'asciiAccepted'  => $ascii_email instanceof \WP_Email_Address,
			);
		}

		return array(
			'observed' => $observed,
			'failures' => $failures,
		);
	}

	private static function check_generated_unicode_filter_view_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::has_idn() ) {
			return array(
				$ctx->skip(
					'email.generated-unicode.filter-view-matrix',
					'idn_to_ascii() or idn_to_utf8() is unavailable.'
				),
			);
		}

		$cases         = self::generated_unicode_filter_view_cases( $ctx->fork( 'unicode-filter-view-matrix' ) );
		$failures      = array();
		$observed      = array();
		$hook_snapshot = self::snapshot_hook_globals();
		$wpdb_snapshot = self::snapshot_wpdb_charset();

		try {
			foreach ( $cases as $case ) {
				if ( null !== $case['conversionError'] ) {
					$failures[] = array(
						'label'  => $case['label'],
						'domain' => $case['domainLabel'],
						'error'  => $case['conversionError'],
					);
					continue;
				}

				$input_parse        = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
				$ascii_view_parse   = self::call( static fn() => \WP_Email_Address::from_string( $case['expectedAsciiAddress'], 'unicode' ) );
				$unicode_view_parse = self::call( static fn() => \WP_Email_Address::from_string( $case['expectedUnicodeAddress'], 'unicode' ) );
				$ascii_mode_parse   = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'ascii' ) );

				self::install_email_filters( 'unicode' );
				$unicode_is_email       = self::call( static fn() => \is_email( $case['input'] ) );
				$unicode_sanitized      = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$unicode_filter_state   = array(
					'isUnicode'       => \has_filter( 'is_email', 'wp_is_unicode_email' ),
					'sanitizeUnicode' => \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' ),
					'isAscii'         => \has_filter( 'is_email', 'wp_is_ascii_email' ),
					'sanitizeAscii'   => \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' ),
				);

				self::install_email_filters( 'ascii' );
				$ascii_is_email       = self::call( static fn() => \is_email( $case['input'] ) );
				$ascii_sanitized      = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$ascii_filter_state   = array(
					'isUnicode'       => \has_filter( 'is_email', 'wp_is_unicode_email' ),
					'sanitizeUnicode' => \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' ),
					'isAscii'         => \has_filter( 'is_email', 'wp_is_ascii_email' ),
					'sanitizeAscii'   => \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' ),
				);

				self::restore_hook_globals( $hook_snapshot );
				self::restore_wpdb_charset( $wpdb_snapshot );
				\remove_all_filters( 'is_email' );
				\remove_all_filters( 'sanitize_email' );
				self::install_email_filters_from_default_filters( 'utf8mb4' );
				$default_utf8mb4_is_email    = self::call( static fn() => \is_email( $case['input'] ) );
				$default_utf8mb4_sanitized   = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$default_utf8mb4_filter_state = array(
					'isUnicode'       => \has_filter( 'is_email', 'wp_is_unicode_email' ),
					'sanitizeUnicode' => \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' ),
					'isAscii'         => \has_filter( 'is_email', 'wp_is_ascii_email' ),
					'sanitizeAscii'   => \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' ),
				);

				self::restore_hook_globals( $hook_snapshot );
				self::restore_wpdb_charset( $wpdb_snapshot );
				\remove_all_filters( 'is_email' );
				\remove_all_filters( 'sanitize_email' );
				self::install_email_filters_from_default_filters( 'latin1' );
				$default_ascii_is_email    = self::call( static fn() => \is_email( $case['input'] ) );
				$default_ascii_sanitized   = self::call( static fn() => \sanitize_email( $case['input'] ) );
				$default_ascii_filter_state = array(
					'isUnicode'       => \has_filter( 'is_email', 'wp_is_unicode_email' ),
					'sanitizeUnicode' => \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' ),
					'isAscii'         => \has_filter( 'is_email', 'wp_is_ascii_email' ),
					'sanitizeAscii'   => \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' ),
				);

				self::restore_hook_globals( $hook_snapshot );
				self::restore_wpdb_charset( $wpdb_snapshot );

				$input_email        = $input_parse['value'] ?? null;
				$ascii_view_email   = $ascii_view_parse['value'] ?? null;
				$unicode_view_email = $unicode_view_parse['value'] ?? null;
				$ascii_mode_email   = $ascii_mode_parse['value'] ?? null;
				$expected_views     = array(
					'localpart'      => $case['local'],
					'asciiDomain'    => $case['asciiDomain'],
					'unicodeDomain'  => $case['unicodeDomain'],
					'asciiAddress'   => $case['expectedAsciiAddress'],
					'unicodeAddress' => $case['expectedUnicodeAddress'],
				);
				$ascii_mode_expected_is_email  = $case['asciiModeValid'] ? $case['expectedUnicodeAddress'] : false;
				$ascii_mode_expected_sanitized = $case['asciiModeValid'] ? $case['expectedUnicodeAddress'] : '';

				$parse_ok = ! $input_parse['threw']
					&& ! $ascii_view_parse['threw']
					&& ! $unicode_view_parse['threw']
					&& $input_email instanceof \WP_Email_Address
					&& $ascii_view_email instanceof \WP_Email_Address
					&& $unicode_view_email instanceof \WP_Email_Address
					&& $expected_views === self::address_raw_views( $input_email )
					&& $expected_views === self::address_raw_views( $ascii_view_email )
					&& $expected_views === self::address_raw_views( $unicode_view_email );

				$ascii_parse_ok = ! $ascii_mode_parse['threw']
					&& (
						$case['asciiModeValid']
							? $ascii_mode_email instanceof \WP_Email_Address
								&& $expected_views === self::address_raw_views( $ascii_mode_email )
							: null === $ascii_mode_email
					);

				$unicode_filter_ok = ! $unicode_is_email['threw']
					&& ! $unicode_sanitized['threw']
					&& $case['expectedUnicodeAddress'] === $unicode_is_email['value']
					&& $case['expectedUnicodeAddress'] === $unicode_sanitized['value']
					&& 10 === $unicode_filter_state['isUnicode']
					&& 10 === $unicode_filter_state['sanitizeUnicode']
					&& false === $unicode_filter_state['isAscii']
					&& false === $unicode_filter_state['sanitizeAscii'];

				$ascii_filter_ok = ! $ascii_is_email['threw']
					&& ! $ascii_sanitized['threw']
					&& $ascii_mode_expected_is_email === $ascii_is_email['value']
					&& $ascii_mode_expected_sanitized === $ascii_sanitized['value']
					&& 10 === $ascii_filter_state['isAscii']
					&& 10 === $ascii_filter_state['sanitizeAscii']
					&& false === $ascii_filter_state['isUnicode']
					&& false === $ascii_filter_state['sanitizeUnicode'];

				$default_filter_ok = ! $default_utf8mb4_is_email['threw']
					&& ! $default_utf8mb4_sanitized['threw']
					&& ! $default_ascii_is_email['threw']
					&& ! $default_ascii_sanitized['threw']
					&& $case['expectedUnicodeAddress'] === $default_utf8mb4_is_email['value']
					&& $case['expectedUnicodeAddress'] === $default_utf8mb4_sanitized['value']
					&& $ascii_mode_expected_is_email === $default_ascii_is_email['value']
					&& $ascii_mode_expected_sanitized === $default_ascii_sanitized['value']
					&& 10 === $default_utf8mb4_filter_state['isUnicode']
					&& 10 === $default_utf8mb4_filter_state['sanitizeUnicode']
					&& false === $default_utf8mb4_filter_state['isAscii']
					&& false === $default_utf8mb4_filter_state['sanitizeAscii']
					&& 10 === $default_ascii_filter_state['isAscii']
					&& 10 === $default_ascii_filter_state['sanitizeAscii']
					&& false === $default_ascii_filter_state['isUnicode']
					&& false === $default_ascii_filter_state['sanitizeUnicode'];

				$view_shape_ok = $input_email instanceof \WP_Email_Address
					&& self::is_ascii( $input_email->get_ascii_domain() )
					&& self::is_ascii( $case['local'] ) === self::is_ascii( $input_email->get_ascii_address() )
					&& $case['hasUnicodeDomain'] === ( $input_email->get_ascii_domain() !== $input_email->get_unicode_domain() )
					&& $case['hasUnicodeLocal'] === ! self::is_ascii( $input_email->get_localpart() );

				$ok = $parse_ok
					&& $ascii_parse_ok
					&& $unicode_filter_ok
					&& $ascii_filter_ok
					&& $default_filter_ok
					&& $view_shape_ok;

				if ( ! $ok ) {
					$failures[] = array(
						'label'              => $case['label'],
						'input'              => self::describe_string( $case['input'] ),
						'inputDomainView'    => $case['inputDomainView'],
						'expectedViews'      => self::describe_value( $expected_views ),
						'inputParse'         => self::describe_call( $input_parse ),
						'asciiViewParse'     => self::describe_call( $ascii_view_parse ),
						'unicodeViewParse'   => self::describe_call( $unicode_view_parse ),
						'asciiModeParse'     => self::describe_call( $ascii_mode_parse ),
						'unicodeIsEmail'     => self::describe_call( $unicode_is_email ),
						'unicodeSanitized'   => self::describe_call( $unicode_sanitized ),
						'asciiIsEmail'       => self::describe_call( $ascii_is_email ),
						'asciiSanitized'     => self::describe_call( $ascii_sanitized ),
						'defaultUtf8mb4Is'   => self::describe_call( $default_utf8mb4_is_email ),
						'defaultUtf8mb4San'  => self::describe_call( $default_utf8mb4_sanitized ),
						'defaultAsciiIs'     => self::describe_call( $default_ascii_is_email ),
						'defaultAsciiSan'    => self::describe_call( $default_ascii_sanitized ),
						'unicodeFilterState' => self::describe_value( $unicode_filter_state ),
						'asciiFilterState'   => self::describe_value( $ascii_filter_state ),
						'defaultUtf8mb4FilterState' => self::describe_value( $default_utf8mb4_filter_state ),
						'defaultAsciiFilterState' => self::describe_value( $default_ascii_filter_state ),
						'parseOk'            => $parse_ok,
						'asciiParseOk'       => $ascii_parse_ok,
						'unicodeFilterOk'    => $unicode_filter_ok,
						'asciiFilterOk'      => $ascii_filter_ok,
						'defaultFilterOk'    => $default_filter_ok,
						'viewShapeOk'        => $view_shape_ok,
					);
				}

				$observed[] = array(
					'label'             => $case['label'],
					'inputDomainView'   => $case['inputDomainView'],
					'localUtf8'         => $case['hasUnicodeLocal'],
					'domainIdn'         => $case['hasUnicodeDomain'],
					'asciiModeValid'    => $case['asciiModeValid'],
					'unicodeAddress'    => self::describe_string( $case['expectedUnicodeAddress'] ),
					'asciiAddress'      => self::describe_string( $case['expectedAsciiAddress'] ),
					'asciiAddressAscii' => $input_email instanceof \WP_Email_Address ? self::is_ascii( $input_email->get_ascii_address() ) : null,
				);
			}
		} finally {
			self::restore_hook_globals( $hook_snapshot );
			self::restore_wpdb_charset( $wpdb_snapshot );
		}

		return array(
			$ctx->result(
				'email.generated-unicode.filter-view-matrix',
				array() === $failures,
				array(
					'caseCount' => count( $cases ),
					'observed'  => $observed,
					'failures'  => $failures,
				)
			),
		);
	}

	private static function check_generated_malformed_variant_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases         = self::generated_malformed_variant_cases( $ctx->fork( 'malformed-variant-matrix' ) );
		$failures      = array();
		$observed      = array();
		$hook_snapshot = self::snapshot_hook_globals();
		$wpdb_snapshot = self::snapshot_wpdb_charset();

		try {
			foreach ( $cases as $case ) {
				if ( null !== $case['conversionError'] ) {
					$failures[] = array(
						'label'  => $case['label'],
						'domain' => $case['domainLabel'],
						'error'  => $case['conversionError'],
					);
					continue;
				}

				$base_parse = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
				$base_email = $base_parse['value'] ?? null;
				$base_ok    = ! $base_parse['threw']
					&& $base_email instanceof \WP_Email_Address
					&& $case['expectedAsciiAddress'] === $base_email->get_ascii_address()
					&& $case['expectedUnicodeAddress'] === $base_email->get_unicode_address()
					&& self::address_round_trip_ok( $base_email );

				if ( ! $base_ok ) {
					$failures[] = array(
						'label'         => $case['label'],
						'input'         => self::describe_string( $case['input'] ),
						'expectedAscii' => self::describe_string( $case['expectedAsciiAddress'] ),
						'expectedUnicode' => self::describe_string( $case['expectedUnicodeAddress'] ),
						'baseParse'     => self::describe_call( $base_parse ),
					);
					continue;
				}

				foreach ( $case['variants'] as $variant ) {
					self::install_email_filters( 'unicode' );
					$unicode_parse     = self::call( static fn() => \WP_Email_Address::from_string( $variant['input'], 'unicode' ) );
					$unicode_is_email  = self::call( static fn() => \is_email( $variant['input'] ) );
					$unicode_sanitized = self::call( static fn() => \sanitize_email( $variant['input'] ) );
					$direct_unicode_is = self::call( static fn() => \wp_is_unicode_email( false, $variant['input'], null ) );
					$direct_unicode_sanitize = self::call( static fn() => \wp_sanitize_unicode_email( '', $variant['input'], null ) );

					self::install_email_filters( 'ascii' );
					$ascii_parse     = self::call( static fn() => \WP_Email_Address::from_string( $variant['input'], 'ascii' ) );
					$ascii_is_email  = self::call( static fn() => \is_email( $variant['input'] ) );
					$ascii_sanitized = self::call( static fn() => \sanitize_email( $variant['input'] ) );
					$direct_ascii_is = self::call( static fn() => \wp_is_ascii_email( false, $variant['input'], null ) );
					$direct_ascii_sanitize = self::call( static fn() => \wp_sanitize_ascii_email( '', $variant['input'], null ) );

					self::restore_hook_globals( $hook_snapshot );
					self::restore_wpdb_charset( $wpdb_snapshot );
					\remove_all_filters( 'is_email' );
					\remove_all_filters( 'sanitize_email' );
					self::install_email_filters_from_default_filters( 'utf8mb4' );
					$default_utf8mb4_is_email  = self::call( static fn() => \is_email( $variant['input'] ) );
					$default_utf8mb4_sanitized = self::call( static fn() => \sanitize_email( $variant['input'] ) );
					$default_utf8mb4_filters   = array(
						'isUnicode'       => \has_filter( 'is_email', 'wp_is_unicode_email' ),
						'sanitizeUnicode' => \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' ),
						'isAscii'         => \has_filter( 'is_email', 'wp_is_ascii_email' ),
						'sanitizeAscii'   => \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' ),
					);

					self::restore_hook_globals( $hook_snapshot );
					self::restore_wpdb_charset( $wpdb_snapshot );
					\remove_all_filters( 'is_email' );
					\remove_all_filters( 'sanitize_email' );
					self::install_email_filters_from_default_filters( 'latin1' );
					$default_ascii_is_email  = self::call( static fn() => \is_email( $variant['input'] ) );
					$default_ascii_sanitized = self::call( static fn() => \sanitize_email( $variant['input'] ) );
					$default_ascii_filters   = array(
						'isUnicode'       => \has_filter( 'is_email', 'wp_is_unicode_email' ),
						'sanitizeUnicode' => \has_filter( 'sanitize_email', 'wp_sanitize_unicode_email' ),
						'isAscii'         => \has_filter( 'is_email', 'wp_is_ascii_email' ),
						'sanitizeAscii'   => \has_filter( 'sanitize_email', 'wp_sanitize_ascii_email' ),
					);

					self::restore_hook_globals( $hook_snapshot );
					self::restore_wpdb_charset( $wpdb_snapshot );

					$ok = ! $unicode_parse['threw']
						&& ! $unicode_is_email['threw']
						&& ! $unicode_sanitized['threw']
						&& ! $direct_unicode_is['threw']
						&& ! $direct_unicode_sanitize['threw']
						&& ! $ascii_parse['threw']
						&& ! $ascii_is_email['threw']
						&& ! $ascii_sanitized['threw']
						&& ! $direct_ascii_is['threw']
						&& ! $direct_ascii_sanitize['threw']
						&& ! $default_utf8mb4_is_email['threw']
						&& ! $default_utf8mb4_sanitized['threw']
						&& ! $default_ascii_is_email['threw']
						&& ! $default_ascii_sanitized['threw']
						&& null === $unicode_parse['value']
						&& false === $unicode_is_email['value']
						&& '' === $unicode_sanitized['value']
						&& false === $direct_unicode_is['value']
						&& '' === $direct_unicode_sanitize['value']
						&& null === $ascii_parse['value']
						&& false === $ascii_is_email['value']
						&& '' === $ascii_sanitized['value']
						&& false === $direct_ascii_is['value']
						&& '' === $direct_ascii_sanitize['value']
						&& false === $default_utf8mb4_is_email['value']
						&& '' === $default_utf8mb4_sanitized['value']
						&& false === $default_ascii_is_email['value']
						&& '' === $default_ascii_sanitized['value']
						&& 10 === $default_utf8mb4_filters['isUnicode']
						&& 10 === $default_utf8mb4_filters['sanitizeUnicode']
						&& false === $default_utf8mb4_filters['isAscii']
						&& false === $default_utf8mb4_filters['sanitizeAscii']
						&& false === $default_ascii_filters['isUnicode']
						&& false === $default_ascii_filters['sanitizeUnicode']
						&& 10 === $default_ascii_filters['isAscii']
						&& 10 === $default_ascii_filters['sanitizeAscii'];

					if ( ! $ok ) {
						$failures[] = array(
							'label'             => $case['label'],
							'variant'           => $variant['label'],
							'base'              => self::describe_string( $case['input'] ),
							'input'             => self::describe_string( $variant['input'] ),
							'unicodeParse'      => self::describe_call( $unicode_parse ),
							'unicodeIsEmail'    => self::describe_call( $unicode_is_email ),
							'unicodeSanitized'  => self::describe_call( $unicode_sanitized ),
							'directUnicodeIs'   => self::describe_call( $direct_unicode_is ),
							'directUnicodeSanitize' => self::describe_call( $direct_unicode_sanitize ),
							'asciiParse'        => self::describe_call( $ascii_parse ),
							'asciiIsEmail'      => self::describe_call( $ascii_is_email ),
							'asciiSanitized'    => self::describe_call( $ascii_sanitized ),
							'directAsciiIs'     => self::describe_call( $direct_ascii_is ),
							'directAsciiSanitize' => self::describe_call( $direct_ascii_sanitize ),
							'defaultUtf8mb4Is'  => self::describe_call( $default_utf8mb4_is_email ),
							'defaultUtf8mb4San' => self::describe_call( $default_utf8mb4_sanitized ),
							'defaultAsciiIs'    => self::describe_call( $default_ascii_is_email ),
							'defaultAsciiSan'   => self::describe_call( $default_ascii_sanitized ),
							'defaultUtf8mb4Filters' => self::describe_value( $default_utf8mb4_filters ),
							'defaultAsciiFilters' => self::describe_value( $default_ascii_filters ),
						);
					}

					$observed[] = array(
						'label'          => $case['label'],
						'variant'        => $variant['label'],
						'inputDomainView' => $case['inputDomainView'],
						'localUtf8'      => $case['hasUnicodeLocal'],
						'domainIdn'      => $case['hasUnicodeDomain'],
						'rejected'       => $ok,
					);
				}
			}
		} finally {
			self::restore_hook_globals( $hook_snapshot );
			self::restore_wpdb_charset( $wpdb_snapshot );
		}

		return array(
			$ctx->result(
				'email.generated-malformed-variants.reject-consistently',
				array() === $failures,
				array(
					'baseCaseCount' => count( $cases ),
					'variantCount'  => count( $observed ),
					'observed'      => $observed,
					'failures'      => $failures,
				)
			),
		);
	}

	private static function check_unicode_case( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case ): array {
		$rows      = array();
		$input     = $case['input'];
		$is_email  = self::call( static fn() => \is_email( $input ) );
		$sanitized = self::call( static fn() => \sanitize_email( $input ) );
		$parsed    = self::call( static fn() => \WP_Email_Address::from_string( $input, 'unicode' ) );
		$no_throw  = ! $is_email['threw'] && ! $sanitized['threw'] && ! $parsed['threw'];

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'email.unicode.no-throw',
			$no_throw,
			array(
				'isEmail'       => self::describe_call( $is_email ),
				'sanitizeEmail' => self::describe_call( $sanitized ),
				'parsed'        => self::describe_call( $parsed ),
			)
		);

		if ( ! $no_throw ) {
			return $rows;
		}

		$is_email_value  = $is_email['value'];
		$sanitized_value = $sanitized['value'];
		$parsed_value    = $parsed['value'];

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'email.sanitize-email.returns-string',
			is_string( $sanitized_value ),
			array( 'sanitizeEmail' => self::describe_value( $sanitized_value ) )
		);

		if ( is_string( $sanitized_value ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.sanitize-email.output-valid-utf8',
				\wp_is_valid_utf8( $sanitized_value ),
				array( 'sanitizeEmail' => self::describe_string( $sanitized_value ) )
			);

			$again = self::call( static fn() => \sanitize_email( $sanitized_value ) );
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.sanitize-email.idempotent',
				! $again['threw'] && $sanitized_value === $again['value'],
				array(
					'first'  => self::describe_string( $sanitized_value ),
					'second' => self::describe_call( $again ),
				)
			);

			$valid_sanitized = '' === $sanitized_value
				? array( 'threw' => false, 'value' => false )
				: self::call( static fn() => \is_email( $sanitized_value ) );

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.sanitize-email.output-validates',
				'' === $sanitized_value || ( ! $valid_sanitized['threw'] && false !== $valid_sanitized['value'] ),
				array(
					'sanitizeEmail' => self::describe_string( $sanitized_value ),
					'isEmail'       => self::describe_call( $valid_sanitized ),
				)
			);

			$parsed_sanitized = '' === $sanitized_value
				? array( 'threw' => false, 'value' => null )
				: self::call( static fn() => \WP_Email_Address::from_string( $sanitized_value, 'unicode' ) );
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.sanitize-email.output-agrees-with-class',
				'' === $sanitized_value
					|| (
						! $parsed_sanitized['threw']
						&& $parsed_sanitized['value'] instanceof \WP_Email_Address
						&& $sanitized_value === $parsed_sanitized['value']->get_unicode_address()
					),
				array(
					'sanitizeEmail' => self::describe_string( $sanitized_value ),
					'parsed'        => self::describe_call( $parsed_sanitized ),
				)
			);
		}

		$expected_is_email = $parsed_value instanceof \WP_Email_Address ? $parsed_value->get_unicode_address() : false;
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'email.is-email.agrees-with-class',
			$is_email_value === $expected_is_email,
			array(
				'expected' => self::describe_value( $expected_is_email ),
				'actual'   => self::describe_value( $is_email_value ),
				'parsed'   => self::describe_value( $parsed_value ),
			)
		);

		if ( array_key_exists( 'expectRawUnicode', $case ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.unicode.raw-expected-contract',
				$case['expectRawUnicode'] === $is_email_value,
				array(
					'expected' => self::describe_value( $case['expectRawUnicode'] ),
					'actual'   => self::describe_value( $is_email_value ),
				)
			);
		}

		if ( array_key_exists( 'expectSanitizedUnicode', $case ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.unicode.sanitized-expected-contract',
				$case['expectSanitizedUnicode'] === $sanitized_value,
				array(
					'expected' => self::describe_value( $case['expectSanitizedUnicode'] ),
					'actual'   => self::describe_value( $sanitized_value ),
				)
			);
		}

		if ( self::has_trait( $case, 'invalidUtf8' ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.invalid-utf8.rejected-and-emptied',
				false === $is_email_value && '' === $sanitized_value && null === $parsed_value,
				array(
					'isEmail'       => self::describe_value( $is_email_value ),
					'sanitizeEmail' => self::describe_value( $sanitized_value ),
					'parsed'        => self::describe_value( $parsed_value ),
				)
			);
		}

		if ( $parsed_value instanceof \WP_Email_Address ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.wp-email-address.structural-parts',
				self::address_parts_ok( $parsed_value ),
				array(
					'address' => self::describe_address( $parsed_value ),
				)
			);
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.wp-email-address.structural-round-trip',
				self::address_round_trip_ok( $parsed_value ),
				array(
					'address' => self::describe_address( $parsed_value ),
				)
			);
		}

		return $rows;
	}

	private static function check_ascii_case( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case ): array {
		$rows      = array();
		$input     = $case['input'];
		$is_email  = self::call( static fn() => \is_email( $input ) );
		$sanitized = self::call( static fn() => \sanitize_email( $input ) );
		$parsed    = self::call( static fn() => \WP_Email_Address::from_string( $input, 'ascii' ) );
		$no_throw  = ! $is_email['threw'] && ! $sanitized['threw'] && ! $parsed['threw'];

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'email.ascii-fallback.no-throw',
			$no_throw,
			array(
				'isEmail'       => self::describe_call( $is_email ),
				'sanitizeEmail' => self::describe_call( $sanitized ),
				'parsed'        => self::describe_call( $parsed ),
			)
		);

		if ( ! $no_throw ) {
			return $rows;
		}

		if ( array_key_exists( 'expectRawAscii', $case ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.ascii-fallback.raw-expected-contract',
				$case['expectRawAscii'] === $is_email['value'],
				array(
					'expected' => self::describe_value( $case['expectRawAscii'] ),
					'actual'   => self::describe_value( $is_email['value'] ),
				)
			);
		}

		if ( array_key_exists( 'expectSanitizedAscii', $case ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.ascii-fallback.sanitized-expected-contract',
				$case['expectSanitizedAscii'] === $sanitized['value'],
				array(
					'expected' => self::describe_value( $case['expectSanitizedAscii'] ),
					'actual'   => self::describe_value( $sanitized['value'] ),
				)
			);
		}

		if ( is_string( $sanitized['value'] ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.ascii-fallback.sanitize-email.output-valid-utf8',
				\wp_is_valid_utf8( $sanitized['value'] ),
				array( 'sanitizeEmail' => self::describe_string( $sanitized['value'] ) )
			);
		}

		if ( self::has_trait( $case, 'unicodeAddress' ) || self::has_trait( $case, 'punycodeUnicodeDomain' ) ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'email.ascii-fallback.rejects-unicode-addresses',
				false === $is_email['value'] && '' === $sanitized['value'] && null === $parsed['value'],
				array(
					'isEmail'       => self::describe_value( $is_email['value'] ),
					'sanitizeEmail' => self::describe_value( $sanitized['value'] ),
					'parsed'        => self::describe_value( $parsed['value'] ),
				)
			);
		}

		return $rows;
	}

	private static function check_distinct_localparts( \ComponentFuzz\FuzzContext $ctx ): array {
		$domain = 'example.com';
		$inputs = array(
			'jose@' . $domain,
			"jos\u{00E9}@" . $domain,
			"jose\u{0301}@" . $domain,
			"josejose@gr\u{00E5}.org",
			"jos\u{00E9}jos\u{00E9}@gr\u{00E5}.org",
			"jose\u{0301}jose\u{0301}@gr\u{00E5}.org",
		);
		$sanitized = array();
		$locals    = array();
		$throws    = array();

		foreach ( $inputs as $input ) {
			$sanitize_call = self::call( static fn() => \sanitize_email( $input ) );
			$parse_call    = self::call( static fn() => \WP_Email_Address::from_string( $input, 'unicode' ) );
			$throws[]      = $sanitize_call['threw'] || $parse_call['threw'];
			$sanitized[]   = $sanitize_call['value'] ?? null;
			$locals[]      = $parse_call['value'] instanceof \WP_Email_Address ? $parse_call['value']->get_localpart() : null;
		}

		$ok = ! in_array( true, $throws, true )
			&& ! in_array( '', $sanitized, true )
			&& count( array_unique( $sanitized, SORT_REGULAR ) ) === count( $sanitized )
			&& count( array_unique( $locals, SORT_REGULAR ) ) === count( $locals );

		return array(
			$ctx->result(
				'email.sanitize-email.distinct-localparts-preserved',
				$ok,
				array(
					'inputs'    => array_map( array( self::class, 'describe_string' ), $inputs ),
					'sanitized' => self::describe_value( $sanitized ),
					'locals'    => self::describe_value( $locals ),
					'throws'    => self::describe_value( $throws ),
				)
			),
		);
	}

	private static function check_normalization_sensitive_localparts( \ComponentFuzz\FuzzContext $ctx ): array {
		$pairs = array(
			array(
				'label' => 'latin-acute',
				'nfc'   => "jos\u{00E9}@example.com",
				'nfd'   => "jose\u{0301}@example.com",
			),
			array(
				'label' => 'latin-ring',
				'nfc'   => "\u{00E5}ngstrom@example.com",
				'nfd'   => "a\u{030A}ngstrom@example.com",
			),
			array(
				'label' => 'greek-tonos',
				'nfc'   => "\u{03AC}\u{03BB}\u{03C6}\u{03B1}@example.com",
				'nfd'   => "\u{03B1}\u{0301}\u{03BB}\u{03C6}\u{03B1}@example.com",
			),
		);
		$failures = array();
		$observed = array();

		foreach ( $pairs as $pair ) {
			$nfc_sanitized = self::call( static fn() => \sanitize_email( $pair['nfc'] ) );
			$nfd_sanitized = self::call( static fn() => \sanitize_email( $pair['nfd'] ) );
			$nfc_parsed    = self::call( static fn() => \WP_Email_Address::from_string( $pair['nfc'], 'unicode' ) );
			$nfd_parsed    = self::call( static fn() => \WP_Email_Address::from_string( $pair['nfd'], 'unicode' ) );
			$nfc_email     = $nfc_parsed['value'] ?? null;
			$nfd_email     = $nfd_parsed['value'] ?? null;
			$ok            = ! $nfc_sanitized['threw']
				&& ! $nfd_sanitized['threw']
				&& ! $nfc_parsed['threw']
				&& ! $nfd_parsed['threw']
				&& $pair['nfc'] === $nfc_sanitized['value']
				&& $pair['nfd'] === $nfd_sanitized['value']
				&& $nfc_email instanceof \WP_Email_Address
				&& $nfd_email instanceof \WP_Email_Address
				&& $pair['nfc'] === $nfc_email->get_unicode_address()
				&& $pair['nfd'] === $nfd_email->get_unicode_address()
				&& $nfc_email->get_localpart() !== $nfd_email->get_localpart()
				&& $nfc_email->get_unicode_address() !== $nfd_email->get_unicode_address();

			if ( ! $ok ) {
				$failures[] = array(
					'label'        => $pair['label'],
					'nfc'          => self::describe_string( $pair['nfc'] ),
					'nfd'          => self::describe_string( $pair['nfd'] ),
					'nfcSanitized' => self::describe_call( $nfc_sanitized ),
					'nfdSanitized' => self::describe_call( $nfd_sanitized ),
					'nfcParsed'    => self::describe_call( $nfc_parsed ),
					'nfdParsed'    => self::describe_call( $nfd_parsed ),
				);
			}

			$observed[] = array(
				'label'         => $pair['label'],
				'nfcLocal'      => $nfc_email instanceof \WP_Email_Address ? self::describe_string( $nfc_email->get_localpart() ) : null,
				'nfdLocal'      => $nfd_email instanceof \WP_Email_Address ? self::describe_string( $nfd_email->get_localpart() ) : null,
				'localsDistinct' => $nfc_email instanceof \WP_Email_Address && $nfd_email instanceof \WP_Email_Address && $nfc_email->get_localpart() !== $nfd_email->get_localpart(),
			);
		}

		return array(
			$ctx->result(
				'email.wp-email-address.normalization-sensitive-localparts-preserved',
				array() === $failures,
				array(
					'observed' => $observed,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_comment_author_email_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'wp_filter_comment' ) ) {
			return array(
				$ctx->skip(
					'email.comment-author-email.filter-agreement',
					'wp_filter_comment() is unavailable.'
				),
			);
		}

		$cases = array(
			array(
				'label'       => 'cyrillic-local',
				'email'       => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@example.com",
				'expectEmail' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@example.com",
			),
			array(
				'label'       => 'hiragana-local',
				'email'       => "\u{3086}\u{3046}\u{3056}\u{3042}@example.com",
				'expectEmail' => "\u{3086}\u{3046}\u{3056}\u{3042}@example.com",
			),
			array(
				'label'       => 'display-name-recovery',
				'email'       => "Display Name <jos\u{00E9} @ example . com.>",
				'expectEmail' => "jos\u{00E9}@example.com",
			),
			array(
				'label'       => 'emoji-local-rejected',
				'email'       => "emoji\u{1F600}@example.com",
				'expectEmail' => '',
			),
			array(
				'label'       => 'fullwidth-at-rejected',
				'email'       => "bad\u{FF20}example.com",
				'expectEmail' => '',
			),
		);
		$failures = array();
		$observed = array();
		$snapshot = self::snapshot_hook_globals();

		try {
			\remove_all_filters( 'pre_comment_author_email' );
			\add_filter( 'pre_comment_author_email', 'trim' );
			\add_filter( 'pre_comment_author_email', 'sanitize_email' );

			foreach ( $cases as $index => $case ) {
				$comment = array(
					'comment_author'       => 'Component Fuzzer',
					'comment_author_email' => $case['email'],
					'comment_author_url'   => '',
					'comment_content'      => 'Unicode email comment case ' . $index,
					'comment_author_IP'    => '127.0.0.1',
					'comment_agent'        => 'component-fuzz',
				);
				$filtered       = self::call( static fn() => \wp_filter_comment( $comment ) );
				$sanitized      = self::call( static fn() => \sanitize_email( trim( $case['email'] ) ) );
				$filtered_email = ! $filtered['threw'] && is_array( $filtered['value'] )
					? ( $filtered['value']['comment_author_email'] ?? null )
					: null;
				$filtered_valid = '' === $filtered_email
					? array( 'threw' => false, 'value' => false )
					: self::call( static fn() => \is_email( $filtered_email ) );
				$parsed         = '' === $filtered_email || ! is_string( $filtered_email )
					? array( 'threw' => false, 'value' => null )
					: self::call( static fn() => \WP_Email_Address::from_string( $filtered_email, 'unicode' ) );
				$ok             = ! $filtered['threw']
					&& ! $sanitized['threw']
					&& ! $filtered_valid['threw']
					&& ! $parsed['threw']
					&& is_array( $filtered['value'] )
					&& true === ( $filtered['value']['filtered'] ?? null )
					&& $case['expectEmail'] === $filtered_email
					&& $sanitized['value'] === $filtered_email
					&& (
						'' === $filtered_email
						? false === $filtered_valid['value'] && null === $parsed['value']
						: $filtered_email === $filtered_valid['value'] && $parsed['value'] instanceof \WP_Email_Address
					);

				if ( ! $ok ) {
					$failures[] = array(
						'label'         => $case['label'],
						'input'         => self::describe_string( $case['email'] ),
						'expectedEmail' => self::describe_string( $case['expectEmail'] ),
						'filteredEmail' => self::describe_value( $filtered_email ),
						'filtered'      => self::describe_call( $filtered ),
						'sanitized'     => self::describe_call( $sanitized ),
						'isEmail'       => self::describe_call( $filtered_valid ),
						'parsed'        => self::describe_call( $parsed ),
					);
				}

				$observed[] = array(
					'label'         => $case['label'],
					'input'         => self::describe_string( $case['email'] ),
					'filteredEmail' => self::describe_value( $filtered_email ),
					'accepted'      => '' !== $filtered_email,
				);
			}
		} finally {
			self::restore_hook_globals( $snapshot );
		}

		return array(
			$ctx->result(
				'email.comment-author-email.filter-agreement',
				array() === $failures,
				array(
					'observed' => $observed,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_rest_email_schema_filter_modes( \ComponentFuzz\FuzzContext $ctx ): array {
		foreach ( array( 'rest_validate_value_from_schema', 'rest_sanitize_value_from_schema' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return array(
					$ctx->skip(
						'email.rest-schema.email-format-filter-modes',
						'Required REST schema APIs are unavailable.',
						array( 'missing' => 'function ' . $function )
					),
				);
			}
		}

		if ( ! class_exists( 'WP_REST_Users_Controller' ) ) {
			return array(
				$ctx->skip(
					'email.rest-schema.email-format-filter-modes',
					'WP_REST_Users_Controller is unavailable.'
				),
			);
		}

		$controller   = new \WP_REST_Users_Controller();
		$item_schema  = $controller->get_item_schema();
		$email_schema = $item_schema['properties']['email'] ?? null;

		if (
			! is_array( $email_schema ) ||
			'string' !== ( $email_schema['type'] ?? null ) ||
			'email' !== ( $email_schema['format'] ?? null )
		) {
			return array(
				$ctx->result(
					'email.rest-schema.email-format-filter-modes',
					false,
					array(
						'emailSchema' => self::describe_value( $email_schema ),
					)
				),
			);
		}

		$cases = array(
			array(
				'label'  => 'ascii',
				'input'  => 'user@example.com',
				'expect' => array(
					'unicode' => true,
					'ascii'   => true,
				),
			),
			array(
				'label'  => 'unicode-local',
				'input'  => "jos\u{00E9}@example.com",
				'expect' => array(
					'unicode' => true,
					'ascii'   => false,
				),
			),
			array(
				'label'  => 'invalid-unicode-local',
				'input'  => "emoji\u{1F600}@example.com",
				'expect' => array(
					'unicode' => false,
					'ascii'   => false,
				),
			),
			array(
				'label'  => 'fullwidth-at',
				'input'  => "bad\u{FF20}example.com",
				'expect' => array(
					'unicode' => false,
					'ascii'   => false,
				),
			),
		);

		if ( self::has_idn() ) {
			$cases[] = array(
				'label'  => 'unicode-local-and-domain',
				'input'  => "gr\u{00E5}@gr\u{00E5}.org",
				'expect' => array(
					'unicode' => true,
					'ascii'   => false,
				),
			);
		}

		$failures = array();
		$observed = array();
		$snapshot = self::snapshot_hook_globals();

		try {
			foreach ( array( 'unicode', 'ascii' ) as $mode ) {
				self::install_email_filters( $mode );

				foreach ( $cases as $case ) {
					$expected_valid = $case['expect'][ $mode ];
					$validate       = self::capture_warnings(
						static fn() => \rest_validate_value_from_schema( $case['input'], $email_schema, 'email' )
					);
					$sanitize       = self::capture_warnings(
						static fn() => \rest_sanitize_value_from_schema( $case['input'], $email_schema, 'email' )
					);
					$sanitized      = $sanitize['value'] ?? null;
					$validate_sanitized = is_string( $sanitized )
						? self::capture_warnings(
							static fn() => \rest_validate_value_from_schema( $sanitized, $email_schema, 'email' )
						)
						: array(
							'threw'    => false,
							'value'    => null,
							'warnings' => array(),
						);
					$is_email       = self::capture_warnings( static fn() => \is_email( $case['input'] ) );

					$validation_result           = $validate['value'] ?? null;
					$sanitized_validation_result = $validate_sanitized['value'] ?? null;
					$is_email_result             = $is_email['value'] ?? null;
					$validation_ok               = true === $validation_result;
					$sanitized_validation_ok     = true === $sanitized_validation_result;
					$is_email_ok                 = false !== $is_email_result;
					$validation_error_ok         = $expected_valid || (
						\is_wp_error( $validation_result ) &&
						'rest_invalid_email' === $validation_result->get_error_code()
					);
					$sanitized_validation_error_ok = $expected_valid || (
						\is_wp_error( $sanitized_validation_result ) &&
						'rest_invalid_email' === $sanitized_validation_result->get_error_code()
					);

					$ok = ! $validate['threw']
						&& ! $sanitize['threw']
						&& ! $validate_sanitized['threw']
						&& ! $is_email['threw']
						&& array() === $validate['warnings']
						&& array() === $sanitize['warnings']
						&& array() === $validate_sanitized['warnings']
						&& array() === $is_email['warnings']
						&& $expected_valid === $validation_ok
						&& $expected_valid === $sanitized_validation_ok
						&& $expected_valid === $is_email_ok
						&& $validation_error_ok
						&& $sanitized_validation_error_ok
						&& $case['input'] === $sanitized
						&& ( ! $expected_valid || $case['input'] === $is_email_result );

					if ( ! $ok ) {
						$failures[] = array(
							'mode'              => $mode,
							'label'             => $case['label'],
							'input'             => self::describe_string( $case['input'] ),
							'expectedValid'     => $expected_valid,
							'validate'          => self::describe_captured_call( $validate ),
							'sanitize'          => self::describe_captured_call( $sanitize ),
							'validateSanitized' => self::describe_captured_call( $validate_sanitized ),
							'isEmail'           => self::describe_captured_call( $is_email ),
						);
					}

					$observed[] = array(
						'mode'              => $mode,
						'label'             => $case['label'],
						'input'             => self::describe_string( $case['input'] ),
						'expectedValid'     => $expected_valid,
						'validation'        => self::describe_value( $validation_result ),
						'sanitized'         => self::describe_value( $sanitized ),
						'sanitizedValid'    => self::describe_value( $sanitized_validation_result ),
						'isEmail'           => self::describe_value( $is_email_result ),
					);
				}
			}

			if ( ! self::has_idn() ) {
				$observed[] = array(
					'label'  => 'unicode-local-and-domain',
					'status' => 'skipped-idn-unavailable',
				);
			}
		} finally {
			self::restore_hook_globals( $snapshot );
		}

		return array(
			$ctx->result(
				'email.rest-schema.email-format-filter-modes',
				array() === $failures,
				array(
					'observed' => $observed,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_user_email_indexes_distinct_localparts( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::can_reset_stub_content() ) {
			return array(
				$ctx->skip(
					'email.user-email-indexes.distinct-localparts-preserved',
					'The in-memory wpdb content reset hook is unavailable.'
				),
			);
		}

		$inputs = array(
			'josejose@example.org',
			'joséjosé@example.org',
			"jose\u{0301}jose\u{0301}@example.org",
		);
		$inserted = array();
		$lookups  = array();
		$failures = array();

		self::reset_stub_content();
		try {
			foreach ( $inputs as $index => $input ) {
				$user_id = \wp_insert_user(
					array(
						'user_login' => 'cfz_email_' . $ctx->iteration() . '_' . $index . '_' . substr( sha1( $input ), 0, 10 ),
						'user_pass'  => 'component-fuzz-pass',
						'user_email' => $input,
						'role'       => 'subscriber',
					)
				);

				$inserted[] = $user_id;

				if ( ! is_int( $user_id ) ) {
					$failures[] = array(
						'label'  => 'insert-failed',
						'input'  => self::describe_string( $input ),
						'result' => self::describe_value( $user_id ),
					);
					continue;
				}

				$exists  = \email_exists( $input );
				$by_email = \get_user_by( 'email', $input );
				$lookups[] = array(
					'input'    => self::describe_string( $input ),
					'userId'   => $user_id,
					'exists'   => $exists,
					'byEmail'  => $by_email instanceof \WP_User ? $by_email->ID : self::describe_value( $by_email ),
				);

				if ( $exists !== $user_id || ! ( $by_email instanceof \WP_User ) || $by_email->ID !== $user_id ) {
					$failures[] = array(
						'label'   => 'lookup-mismatch',
						'input'   => self::describe_string( $input ),
						'userId'  => $user_id,
						'exists'  => $exists,
						'byEmail' => self::describe_value( $by_email ),
					);
				}
			}

			$duplicate = \wp_insert_user(
				array(
					'user_login' => 'cfz_email_duplicate_' . $ctx->iteration(),
					'user_pass'  => 'component-fuzz-pass',
					'user_email' => $inputs[1],
					'role'       => 'subscriber',
				)
			);

			if (
				count( array_filter( $inserted, 'is_int' ) ) !== count( $inputs )
				|| count( array_unique( array_filter( $inserted, 'is_int' ), SORT_REGULAR ) ) !== count( $inputs )
			) {
				$failures[] = array(
					'label'    => 'inserted-ids-not-distinct',
					'inserted' => self::describe_value( $inserted ),
				);
			}

			if ( ! \is_wp_error( $duplicate ) || 'existing_user_email' !== $duplicate->get_error_code() ) {
				$failures[] = array(
					'label'     => 'exact-duplicate-not-rejected',
					'duplicate' => self::describe_value( $duplicate ),
				);
			}
		} finally {
			self::reset_stub_content();
		}

		return array(
			$ctx->result(
				'email.user-email-indexes.distinct-localparts-preserved',
				array() === $failures,
				array(
					'inputs'   => array_map( array( self::class, 'describe_string' ), $inputs ),
					'inserted' => self::describe_value( $inserted ),
					'lookups'  => self::describe_value( $lookups ),
					'failures' => self::describe_value( $failures ),
				)
			),
		);
	}

	private static function check_user_email_indexes_generated_localpart_aliases( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::can_reset_stub_content() ) {
			return array(
				$ctx->skip(
					'email.user-email-indexes.generated-localpart-aliases-clean',
					'The in-memory wpdb content reset hook is unavailable.'
				),
			);
		}

		$cases         = self::generated_localpart_alias_cases( $ctx->fork( 'user-email-localpart-aliases' ) );
		$failures      = array();
		$observed      = array();
		$hook_snapshot = self::snapshot_hook_globals();
		$not_called    = static function (): array {
			return array(
				'threw'    => false,
				'value'    => null,
				'warnings' => array(),
			);
		};

		self::reset_stub_content();
		try {
			\remove_all_filters( 'pre_user_email' );
			\add_filter( 'pre_user_email', 'trim' );
			\add_filter( 'pre_user_email', 'sanitize_email' );
			if ( function_exists( 'wp_filter_kses' ) ) {
				\add_filter( 'pre_user_email', 'wp_filter_kses' );
			}

			foreach ( $cases as $case_index => $case ) {
				self::reset_stub_content();

				$inserted            = array();
				$lookups             = array();
				$canonical_addresses = array();

				foreach ( $case['addresses'] as $address_index => $input ) {
					$parse     = self::capture_warnings( static fn() => \WP_Email_Address::from_string( $input, 'unicode' ) );
					$email     = $parse['value'] ?? null;
					$canonical = $email instanceof \WP_Email_Address ? $email->get_unicode_address() : null;
					$login     = 'cfz_alias_local_' . $ctx->iteration() . '_' . $case_index . '_' . $address_index . '_' . substr( sha1( $input ), 0, 8 );
					$insert    = self::capture_warnings(
						static fn() => \wp_insert_user(
							array(
								'user_login' => $login,
								'user_pass'  => 'component-fuzz-pass',
								'user_email' => $input,
								'role'       => 'subscriber',
							)
						)
					);
					$user_id   = $insert['value'] ?? null;
					$inserted[] = $user_id;

					$stored           = is_int( $user_id ) ? self::capture_warnings( static fn() => \get_user_by( 'id', $user_id ) ) : $not_called();
					$exists_input     = is_int( $user_id ) ? self::capture_warnings( static fn() => \email_exists( $input ) ) : $not_called();
					$by_input         = is_int( $user_id ) ? self::capture_warnings( static fn() => \get_user_by( 'email', $input ) ) : $not_called();
					$exists_canonical = is_int( $user_id ) && is_string( $canonical ) ? self::capture_warnings( static fn() => \email_exists( $canonical ) ) : $not_called();
					$by_canonical     = is_int( $user_id ) && is_string( $canonical ) ? self::capture_warnings( static fn() => \get_user_by( 'email', $canonical ) ) : $not_called();
					$stored_user      = $stored['value'] ?? null;
					$by_input_user    = $by_input['value'] ?? null;
					$by_canonical_user = $by_canonical['value'] ?? null;

					if ( is_string( $canonical ) ) {
						$canonical_addresses[] = $canonical;
					}

					$ok = ! $parse['threw']
						&& ! $insert['threw']
						&& ! $stored['threw']
						&& ! $exists_input['threw']
						&& ! $by_input['threw']
						&& ! $exists_canonical['threw']
						&& ! $by_canonical['threw']
						&& array() === $parse['warnings']
						&& array() === $insert['warnings']
						&& array() === $stored['warnings']
						&& array() === $exists_input['warnings']
						&& array() === $by_input['warnings']
						&& array() === $exists_canonical['warnings']
						&& array() === $by_canonical['warnings']
						&& $email instanceof \WP_Email_Address
						&& is_int( $user_id )
						&& $stored_user instanceof \WP_User
						&& $stored_user->ID === $user_id
						&& $canonical === $stored_user->user_email
						&& $exists_input['value'] === $user_id
						&& $by_input_user instanceof \WP_User
						&& $by_input_user->ID === $user_id
						&& $exists_canonical['value'] === $user_id
						&& $by_canonical_user instanceof \WP_User
						&& $by_canonical_user->ID === $user_id;

					if ( ! $ok ) {
						$failures[] = array(
							'label'           => $case['label'],
							'input'           => self::describe_string( $input ),
							'canonical'       => is_string( $canonical ) ? self::describe_string( $canonical ) : null,
							'parse'           => self::describe_captured_call( $parse ),
							'insert'          => self::describe_captured_call( $insert ),
							'stored'          => self::describe_captured_call( $stored ),
							'existsInput'     => self::describe_captured_call( $exists_input ),
							'byInput'         => self::describe_captured_call( $by_input ),
							'existsCanonical' => self::describe_captured_call( $exists_canonical ),
							'byCanonical'     => self::describe_captured_call( $by_canonical ),
						);
					}

					$lookups[] = array(
						'input'     => self::describe_string( $input ),
						'canonical' => is_string( $canonical ) ? self::describe_string( $canonical ) : null,
						'userId'    => $user_id,
						'warnings'  => count( $parse['warnings'] ) + count( $insert['warnings'] ) + count( $stored['warnings'] ) + count( $exists_input['warnings'] ) + count( $by_input['warnings'] ) + count( $exists_canonical['warnings'] ) + count( $by_canonical['warnings'] ),
					);
				}

				$ids = array_values( array_filter( $inserted, 'is_int' ) );
				if ( count( $ids ) !== count( $case['addresses'] ) || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $case['addresses'] ) ) {
					$failures[] = array(
						'label'    => $case['label'],
						'failure'  => 'inserted-ids-not-distinct',
						'inserted' => self::describe_value( $inserted ),
					);
				}

				if ( count( $canonical_addresses ) !== count( $case['addresses'] ) || count( array_unique( $canonical_addresses, SORT_REGULAR ) ) !== count( $case['addresses'] ) ) {
					$failures[] = array(
						'label'     => $case['label'],
						'failure'   => 'canonical-addresses-not-distinct',
						'addresses' => self::describe_value( $canonical_addresses ),
					);
				}

				$duplicate = self::capture_warnings(
					static fn() => \wp_insert_user(
						array(
							'user_login' => 'cfz_alias_local_duplicate_' . $ctx->iteration() . '_' . $case_index,
							'user_pass'  => 'component-fuzz-pass',
							'user_email' => $case['addresses'][1],
							'role'       => 'subscriber',
						)
					)
				);

				if (
					$duplicate['threw'] ||
					array() !== $duplicate['warnings'] ||
					! \is_wp_error( $duplicate['value'] ?? null ) ||
					'existing_user_email' !== $duplicate['value']->get_error_code()
				) {
					$failures[] = array(
						'label'     => $case['label'],
						'failure'   => 'exact-duplicate-not-rejected-cleanly',
						'duplicate' => self::describe_captured_call( $duplicate ),
					);
				}

				$hostile_lookup    = $case['addresses'][0];
				$hostile_candidate = $case['addresses'][1] ?? null;
				$hostile_probe     = array(
					'ok'     => false,
					'reason' => 'not-run',
				);
				if ( is_string( $hostile_candidate ) && $hostile_lookup !== $hostile_candidate ) {
					self::reset_stub_content();
					$hostile_seed = self::seed_stub_user_email_row(
						$hostile_candidate,
						'cfz_alias_local_hostile_' . $ctx->iteration() . '_' . $case_index
					);
					if ( ! $hostile_seed['threw'] && array() === $hostile_seed['warnings'] && is_int( $hostile_seed['value'] ?? null ) ) {
						$hostile_probe = self::hostile_user_email_lookup_probe( $hostile_lookup, $hostile_candidate );
					} else {
						$hostile_probe = array(
							'ok'     => false,
							'reason' => 'candidate-seed-failed',
							'seed'   => self::describe_captured_call( $hostile_seed ),
						);
					}
					self::reset_stub_content();
				}

				if ( true !== ( $hostile_probe['ok'] ?? false ) ) {
					$failures[] = array(
						'label'     => $case['label'],
						'failure'   => 'accent-folded-db-candidate-not-ignored',
						'lookup'    => self::describe_string( $hostile_lookup ),
						'candidate' => is_string( $hostile_candidate ) ? self::describe_string( $hostile_candidate ) : null,
						'probe'     => self::describe_value( $hostile_probe ),
					);
				}

				$observed[] = array(
					'label'          => $case['label'],
					'profile'        => $case['profile'],
					'domain'         => self::describe_string( $case['domain'] ),
					'addressCount'   => count( $case['addresses'] ),
					'insertedIds'    => self::describe_value( $inserted ),
					'lookups'        => self::describe_value( $lookups ),
					'duplicateOk'    => ! $duplicate['threw'] && array() === $duplicate['warnings'] && \is_wp_error( $duplicate['value'] ?? null ),
					'hostileLookup' => array(
						'lookup'    => self::describe_string( $hostile_lookup ),
						'candidate' => is_string( $hostile_candidate ) ? self::describe_string( $hostile_candidate ) : null,
						'ok'        => true === ( $hostile_probe['ok'] ?? false ),
					),
				);
			}
		} finally {
			self::restore_hook_globals( $hook_snapshot );
			self::reset_stub_content();
		}

		return array(
			$ctx->result(
				'email.user-email-indexes.generated-localpart-aliases-clean',
				array() === $failures,
				array(
					'caseCount' => count( $cases ),
					'observed'  => $observed,
					'failures'  => self::describe_value( $failures ),
				)
			),
		);
	}

	private static function check_user_email_search_unicode_terms( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! class_exists( 'WP_User_Query' ) ) {
			return array(
				$ctx->skip(
					'email.user-email-search.unicode-terms-byte-preserving',
					'WP_User_Query is unavailable.'
				),
			);
		}
		if ( ! self::can_reset_stub_content() ) {
			return array(
				$ctx->skip(
					'email.user-email-search.unicode-terms-byte-preserving',
					'The in-memory wpdb content reset hook is unavailable.'
				),
			);
		}

		$cases         = self::generated_user_search_cases( $ctx->fork( 'user-email-search-unicode-terms' ) );
		$failures      = array();
		$observed      = array();
		$hook_snapshot = self::snapshot_hook_globals();

		try {
			foreach ( $cases as $case ) {
				$unicode_parse = self::call( static fn() => \WP_Email_Address::from_string( $case['unicodeAddress'], 'unicode' ) );
				$folded_parse  = self::call( static fn() => \WP_Email_Address::from_string( $case['foldedAddress'], 'unicode' ) );
				$unicode_local = self::email_localpart( $case['unicodeAddress'] );
				$folded_local  = self::email_localpart( $case['foldedAddress'] );
				$full_query    = self::prepare_user_email_search_query( $case['unicodeAddress'] );
				$wild_query    = self::prepare_user_email_search_query( '*' . $unicode_local . '*', array( 'user_email' ) );
				$folded_query  = self::prepare_user_email_search_query( '*' . $folded_local . '*', array( 'user_email' ) );
				$default_query = self::prepare_user_email_search_query( '*' . $unicode_local . '*' );
				$full_where    = is_array( $full_query['value'] ?? null ) ? (string) ( $full_query['value']['queryWhere'] ?? '' ) : '';
				$wild_where    = is_array( $wild_query['value'] ?? null ) ? (string) ( $wild_query['value']['queryWhere'] ?? '' ) : '';
				$folded_where  = is_array( $folded_query['value'] ?? null ) ? (string) ( $folded_query['value']['queryWhere'] ?? '' ) : '';
				$default_where = is_array( $default_query['value'] ?? null ) ? (string) ( $default_query['value']['queryWhere'] ?? '' ) : '';
				$full_likes    = self::sql_like_literals( $full_where, 'user_email' );
				$wild_likes    = self::sql_like_literals( $wild_where, 'user_email' );
				$folded_likes  = self::sql_like_literals( $folded_where, 'user_email' );
				$default_likes = self::sql_like_literals( $default_where, 'user_email' );
				$full_other    = self::sql_like_literals_for_columns( $full_where, array( 'user_login', 'user_url', 'user_nicename', 'display_name' ) );
				$wild_other    = self::sql_like_literals_for_columns( $wild_where, array( 'user_login', 'user_url', 'user_nicename', 'display_name' ) );
				$folded_other  = self::sql_like_literals_for_columns( $folded_where, array( 'user_login', 'user_url', 'user_nicename', 'display_name' ) );
				$default_other = self::sql_like_literals_for_columns( $default_where, array( 'user_login', 'user_url', 'user_nicename', 'display_name' ) );
				$default_other_literals = self::flatten_sql_like_literal_groups( $default_other );
				$expected_full = $case['unicodeAddress'];
				$expected_wild = '%' . $unicode_local . '%';
				$folded_wild   = '%' . $folded_local . '%';
				$result_probe  = self::probe_user_email_localpart_search_results( $ctx, $case );

				$ok = ! $unicode_parse['threw']
					&& ! $folded_parse['threw']
					&& ! $full_query['threw']
					&& ! $wild_query['threw']
					&& ! $folded_query['threw']
					&& ! $default_query['threw']
					&& $unicode_parse['value'] instanceof \WP_Email_Address
					&& $folded_parse['value'] instanceof \WP_Email_Address
					&& array() === ( $full_query['warnings'] ?? array() )
					&& array() === ( $wild_query['warnings'] ?? array() )
					&& array() === ( $folded_query['warnings'] ?? array() )
					&& array() === ( $default_query['warnings'] ?? array() )
					&& array( $expected_full ) === $full_likes
					&& array( $expected_wild ) === $wild_likes
					&& array( $folded_wild ) === $folded_likes
					&& array( $expected_wild ) === $default_likes
					&& array() === $full_other
					&& array() === $wild_other
					&& array() === $folded_other
					&& count( $default_other_literals ) >= 4
					&& array() === array_diff( $default_other_literals, array( $expected_wild ) )
					&& $case['unicodeAddress'] !== $case['foldedAddress']
					&& $unicode_local !== $folded_local
					&& $expected_wild !== $folded_wild
					&& bin2hex( $expected_wild ) !== bin2hex( $folded_wild )
					&& true === ( $result_probe['ok'] ?? false );

				if ( ! $ok ) {
					$failures[] = array(
						'label'        => $case['label'],
						'profile'      => $case['profile'],
						'unicode'      => self::describe_string( $case['unicodeAddress'] ),
						'folded'       => self::describe_string( $case['foldedAddress'] ),
						'unicodeLocal' => self::describe_string( $unicode_local ),
						'foldedLocal'  => self::describe_string( $folded_local ),
						'unicodeParse' => self::describe_call( $unicode_parse ),
						'foldedParse'  => self::describe_call( $folded_parse ),
						'fullQuery'    => self::describe_captured_call( $full_query ),
						'wildQuery'    => self::describe_captured_call( $wild_query ),
						'foldedQuery'  => self::describe_captured_call( $folded_query ),
						'defaultQuery' => self::describe_captured_call( $default_query ),
						'fullLikes'    => self::describe_value( $full_likes ),
						'wildLikes'    => self::describe_value( $wild_likes ),
						'foldedLikes'  => self::describe_value( $folded_likes ),
						'defaultLikes' => self::describe_value( $default_likes ),
						'fullOther'    => self::describe_value( $full_other ),
						'wildOther'    => self::describe_value( $wild_other ),
						'foldedOther'  => self::describe_value( $folded_other ),
						'defaultOther' => self::describe_value( $default_other ),
						'defaultOtherLiterals' => self::describe_value( $default_other_literals ),
						'resultProbe'  => self::describe_value( $result_probe ),
					);
				}

				$observed[] = array(
					'label'        => $case['label'],
					'profile'      => $case['profile'],
					'unicode'      => self::describe_string( $case['unicodeAddress'] ),
					'folded'       => self::describe_string( $case['foldedAddress'] ),
					'unicodeLocal' => self::describe_string( $unicode_local ),
					'foldedLocal'  => self::describe_string( $folded_local ),
					'fullLikes'    => self::describe_value( $full_likes ),
					'wildLikes'    => self::describe_value( $wild_likes ),
					'foldedLikes'  => self::describe_value( $folded_likes ),
					'resultProbe'  => array(
						'unicode' => $result_probe['unicode'] ?? null,
						'folded'  => $result_probe['folded'] ?? null,
					),
				);
			}
		} finally {
			self::restore_hook_globals( $hook_snapshot );
			self::reset_stub_content();
		}

		return array(
			$ctx->result(
				'email.user-email-search.unicode-terms-byte-preserving',
				array() === $failures,
				array(
					'caseCount' => count( $cases ),
					'observed'  => $observed,
					'failures'  => self::describe_value( $failures ),
				)
			),
		);
	}

	private static function check_user_email_indexes_distinct_domains( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::can_reset_stub_content() ) {
			return array(
				$ctx->skip(
					'email.user-email-indexes.distinct-domains-preserved',
					'The in-memory wpdb content reset hook is unavailable.'
				),
			);
		}

		if ( ! self::has_idn() ) {
			return array(
				$ctx->skip(
					'email.user-email-indexes.distinct-domains-preserved',
					'idn_to_ascii() or idn_to_utf8() is unavailable.'
				),
			);
		}

		$inputs = array(
			'mail@gra.org',
			"mail@gr\u{00E5}.org",
			'mail@bucher.de',
			"mail@b\u{00FC}cher.de",
			"jos\u{00E9}@example.org",
			"jos\u{00E9}@gr\u{00E5}.org",
		);
		$inserted = array();
		$lookups  = array();
		$failures = array();

		self::reset_stub_content();
		try {
			foreach ( $inputs as $index => $input ) {
				$insert = self::capture_warnings(
					static fn() => \wp_insert_user(
						array(
							'user_login' => 'cfz_domain_email_' . $ctx->iteration() . '_' . $index . '_' . substr( sha1( $input ), 0, 10 ),
							'user_pass'  => 'component-fuzz-pass',
							'user_email' => $input,
							'role'       => 'subscriber',
						)
					)
				);
				$user_id = $insert['value'] ?? null;
				$inserted[] = $user_id;

				if ( $insert['threw'] || array() !== $insert['warnings'] || ! is_int( $user_id ) ) {
					$failures[] = array(
						'label'  => 'insert-failed-or-warned',
						'input'  => self::describe_string( $input ),
						'insert' => self::describe_captured_call( $insert ),
					);
					continue;
				}

				$exists  = self::capture_warnings( static fn() => \email_exists( $input ) );
				$by_email = self::capture_warnings( static fn() => \get_user_by( 'email', $input ) );
				$user     = $by_email['value'] ?? null;
				$lookups[] = array(
					'input'    => self::describe_string( $input ),
					'userId'   => $user_id,
					'exists'   => $exists['value'] ?? null,
					'byEmail'  => $user instanceof \WP_User ? $user->ID : self::describe_value( $user ),
					'warnings' => count( $exists['warnings'] ) + count( $by_email['warnings'] ),
				);

				if (
					$exists['threw'] ||
					$by_email['threw'] ||
					array() !== $exists['warnings'] ||
					array() !== $by_email['warnings'] ||
					$exists['value'] !== $user_id ||
					! ( $user instanceof \WP_User ) ||
					$user->ID !== $user_id ||
					$user->user_email !== $input
				) {
					$failures[] = array(
						'label'   => 'lookup-mismatch-or-warning',
						'input'   => self::describe_string( $input ),
						'userId'  => $user_id,
						'exists'  => self::describe_captured_call( $exists ),
						'byEmail' => self::describe_captured_call( $by_email ),
					);
				}
			}

			$ids = array_values( array_filter( $inserted, 'is_int' ) );
			if ( count( $ids ) !== count( $inputs ) || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $inputs ) ) {
				$failures[] = array(
					'label'    => 'inserted-ids-not-distinct',
					'inserted' => self::describe_value( $inserted ),
				);
			}

			$duplicate = self::capture_warnings(
				static fn() => \wp_insert_user(
					array(
						'user_login' => 'cfz_domain_email_duplicate_' . $ctx->iteration(),
						'user_pass'  => 'component-fuzz-pass',
						'user_email' => $inputs[1],
						'role'       => 'subscriber',
					)
				)
			);
			$missing   = self::capture_warnings( static fn() => \email_exists( "missing@gr\u{00E5}.org" ) );

			if (
				$duplicate['threw'] ||
				array() !== $duplicate['warnings'] ||
				! \is_wp_error( $duplicate['value'] ?? null ) ||
				'existing_user_email' !== $duplicate['value']->get_error_code()
			) {
				$failures[] = array(
					'label'     => 'exact-duplicate-not-rejected-cleanly',
					'duplicate' => self::describe_captured_call( $duplicate ),
				);
			}

			if ( $missing['threw'] || array() !== $missing['warnings'] || false !== $missing['value'] ) {
				$failures[] = array(
					'label'   => 'missing-unicode-domain-lookup-not-false-cleanly',
					'missing' => self::describe_captured_call( $missing ),
				);
			}
		} finally {
			self::reset_stub_content();
		}

		return array(
			$ctx->result(
				'email.user-email-indexes.distinct-domains-preserved',
				array() === $failures,
				array(
					'inputs'   => array_map( array( self::class, 'describe_string' ), $inputs ),
					'inserted' => self::describe_value( $inserted ),
					'lookups'  => self::describe_value( $lookups ),
					'failures' => self::describe_value( $failures ),
				)
			),
		);
	}

	private static function check_user_email_indexes_canonical_domain_aliases( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::can_reset_stub_content() ) {
			return array(
				$ctx->skip(
					'email.user-email-indexes.canonical-domain-aliases',
					'The in-memory wpdb content reset hook is unavailable.'
				),
			);
		}

		if ( ! self::has_idn() ) {
			return array(
				$ctx->skip(
					'email.user-email-indexes.canonical-domain-aliases',
					'idn_to_ascii() or idn_to_utf8() is unavailable.'
				),
			);
		}

		if ( ! function_exists( 'wp_update_user' ) ) {
			return array(
				$ctx->skip(
					'email.user-email-indexes.canonical-domain-aliases',
					'wp_update_user() is unavailable.'
				),
			);
		}

		$domain_aliases = array(
			"gr\u{00E5}.org",
			"b\u{00FC}cher.de",
			"fa\u{00DF}.de",
			"\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}",
			"\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}",
			"\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}",
			"\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}",
		);
		$localparts     = array(
			'mail',
			'USER+tag',
			"gr\u{00E5}",
			"jose\u{0301}",
			"\u{7528}\u{6237}",
			"\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}",
			"\u{3086}\u{3046}\u{3056}\u{3042}",
		);
		$alias_ctx      = $ctx->fork( 'user-email-canonical-domain-aliases' );
		$cases          = array();
		$failures       = array();
		$observed       = array();
		$mail_calls     = array();
		$not_called     = static function (): array {
			return array(
				'threw'    => false,
				'value'    => null,
				'warnings' => array(),
			);
		};
		$mail_filter    = static function ( $return, $atts ) use ( &$mail_calls ) {
			$mail_calls[] = $atts;
			return true;
		};

		for ( $i = 0; $i < self::GENERATED_DOMAIN_ALIAS_CASES; $i++ ) {
			$unicode_domain = $alias_ctx->choice( $domain_aliases );
			$ascii_domain   = idn_to_ascii( $unicode_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$decoded_domain = false === $ascii_domain
				? false
				: idn_to_utf8( $ascii_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$localpart      = $alias_ctx->choice( $localparts ) . $alias_ctx->int( 10, 99 );

			if ( false === $ascii_domain || false === $decoded_domain ) {
				$failures[] = array(
					'label'  => 'idn-conversion-failed',
					'domain' => self::describe_string( $unicode_domain ),
				);
				continue;
			}

			$unicode_input = $localpart . '@' . $decoded_domain;
			$machine_input = $localpart . '@' . $ascii_domain;
			$unicode_parse = self::call( static fn() => \WP_Email_Address::from_string( $unicode_input, 'unicode' ) );
			$machine_parse = self::call( static fn() => \WP_Email_Address::from_string( $machine_input, 'unicode' ) );
			$unicode_email = $unicode_parse['value'] ?? null;
			$machine_email = $machine_parse['value'] ?? null;

			if (
				$unicode_parse['threw'] ||
				$machine_parse['threw'] ||
				! ( $unicode_email instanceof \WP_Email_Address ) ||
				! ( $machine_email instanceof \WP_Email_Address ) ||
				$unicode_email->get_ascii_address() !== $machine_email->get_ascii_address() ||
				$unicode_email->get_unicode_address() !== $machine_email->get_unicode_address()
			) {
				$failures[] = array(
					'label'        => 'alias-parse-mismatch',
					'unicodeInput' => self::describe_string( $unicode_input ),
					'machineInput' => self::describe_string( $machine_input ),
					'unicodeParse' => self::describe_call( $unicode_parse ),
					'machineParse' => self::describe_call( $machine_parse ),
				);
				continue;
			}

			$cases[] = array(
				'label'     => 'generated-domain-alias-' . $i,
				'canonical' => $unicode_email->get_unicode_address(),
				'machine'   => $unicode_email->get_ascii_address(),
				'domain'    => $unicode_email->get_unicode_domain(),
				'ascii'     => $unicode_email->get_ascii_domain(),
			);
		}

		$hook_snapshot = self::snapshot_hook_globals();

		self::reset_stub_content();
		try {
			\remove_all_filters( 'pre_user_email' );
			\add_filter( 'pre_user_email', 'trim' );
			\add_filter( 'pre_user_email', 'sanitize_email' );
			if ( function_exists( 'wp_filter_kses' ) ) {
				\add_filter( 'pre_user_email', 'wp_filter_kses' );
			}
			\remove_all_filters( 'pre_wp_mail' );
			\add_filter( 'pre_wp_mail', $mail_filter, PHP_INT_MAX, 2 );

			foreach ( $cases as $index => $case ) {
				self::reset_stub_content();

				$canonical = $case['canonical'];
				$machine   = $case['machine'];
				$login     = 'cfz_alias_' . $ctx->iteration() . '_' . $index . '_' . substr( sha1( $canonical ), 0, 10 );
				$other     = 'cfz_alias_other_' . $ctx->iteration() . '_' . $index . '_' . substr( sha1( $machine ), 0, 10 );
				$other_email = 'other-' . substr( sha1( $canonical ), 0, 12 ) . '@example.org';
				$mail_count_before = count( $mail_calls );

				$canonical_sanitized = self::capture_warnings( static fn() => \sanitize_email( $canonical ) );
				$machine_sanitized   = self::capture_warnings( static fn() => \sanitize_email( $machine ) );
				$canonical_valid     = self::capture_warnings( static fn() => \is_email( $canonical ) );
				$machine_valid       = self::capture_warnings( static fn() => \is_email( $machine ) );
				$insert              = self::capture_warnings(
					static fn() => \wp_insert_user(
						array(
							'user_login' => $login,
							'user_pass'  => 'component-fuzz-pass',
							'user_email' => $machine,
							'role'       => 'subscriber',
						)
					)
				);
				$user_id             = $insert['value'] ?? null;
				$stored              = is_int( $user_id ) ? self::capture_warnings( static fn() => \get_user_by( 'id', $user_id ) ) : $not_called();
				$exists              = is_int( $user_id ) ? self::capture_warnings( static fn() => \email_exists( $canonical ) ) : $not_called();
				$by_email            = is_int( $user_id ) ? self::capture_warnings( static fn() => \get_user_by( 'email', $canonical ) ) : $not_called();
				$machine_exists      = is_int( $user_id ) ? self::capture_warnings( static fn() => \email_exists( $machine ) ) : $not_called();
				$machine_by_email    = is_int( $user_id ) ? self::capture_warnings( static fn() => \get_user_by( 'email', $machine ) ) : $not_called();
				$duplicate           = is_int( $user_id )
					? self::capture_warnings(
						static fn() => \wp_insert_user(
							array(
								'user_login' => $login . '_duplicate',
								'user_pass'  => 'component-fuzz-pass',
								'user_email' => $canonical,
								'role'       => 'subscriber',
							)
						)
					)
					: $not_called();
				$self_update         = is_int( $user_id )
					? self::capture_warnings(
						static fn() => \wp_update_user(
							array(
								'ID'         => $user_id,
								'user_email' => $canonical,
							)
						)
					)
					: $not_called();
				$stored_after_update = is_int( $user_id ) ? self::capture_warnings( static fn() => \get_user_by( 'id', $user_id ) ) : $not_called();
				$other_insert        = self::capture_warnings(
					static fn() => \wp_insert_user(
						array(
							'user_login' => $other,
							'user_pass'  => 'component-fuzz-pass',
							'user_email' => $other_email,
							'role'       => 'subscriber',
						)
					)
				);
				$other_id            = $other_insert['value'] ?? null;
				$collision_update    = is_int( $other_id )
					? self::capture_warnings(
						static fn() => \wp_update_user(
							array(
								'ID'         => $other_id,
								'user_email' => $machine,
							)
						)
					)
					: $not_called();
				$stored_user         = $stored['value'] ?? null;
				$by_email_user       = $by_email['value'] ?? null;
				$machine_email_user  = $machine_by_email['value'] ?? null;
				$updated_user        = $stored_after_update['value'] ?? null;
				$other_after_collision = is_int( $other_id ) ? self::capture_warnings( static fn() => \get_user_by( 'id', $other_id ) ) : $not_called();
				$other_after_user    = $other_after_collision['value'] ?? null;

				$ok = ! $canonical_sanitized['threw']
					&& ! $machine_sanitized['threw']
					&& ! $canonical_valid['threw']
					&& ! $machine_valid['threw']
					&& ! $insert['threw']
					&& ! $stored['threw']
					&& ! $exists['threw']
					&& ! $by_email['threw']
					&& ! $machine_exists['threw']
					&& ! $machine_by_email['threw']
					&& ! $duplicate['threw']
					&& ! $self_update['threw']
					&& ! $stored_after_update['threw']
					&& ! $other_insert['threw']
					&& ! $collision_update['threw']
					&& ! $other_after_collision['threw']
					&& array() === $canonical_sanitized['warnings']
					&& array() === $machine_sanitized['warnings']
					&& array() === $canonical_valid['warnings']
					&& array() === $machine_valid['warnings']
					&& array() === $insert['warnings']
					&& array() === $stored['warnings']
					&& array() === $exists['warnings']
					&& array() === $by_email['warnings']
					&& array() === $machine_exists['warnings']
					&& array() === $machine_by_email['warnings']
					&& array() === $duplicate['warnings']
					&& array() === $self_update['warnings']
					&& array() === $stored_after_update['warnings']
					&& array() === $other_insert['warnings']
					&& array() === $collision_update['warnings']
					&& array() === $other_after_collision['warnings']
					&& $canonical === $canonical_sanitized['value']
					&& $canonical === $machine_sanitized['value']
					&& $canonical === $canonical_valid['value']
					&& $canonical === $machine_valid['value']
					&& is_int( $user_id )
					&& $stored_user instanceof \WP_User
					&& $canonical === $stored_user->user_email
					&& $exists['value'] === $user_id
					&& $by_email_user instanceof \WP_User
					&& $by_email_user->ID === $user_id
					&& $canonical === $by_email_user->user_email
					&& false === $machine_exists['value']
					&& false === $machine_email_user
					&& \is_wp_error( $duplicate['value'] ?? null )
					&& 'existing_user_email' === $duplicate['value']->get_error_code()
					&& $self_update['value'] === $user_id
					&& $updated_user instanceof \WP_User
					&& $canonical === $updated_user->user_email
					&& is_int( $other_id )
					&& \is_wp_error( $collision_update['value'] ?? null )
					&& 'existing_user_email' === $collision_update['value']->get_error_code()
					&& $other_after_user instanceof \WP_User
					&& $other_after_user->ID === $other_id
					&& $other_email === $other_after_user->user_email
					&& count( $mail_calls ) === $mail_count_before;

				if ( ! $ok ) {
					$failures[] = array(
						'label'              => $case['label'],
						'canonical'          => self::describe_string( $canonical ),
						'machine'            => self::describe_string( $machine ),
						'canonicalSanitized' => self::describe_captured_call( $canonical_sanitized ),
						'machineSanitized'   => self::describe_captured_call( $machine_sanitized ),
						'canonicalValid'     => self::describe_captured_call( $canonical_valid ),
						'machineValid'       => self::describe_captured_call( $machine_valid ),
						'insert'             => self::describe_captured_call( $insert ),
						'stored'             => self::describe_captured_call( $stored ),
						'exists'             => self::describe_captured_call( $exists ),
						'byEmail'            => self::describe_captured_call( $by_email ),
						'machineExists'      => self::describe_captured_call( $machine_exists ),
						'machineByEmail'     => self::describe_captured_call( $machine_by_email ),
						'duplicate'          => self::describe_captured_call( $duplicate ),
						'selfUpdate'         => self::describe_captured_call( $self_update ),
						'storedAfterUpdate'  => self::describe_captured_call( $stored_after_update ),
						'otherInsert'        => self::describe_captured_call( $other_insert ),
						'collisionUpdate'    => self::describe_captured_call( $collision_update ),
						'otherAfterCollision' => self::describe_captured_call( $other_after_collision ),
						'mailCalls'          => self::describe_value( array_slice( $mail_calls, $mail_count_before ) ),
					);
				}

				$observed[] = array(
					'label'             => $case['label'],
					'canonical'         => self::describe_string( $canonical ),
					'machine'           => self::describe_string( $machine ),
					'domain'            => self::describe_string( $case['domain'] ),
					'asciiDomain'       => self::describe_string( $case['ascii'] ),
					'userId'            => $user_id,
					'otherId'           => $other_id,
					'storedCanonical'   => $stored_user instanceof \WP_User && $canonical === $stored_user->user_email,
					'machineLookupMiss' => false === ( $machine_exists['value'] ?? null ) && false === $machine_email_user,
					'duplicateRejected' => \is_wp_error( $duplicate['value'] ?? null ),
					'collisionRejected' => \is_wp_error( $collision_update['value'] ?? null ),
					'collisionLeftOtherUnchanged' => $other_after_user instanceof \WP_User && $other_email === $other_after_user->user_email,
					'mailCalls'         => count( $mail_calls ) - $mail_count_before,
				);
			}
		} finally {
			self::restore_hook_globals( $hook_snapshot );
			self::reset_stub_content();
		}

		return array(
			$ctx->result(
				'email.user-email-indexes.canonical-domain-aliases',
				array() === $failures,
				array(
					'caseCount' => count( $cases ),
					'observed'  => $observed,
					'failures'  => self::describe_value( $failures ),
				)
			),
		);
	}

	private static function check_password_reset_unicode_email_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::can_reset_stub_content() ) {
			return array(
				$ctx->skip(
					'email.password-reset.unicode-email-paths',
					'The in-memory wpdb content reset hook is unavailable.'
				),
			);
		}

		$required = array(
			'retrieve_password',
			'wp_mail',
			'add_filter',
			'remove_filter',
		);
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				return array(
					$ctx->skip(
						'email.password-reset.unicode-email-paths',
						'Required password reset APIs are unavailable.',
						array( 'missing' => 'function ' . $function )
					),
				);
			}
		}

		$cases = array(
			array(
				'label' => 'unicode-local',
				'email' => "jos\u{00E9}.reset@example.org",
			),
		);

		if ( self::has_idn() ) {
			$cases[] = array(
				'label' => 'unicode-domain',
				'email' => "reset@gr\u{00E5}.org",
			);
			$cases[] = array(
				'label' => 'unicode-local-and-domain',
				'email' => "jos\u{00E9}.reset@gr\u{00E5}.org",
			);
		}

		$failures        = array();
		$observed        = array();
		$hook_snapshot   = self::snapshot_hook_globals();
		$server_snapshot = array_key_exists( 'REMOTE_ADDR', $_SERVER )
			? array( 'exists' => true, 'value' => $_SERVER['REMOTE_ADDR'] )
			: array( 'exists' => false, 'value' => null );

		self::reset_stub_content();
		try {
			$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

			foreach ( $cases as $index => $case ) {
				$mail_calls = array();
				$mail_filter = static function ( $return, $atts ) use ( &$mail_calls ) {
					$mail_calls[] = $atts;
					return true;
				};

				\add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
				try {
					$login  = 'cfz_reset_' . $ctx->iteration() . '_' . $index . '_' . substr( sha1( $case['email'] ), 0, 8 );
					$insert = self::capture_warnings(
						static fn() => \wp_insert_user(
							array(
								'user_login' => $login,
								'user_pass'  => 'component-fuzz-pass',
								'user_email' => $case['email'],
								'role'       => 'subscriber',
							)
						)
					);
					$user_id = $insert['value'] ?? null;
					$reset   = is_int( $user_id )
						? self::capture_warnings( static fn() => \retrieve_password( $case['email'] ) )
						: array(
							'threw'    => false,
							'value'    => null,
							'warnings' => array(),
						);
					$mail    = $mail_calls[0] ?? null;

					$ok = ! $insert['threw']
						&& array() === $insert['warnings']
						&& is_int( $user_id )
						&& ! $reset['threw']
						&& array() === $reset['warnings']
						&& true === $reset['value']
						&& 1 === count( $mail_calls )
						&& is_array( $mail )
						&& $case['email'] === ( $mail['to'] ?? null )
						&& is_string( $mail['subject'] ?? null )
						&& '' !== ( $mail['subject'] ?? '' )
						&& is_string( $mail['message'] ?? null )
						&& str_contains( $mail['message'], rawurlencode( $login ) );

					if ( ! $ok ) {
						$failures[] = array(
							'label'     => $case['label'],
							'email'     => self::describe_string( $case['email'] ),
							'login'     => $login,
							'insert'    => self::describe_captured_call( $insert ),
							'reset'     => self::describe_captured_call( $reset ),
							'mailCalls' => self::describe_value( $mail_calls ),
						);
					}

					$observed[] = array(
						'label'     => $case['label'],
						'email'     => self::describe_string( $case['email'] ),
						'userId'    => $user_id,
						'reset'     => self::describe_value( $reset['value'] ?? null ),
						'mailCalls' => count( $mail_calls ),
						'mailTo'    => isset( $mail['to'] ) && is_string( $mail['to'] ) ? self::describe_string( $mail['to'] ) : null,
						'warnings'  => count( $insert['warnings'] ) + count( $reset['warnings'] ),
					);
				} finally {
					\remove_filter( 'pre_wp_mail', $mail_filter, 10 );
				}
			}
		} finally {
			if ( $server_snapshot['exists'] ) {
				$_SERVER['REMOTE_ADDR'] = $server_snapshot['value'];
			} else {
				unset( $_SERVER['REMOTE_ADDR'] );
			}
			self::restore_hook_globals( $hook_snapshot );
			self::reset_stub_content();
		}

		return array(
			$ctx->result(
				'email.password-reset.unicode-email-paths',
				array() === $failures,
				array(
					'observed' => $observed,
					'failures' => self::describe_value( $failures ),
				)
			),
		);
	}

	private static function check_punycode_views( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::has_idn() ) {
			return array(
				$ctx->skip(
					'email.wp-email-address.punycode-domain-decodes',
					'idn_to_ascii() or idn_to_utf8() is unavailable.'
				),
			);
		}

		$input = 'books@xn--bcher-kva.de';
		$call  = self::call( static fn() => \WP_Email_Address::from_string( $input, 'unicode' ) );
		$email = $call['value'] ?? null;
		$ok    = ! $call['threw']
			&& $email instanceof \WP_Email_Address
			&& 'xn--bcher-kva.de' === $email->get_ascii_domain()
			&& "b\u{00FC}cher.de" === $email->get_unicode_domain()
			&& $input === $email->get_ascii_address()
			&& "books@b\u{00FC}cher.de" === $email->get_unicode_address()
			&& self::is_ascii( $email->get_ascii_address() )
			&& ! self::is_ascii( $email->get_unicode_address() );

		return array(
			$ctx->result(
				'email.wp-email-address.punycode-domain-decodes',
				$ok,
				array(
					'input'  => self::describe_string( $input ),
					'parsed' => self::describe_call( $call ),
				)
			),
		);
	}

	private static function check_idn_views( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::has_idn() ) {
			return array(
				$ctx->skip(
					'email.wp-email-address.idn-view-matrix',
					'idn_to_ascii() or idn_to_utf8() is unavailable.'
				),
			);
		}

		$samples = array(
			array( 'label' => 'latin-diaeresis', 'domain' => "b\u{00FC}cher.de" ),
			array( 'label' => 'latin-ring', 'domain' => "gr\u{00E5}.org" ),
			array( 'label' => 'cjk', 'domain' => "\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" ),
			array( 'label' => 'greek', 'domain' => "\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" ),
			array( 'label' => 'cyrillic', 'domain' => "\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}" ),
			array( 'label' => 'hiragana', 'domain' => "\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}" ),
			array( 'label' => 'eszett', 'domain' => "fa\u{00DF}.de" ),
		);
		$failures = array();
		$views    = array();

		foreach ( $samples as $sample ) {
			$encoded_domain = idn_to_ascii( $sample['domain'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$decoded_domain = false === $encoded_domain ? false : idn_to_utf8( $encoded_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $encoded_domain || false === $decoded_domain ) {
				$failures[] = array(
					'label'  => $sample['label'],
					'domain' => self::describe_string( $sample['domain'] ),
					'error'  => 'IDN conversion failed.',
				);
				continue;
			}

			$ascii_input     = 'mail@' . $encoded_domain;
			$unicode_input   = 'mail@' . $sample['domain'];
			$encoded_parse   = self::call( static fn() => \WP_Email_Address::from_string( $ascii_input, 'unicode' ) );
			$raw_parse       = self::call( static fn() => \WP_Email_Address::from_string( $unicode_input, 'unicode' ) );
			$is_encoded      = self::call( static fn() => \is_email( $ascii_input ) );
			$sanitize_encoded = self::call( static fn() => \sanitize_email( $ascii_input ) );
			$encoded_email   = $encoded_parse['value'] ?? null;
			$raw_email       = $raw_parse['value'] ?? null;
			$raw_roundtrip   = $raw_email instanceof \WP_Email_Address
				? self::call( static fn() => \WP_Email_Address::from_string( $raw_email->get_ascii_address(), 'unicode' ) )
				: array( 'threw' => false, 'value' => null );

			$encoded_ok = ! $encoded_parse['threw']
				&& ! $is_encoded['threw']
				&& ! $sanitize_encoded['threw']
				&& $encoded_email instanceof \WP_Email_Address
				&& $encoded_domain === $encoded_email->get_ascii_domain()
				&& $decoded_domain === $encoded_email->get_unicode_domain()
				&& $ascii_input === $encoded_email->get_ascii_address()
				&& 'mail@' . $decoded_domain === $encoded_email->get_unicode_address()
				&& 'mail@' . $decoded_domain === $is_encoded['value']
				&& 'mail@' . $decoded_domain === $sanitize_encoded['value']
				&& self::is_ascii( $encoded_email->get_ascii_address() );

			$raw_ok = ! $raw_parse['threw']
				&& ! $raw_roundtrip['threw']
				&& $raw_email instanceof \WP_Email_Address
				&& $encoded_domain === $raw_email->get_ascii_domain()
				&& $sample['domain'] === $raw_email->get_unicode_domain()
				&& 'mail@' . $encoded_domain === $raw_email->get_ascii_address()
				&& $unicode_input === $raw_email->get_unicode_address()
				&& self::is_ascii( $raw_email->get_ascii_address() )
				&& $raw_roundtrip['value'] instanceof \WP_Email_Address
				&& $unicode_input === $raw_roundtrip['value']->get_unicode_address();

			if ( ! $encoded_ok || ! $raw_ok ) {
				$failures[] = array(
					'label'           => $sample['label'],
					'domain'          => self::describe_string( $sample['domain'] ),
					'encodedDomain'   => self::describe_string( $encoded_domain ),
					'decodedDomain'   => self::describe_string( $decoded_domain ),
					'encodedParse'    => self::describe_call( $encoded_parse ),
					'rawParse'        => self::describe_call( $raw_parse ),
					'isEncoded'       => self::describe_call( $is_encoded ),
					'sanitizeEncoded' => self::describe_call( $sanitize_encoded ),
					'rawRoundtrip'    => self::describe_call( $raw_roundtrip ),
				);
			}

			$views[] = array(
				'label'                   => $sample['label'],
				'domain'                  => self::describe_string( $sample['domain'] ),
				'encodedDomain'           => self::describe_string( $encoded_domain ),
				'decodedDomain'           => self::describe_string( $decoded_domain ),
				'rawAsciiDomainMatchesIdn' => $raw_email instanceof \WP_Email_Address && $encoded_domain === $raw_email->get_ascii_domain(),
				'rawAsciiAddressIsAscii'  => $raw_email instanceof \WP_Email_Address && self::is_ascii( $raw_email->get_ascii_address() ),
			);
		}

		return array(
			$ctx->result(
				'email.wp-email-address.idn-view-matrix',
				array() === $failures,
				array(
					'views'    => $views,
					'failures' => $failures,
				)
			),
		);
	}

	private static function check_extension_address_views( \ComponentFuzz\FuzzContext $ctx ): array {
		$samples  = self::extension_view_cases( $ctx );
		$failures = array();
		$views    = array();

		foreach ( $samples as $sample ) {
			$input  = $sample['local'] . '@' . $sample['domain'];
			$parsed = self::call( static fn() => \WP_Email_Address::from_string( $input, 'unicode' ) );
			$email  = $parsed['value'] ?? null;

			if ( $parsed['threw'] || ! ( $email instanceof \WP_Email_Address ) ) {
				$failures[] = array(
					'label'  => $sample['label'],
					'source' => $sample['source'],
					'input'  => self::describe_string( $input ),
					'parsed' => self::describe_call( $parsed ),
				);
				continue;
			}

			$href_address = $email->get_ascii_address();
			$href         = 'mailto:' . $href_address;
			$text         = $email->get_unicode_address();
			$href_parts   = explode( '@', $href_address, 2 );
			$text_parts   = explode( '@', $text, 2 );
			$href_local   = 2 === count( $href_parts ) ? $href_parts[0] : null;
			$href_domain  = 2 === count( $href_parts ) ? $href_parts[1] : null;
			$text_local   = 2 === count( $text_parts ) ? $text_parts[0] : null;
			$text_domain  = 2 === count( $text_parts ) ? $text_parts[1] : null;
			$href_parse   = self::call( static fn() => \WP_Email_Address::from_string( $href_address, 'unicode' ) );
			$text_parse   = self::call( static fn() => \WP_Email_Address::from_string( $text, 'unicode' ) );

			$has_unicode_local  = ! self::is_ascii( $email->get_localpart() );
			$has_unicode_domain = $email->get_ascii_domain() !== $email->get_unicode_domain();
			$href_roundtrip     = $href_parse['value'] ?? null;
			$text_roundtrip     = $text_parse['value'] ?? null;
			$href_has_mailto    = 0 === strpos( $href, 'mailto:' )
				&& $href_address === substr( $href, strlen( 'mailto:' ) );
			$local_preserved    = $email->get_localpart() === $href_local
				&& $email->get_localpart() === $text_local;
			$domain_views       = $email->get_ascii_domain() === $href_domain
				&& $email->get_unicode_domain() === $text_domain
				&& is_string( $href_domain )
				&& self::is_ascii( $href_domain );
			$unicode_local      = ! $has_unicode_local
				|| (
					is_string( $href_local )
					&& is_string( $text_local )
					&& ! self::is_ascii( $href_local )
					&& ! self::is_ascii( $text_local )
				);
			$unicode_domain     = ! $has_unicode_domain || $href_domain !== $text_domain;
			$roundtrips         = ! $href_parse['threw']
				&& ! $text_parse['threw']
				&& $href_roundtrip instanceof \WP_Email_Address
				&& $text_roundtrip instanceof \WP_Email_Address
				&& $href_roundtrip->get_ascii_address() === $email->get_ascii_address()
				&& $href_roundtrip->get_unicode_address() === $email->get_unicode_address()
				&& $text_roundtrip->get_ascii_address() === $email->get_ascii_address()
				&& $text_roundtrip->get_unicode_address() === $email->get_unicode_address();
			$ok                 = $href_has_mailto
				&& $href_address === $email->get_ascii_address()
				&& $text === $email->get_unicode_address()
				&& $local_preserved
				&& $domain_views
				&& $unicode_local
				&& $unicode_domain
				&& $roundtrips;

			if ( ! $ok ) {
				$failures[] = array(
					'label'         => $sample['label'],
					'source'        => $sample['source'],
					'input'         => self::describe_string( $input ),
					'address'       => self::describe_address( $email ),
					'href'          => self::describe_string( $href ),
					'text'          => self::describe_string( $text ),
					'hrefLocal'     => self::describe_value( $href_local ),
					'hrefDomain'    => self::describe_value( $href_domain ),
					'textLocal'     => self::describe_value( $text_local ),
					'textDomain'    => self::describe_value( $text_domain ),
					'hrefParse'     => self::describe_call( $href_parse ),
					'textParse'     => self::describe_call( $text_parse ),
					'hrefHasMailto' => $href_has_mailto,
					'localPreserved' => $local_preserved,
					'domainViews'   => $domain_views,
					'unicodeLocal'  => $unicode_local,
					'unicodeDomain' => $unicode_domain,
					'roundtrips'    => $roundtrips,
				);
			}

			$views[] = array(
				'label'             => $sample['label'],
				'source'            => $sample['source'],
				'input'             => self::describe_string( $input ),
				'machineAddress'    => self::describe_string( $href_address ),
				'readableAddress'   => self::describe_string( $text ),
				'machineDomainAscii' => is_string( $href_domain ) && self::is_ascii( $href_domain ),
				'unicodeLocal'      => $has_unicode_local,
				'unicodeDomain'     => $has_unicode_domain,
			);
		}

		return array(
			$ctx->result(
				'email.wp-email-address.extension-machine-readable-views',
				array() === $failures,
				array(
					'caseCount' => count( $samples ),
					'views'     => $views,
					'failures'  => $failures,
				)
			),
		);
	}

	private static function check_make_clickable_email_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$samples = array(
			array(
				'label'   => 'ascii-baseline',
				'address' => 'user@example.com',
				'valid'   => true,
			),
			array(
				'label'   => 'plus-tag',
				'address' => 'USER+tag@example.com',
				'valid'   => true,
			),
		);

		if ( self::has_idn() ) {
			$samples[] = array(
				'label'   => 'ascii-local-punycode-domain',
				'address' => 'mail@xn--bcher-kva.de',
				'valid'   => true,
			);
			$samples[] = array(
				'label'   => 'ascii-local-punycode-tld',
				'address' => 'mail@xn--fsqu00a.xn--4rr70v',
				'valid'   => true,
			);
		}

		foreach ( self::extension_view_cases( $ctx->fork( 'make-clickable' ) ) as $sample ) {
			$input  = $sample['local'] . '@' . $sample['domain'];
			$parsed = self::call( static fn() => \WP_Email_Address::from_string( $input, 'unicode' ) );
			$email  = $parsed['value'] ?? null;

			if ( ! $parsed['threw'] && $email instanceof \WP_Email_Address ) {
				$samples[] = array(
					'label'   => $sample['label'] . '-machine',
					'address' => $email->get_ascii_address(),
					'valid'   => true,
				);

				if ( $email->get_unicode_address() !== $email->get_ascii_address() ) {
					$samples[] = array(
						'label'   => $sample['label'] . '-readable',
						'address' => $email->get_unicode_address(),
						'valid'   => true,
					);
				}
			}
		}

		$samples = array_merge(
			$samples,
			array(
				array(
					'label'   => 'unicode-local-valid-address',
					'address' => "jos\u{00E9}@example.com",
					'valid'   => true,
				),
				array(
					'label'   => 'emoji-local-invalid-address',
					'address' => "emoji\u{1F600}@example.com",
					'valid'   => false,
				),
				array(
					'label'   => 'invalid-utf8-local-address',
					'address' => "bad\x80@example.com",
					'valid'   => false,
				),
				array(
					'label'   => 'double-at-address',
					'address' => 'bad@@example.com',
					'valid'   => false,
				),
				array(
					'label'   => 'double-dot-domain-address',
					'address' => 'user@example..com',
					'valid'   => false,
				),
			)
		);

		$failures         = array();
		$observed         = array();
		$known_boundaries = array();

		foreach ( $samples as $case ) {
			$address      = $case['address'];
			$wrapped      = 'Contact ' . $address . ' now';
			$rendered     = self::call( static fn() => \make_clickable( $wrapped ) );
			$parse        = self::call( static fn() => \WP_Email_Address::from_string( $address, 'unicode' ) );
			$email        = $parse['value'] ?? null;
			$anchors      = ! $rendered['threw'] && is_string( $rendered['value'] )
				? self::mailto_anchors( $rendered['value'] )
				: array();
			$expect_link  = true === $case['valid'] && self::make_clickable_email_pattern_matches( $address );
			$actual_link  = 1 === count( $anchors );
			$anchor       = $anchors[0] ?? array();
			$href_address = (string) ( $anchor['href'] ?? '' );
			$text_address = (string) ( $anchor['text'] ?? '' );
			$href_parse   = '' === $href_address
				? array( 'threw' => false, 'value' => null )
				: self::call( static fn() => \WP_Email_Address::from_string( $href_address, 'unicode' ) );
			$text_parse   = '' === $text_address
				? array( 'threw' => false, 'value' => null )
				: self::call( static fn() => \WP_Email_Address::from_string( $text_address, 'unicode' ) );
			$href_email   = $href_parse['value'] ?? null;
			$text_email   = $text_parse['value'] ?? null;

			if (
				! $expect_link
				&& array() !== $anchors
				&& is_string( $rendered['value'] ?? null )
				&& self::make_clickable_known_partial_email_boundary( $address, $anchors, $rendered['value'] )
			) {
				$known_boundaries[] = array(
					'label'    => $case['label'],
					'address'  => self::describe_string( $address ),
					'rendered' => self::describe_call( $rendered ),
					'anchors'  => self::describe_value( $anchors ),
				);
				$observed[]         = array(
					'label'         => $case['label'],
					'address'       => self::describe_string( $address ),
					'valid'         => $case['valid'],
					'expectLink'    => $expect_link,
					'linked'        => $actual_link,
					'knownBoundary' => true,
				);
				continue;
			}

			if ( $expect_link ) {
				$ok = ! $rendered['threw']
					&& $email instanceof \WP_Email_Address
					&& $actual_link
					&& $address === $href_address
					&& $address === $text_address
					&& $href_email instanceof \WP_Email_Address
					&& $text_email instanceof \WP_Email_Address
					&& $href_email->get_unicode_address() === $email->get_unicode_address()
					&& $text_email->get_unicode_address() === $email->get_unicode_address()
					&& str_contains( $rendered['value'], '<a href="mailto:' . $address . '">' . $address . '</a>' );
			} else {
				$ok = ! $rendered['threw']
					&& array() === $anchors
					&& $wrapped === $rendered['value'];
			}

			if ( ! $ok ) {
				$failures[] = array(
					'label'      => $case['label'],
					'address'    => self::describe_string( $address ),
					'valid'      => $case['valid'],
					'expectLink' => $expect_link,
					'rendered'   => self::describe_call( $rendered ),
					'parse'      => self::describe_call( $parse ),
					'anchors'    => self::describe_value( $anchors ),
					'hrefParse'  => self::describe_call( $href_parse ),
					'textParse'  => self::describe_call( $text_parse ),
				);
			}

			$observed[] = array(
				'label'      => $case['label'],
				'address'    => self::describe_string( $address ),
				'valid'      => $case['valid'],
				'expectLink' => $expect_link,
				'linked'     => $actual_link,
			);
		}

		$rows = array(
			$ctx->result(
				'email.make-clickable.mailto-rendering-boundaries',
				array() === $failures,
				array(
					'caseCount' => count( $samples ),
					'observed'  => $observed,
					'failures'  => $failures,
				)
			),
		);

		if ( array() !== $known_boundaries ) {
			$rows[] = $ctx->skip(
				'email.make-clickable.punycode-tld-partial-link-boundary',
				'Core make_clickable() currently uses an ASCII email regex that can partially link punycode TLD labels.',
				array( 'cases' => $known_boundaries )
			);
		}

		return $rows;
	}

	private static function check_length_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$address_254 = str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.' . str_repeat( 'd', 57 ) . '.com';
		$address_255 = str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.' . str_repeat( 'd', 58 ) . '.com';
		$exact       = array(
			array(
				'label' => 'ascii-domain-label-63',
				'input' => 'u@' . str_repeat( 'a', 63 ) . '.com',
				'valid' => true,
			),
			array(
				'label' => 'ascii-domain-label-64',
				'input' => 'u@' . str_repeat( 'a', 64 ) . '.com',
				'valid' => false,
			),
		);
		if ( self::has_idn() ) {
			$exact[] = array(
				'label' => 'unicode-domain-label-63-bytes',
				'input' => 'u@' . str_repeat( "\u{00E5}", 31 ) . 'a.com',
				'valid' => true,
			);
			$exact[] = array(
				'label' => 'unicode-domain-label-64-bytes',
				'input' => 'u@' . str_repeat( "\u{00E5}", 32 ) . '.com',
				'valid' => false,
			);
		}
		$observed    = array(
			array( 'label' => 'local-64-bytes', 'input' => str_repeat( 'a', 64 ) . '@example.com' ),
			array( 'label' => 'local-65-bytes', 'input' => str_repeat( 'a', 65 ) . '@example.com' ),
			array( 'label' => 'whole-address-254-bytes', 'input' => $address_254 ),
			array( 'label' => 'whole-address-255-bytes', 'input' => $address_255 ),
		);
		$failures    = array();
		$lengths     = array();

		foreach ( $exact as $case ) {
			$parsed             = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
			$is_email           = self::call( static fn() => \is_email( $case['input'] ) );
			$sanitized          = self::call( static fn() => \sanitize_email( $case['input'] ) );
			$expected_is_email  = $case['valid'] ? $case['input'] : false;
			$expected_sanitized = $case['valid'] ? $case['input'] : '';
			$expected_parsed    = $case['valid'];

			if (
				$parsed['threw'] ||
				$is_email['threw'] ||
				$sanitized['threw'] ||
				$expected_parsed !== ( $parsed['value'] instanceof \WP_Email_Address ) ||
				$expected_is_email !== $is_email['value'] ||
				$expected_sanitized !== $sanitized['value']
			) {
				$failures[] = array(
					'label'         => $case['label'],
					'input'         => self::describe_string( $case['input'] ),
					'expectedValid' => $case['valid'],
					'parsed'        => self::describe_call( $parsed ),
					'isEmail'       => self::describe_call( $is_email ),
					'sanitizeEmail' => self::describe_call( $sanitized ),
				);
			}

			$lengths[] = array(
				'label' => $case['label'],
				'bytes' => strlen( $case['input'] ),
				'valid' => $case['valid'],
			);
		}

		foreach ( $observed as $case ) {
			$parsed    = self::call( static fn() => \WP_Email_Address::from_string( $case['input'], 'unicode' ) );
			$is_email  = self::call( static fn() => \is_email( $case['input'] ) );
			$sanitized = self::call( static fn() => \sanitize_email( $case['input'] ) );
			$expected  = $parsed['value'] instanceof \WP_Email_Address ? $parsed['value']->get_unicode_address() : false;

			if (
				$parsed['threw'] ||
				$is_email['threw'] ||
				$sanitized['threw'] ||
				$expected !== $is_email['value'] ||
				( false === $expected ? '' : $expected ) !== $sanitized['value']
			) {
				$failures[] = array(
					'label'         => $case['label'],
					'input'         => self::describe_string( $case['input'] ),
					'expectedValue' => self::describe_value( $expected ),
					'parsed'        => self::describe_call( $parsed ),
					'isEmail'       => self::describe_call( $is_email ),
					'sanitizeEmail' => self::describe_call( $sanitized ),
				);
			}

			$lengths[] = array(
				'label' => $case['label'],
				'bytes' => strlen( $case['input'] ),
				'valid' => $parsed['value'] instanceof \WP_Email_Address,
			);
		}

		return array(
			$ctx->result(
				'email.wp-email-address.length-boundaries',
				array() === $failures,
				array(
					'lengths'  => $lengths,
					'failures' => $failures,
				)
			),
		);
	}

	private static function address_parts_ok( \WP_Email_Address $email ): bool {
		$parts = array(
			$email->get_localpart(),
			$email->get_ascii_domain(),
			$email->get_unicode_domain(),
			$email->get_ascii_address(),
			$email->get_unicode_address(),
		);

		foreach ( $parts as $part ) {
			if ( ! \wp_is_valid_utf8( $part ) ) {
				return false;
			}
		}

		if (
			'' === $email->get_localpart() ||
			'' === $email->get_ascii_domain() ||
			'' === $email->get_unicode_domain() ||
			str_contains( $email->get_localpart(), '@' ) ||
			str_contains( $email->get_ascii_domain(), '@' ) ||
			str_contains( $email->get_unicode_domain(), '@' )
		) {
			return false;
		}

		if ( ! self::is_ascii( $email->get_ascii_domain() ) ) {
			return false;
		}

		foreach ( explode( '.', $email->get_ascii_domain() ) as $label ) {
			if ( '' === $label || strlen( $label ) > 63 ) {
				return false;
			}
		}

		foreach ( explode( '.', $email->get_unicode_domain() ) as $label ) {
			if ( '' === $label ) {
				return false;
			}
		}

		return str_contains( $email->get_ascii_address(), '@' )
			&& str_contains( $email->get_unicode_address(), '@' );
	}

	private static function address_raw_views( \WP_Email_Address $email ): array {
		return array(
			'localpart'      => $email->get_localpart(),
			'asciiDomain'    => $email->get_ascii_domain(),
			'unicodeDomain'  => $email->get_unicode_domain(),
			'asciiAddress'   => $email->get_ascii_address(),
			'unicodeAddress' => $email->get_unicode_address(),
		);
	}

	private static function mailto_anchors( string $html ): array {
		$count = preg_match_all( '#<a href="mailto:([^"]+)">([^<]+)</a>#', $html, $matches, PREG_SET_ORDER );
		if ( false === $count || 0 === $count ) {
			return array();
		}

		$anchors = array();
		foreach ( $matches as $match ) {
			$anchors[] = array(
				'href' => $match[1],
				'text' => $match[2],
			);
		}

		return $anchors;
	}

	private static function make_clickable_email_pattern_matches( string $address ): bool {
		return 1 === preg_match( '/\A[.0-9a-z_+-]+@(?:[0-9a-z-]+\.)+[0-9a-z]{2,}\z/i', $address );
	}

	private static function make_clickable_known_partial_email_boundary( string $address, array $anchors, string $rendered ): bool {
		if ( ! str_contains( $address, '.xn--' ) ) {
			return false;
		}

		if ( 1 !== count( $anchors ) ) {
			return false;
		}

		$anchor           = $anchors[0];
		$href             = $anchor['href'] ?? null;
		$text             = $anchor['text'] ?? null;
		$expected_partial = self::make_clickable_partial_email_target( $address );
		if ( null === $expected_partial || ! is_string( $href ) || ! is_string( $text ) ) {
			return false;
		}

		$suffix   = substr( $address, strlen( $expected_partial ) );
		$expected = 'Contact <a href="mailto:' . $expected_partial . '">' . $expected_partial . '</a>' . $suffix . ' now';

		return $href === $expected_partial
			&& $text === $expected_partial
			&& $rendered === $expected;
	}

	private static function make_clickable_partial_email_target( string $address ): ?string {
		if ( 1 !== preg_match( '/\A([.0-9a-z_+-]+@(?:[0-9a-z-]+\.)+xn)(--[0-9a-z-]+)\z/i', $address, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	private static function address_round_trip_ok( \WP_Email_Address $email ): bool {
		if ( $email->get_localpart() . '@' . $email->get_ascii_domain() !== $email->get_ascii_address() ) {
			return false;
		}
		if ( $email->get_localpart() . '@' . $email->get_unicode_domain() !== $email->get_unicode_address() ) {
			return false;
		}

		$ascii_roundtrip = \WP_Email_Address::from_string( $email->get_ascii_address(), 'unicode' );
		if ( ! $ascii_roundtrip instanceof \WP_Email_Address ) {
			return false;
		}
		if ( $ascii_roundtrip->get_unicode_address() !== $email->get_unicode_address() ) {
			return false;
		}

		$unicode_roundtrip = \WP_Email_Address::from_string( $email->get_unicode_address(), 'unicode' );
		return $unicode_roundtrip instanceof \WP_Email_Address
			&& $unicode_roundtrip->get_unicode_address() === $email->get_unicode_address();
	}

	private static function hostile_user_email_lookup_probe( string $lookup, string $candidate ): array {
		global $wpdb;

		self::delete_user_email_cache( $candidate );
		$candidate_user = \get_user_by( 'email', $candidate );
		if ( ! ( $candidate_user instanceof \WP_User ) || ! is_object( $candidate_user->data ) ) {
			return array(
				'ok'     => false,
				'reason' => 'candidate-user-unavailable',
			);
		}

		$original_wpdb = $wpdb;
		$candidate_row = clone $candidate_user->data;
		$proxy         = new class( $original_wpdb, $candidate_row ) {
			/** @var object */
			private $delegate;

			/** @var object */
			private $candidate;

			/** @var int */
			public $hits = 0;

			/** @var string */
			public $last_query = '';

			public function __construct( $delegate, object $candidate ) {
				$this->delegate  = $delegate;
				$this->candidate = $candidate;
			}

			public function get_results( $query = null, $output = OBJECT ) {
				$sql              = null === $query ? (string) ( $this->delegate->last_query ?? '' ) : (string) $query;
				$this->last_query = $sql;

				if ( preg_match( '/WHERE\s+user_email\s*=\s*/i', $sql ) ) {
					++$this->hits;
					return array( clone $this->candidate );
				}

				return $this->delegate->get_results( $query, $output );
			}

			public function __call( string $method, array $args ) {
				return $this->delegate->$method( ...$args );
			}

			public function __get( string $name ) {
				return $this->delegate->$name;
			}

			public function __set( string $name, $value ): void {
				$this->delegate->$name = $value;
			}

			public function __isset( string $name ): bool {
				return isset( $this->delegate->$name );
			}
		};

		self::delete_user_email_cache( $lookup );
		$wpdb            = $proxy;
		$GLOBALS['wpdb'] = $proxy;
		try {
			$exists = self::capture_warnings( static fn() => \email_exists( $lookup ) );
			self::delete_user_email_cache( $lookup );
			$by_email = self::capture_warnings( static fn() => \get_user_by( 'email', $lookup ) );
		} finally {
			$wpdb            = $original_wpdb;
			$GLOBALS['wpdb'] = $original_wpdb;
			self::delete_user_email_cache( $lookup );
		}

		return array(
			'ok'        => 0 !== strcasecmp( $lookup, $candidate )
				&& ! $exists['threw']
				&& ! $by_email['threw']
				&& array() === $exists['warnings']
				&& array() === $by_email['warnings']
				&& false === $exists['value']
				&& false === $by_email['value']
				&& $proxy->hits >= 2,
			'lookup'    => self::describe_string( $lookup ),
			'candidate' => self::describe_string( $candidate ),
			'hits'      => $proxy->hits,
			'query'     => self::describe_string( $proxy->last_query ),
			'exists'    => self::describe_captured_call( $exists ),
			'byEmail'   => self::describe_captured_call( $by_email ),
		);
	}

	private static function seed_stub_user_email_row( string $email, string $login ): array {
		global $wpdb;

		self::delete_user_email_cache( $email );

		return self::capture_warnings(
			static function () use ( $wpdb, $email, $login ) {
				$result = $wpdb->insert(
					$wpdb->users,
					array(
						'user_login'      => $login,
						'user_pass'       => 'component-fuzz-seeded-password',
						'user_nicename'   => $login,
						'user_email'      => $email,
						'user_url'        => '',
						'user_registered' => '2026-06-25 00:00:00',
						'display_name'    => $login,
					)
				);

				if ( false === $result ) {
					return false;
				}

				return (int) $wpdb->insert_id;
			}
		);
	}

	private static function probe_user_email_localpart_search_results( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$unicode_local = self::email_localpart( $case['unicodeAddress'] );
		$folded_local  = self::email_localpart( $case['foldedAddress'] );
		$base_id        = 800000 + ( 2 * ( (int) sprintf( '%u', crc32( $case['label'] . '|' . $ctx->iteration() ) ) % 50000 ) );
		$records        = array(
			array(
				'id'    => $base_id,
				'email' => $case['unicodeAddress'],
			),
			array(
				'id'    => $base_id + 1,
				'email' => $case['foldedAddress'],
			),
		);

		$seen   = array();
		$filter = static function ( $results, \WP_User_Query $query ) use ( &$seen, $records ) {
			$query_vars = $query->query_vars;
			$columns    = array_values( (array) ( $query_vars['search_columns'] ?? array() ) );
			if ( array( 'user_email' ) !== $columns || 'ID' !== ( $query_vars['fields'] ?? null ) ) {
				return $results;
			}

			$search = trim( (string) ( $query_vars['search'] ?? '' ), '*' );
			$ids    = array();
			foreach ( $records as $record ) {
				if ( str_contains( $record['email'], $search ) ) {
					$ids[] = $record['id'];
				}
			}

			$seen[] = array(
				'search' => $search,
				'ids'    => $ids,
			);

			return $ids;
		};

		\add_filter( 'users_pre_query', $filter, 10, 2 );
		try {
			$unicode_query = self::run_user_email_localpart_search_query( '*' . $unicode_local . '*' );
			$folded_query  = self::run_user_email_localpart_search_query( '*' . $folded_local . '*' );
		} finally {
			\remove_filter( 'users_pre_query', $filter, 10 );
		}

		$expected_unicode = self::expected_byte_preserving_email_search_ids( $records, $unicode_local );
		$expected_folded  = self::expected_byte_preserving_email_search_ids( $records, $folded_local );
		$actual_unicode   = self::captured_query_result_ids( $unicode_query );
		$actual_folded    = self::captured_query_result_ids( $folded_query );
		$filter_restored  = false === \has_filter( 'users_pre_query', $filter );

		return array(
			'ok'              => 2 === count( $records )
				&& ! $unicode_query['threw']
				&& ! $folded_query['threw']
				&& array() === $unicode_query['warnings']
				&& array() === $folded_query['warnings']
				&& $actual_unicode === $expected_unicode
				&& $actual_folded === $expected_folded
				&& 2 === count( $seen )
				&& $filter_restored,
			'unicode'         => array(
				'search'   => self::describe_string( $unicode_local ),
				'expected' => $expected_unicode,
				'actual'   => $actual_unicode,
			),
			'folded'          => array(
				'search'   => self::describe_string( $folded_local ),
				'expected' => $expected_folded,
				'actual'   => $actual_folded,
			),
			'seen'            => $seen,
			'records'         => $records,
			'unicodeQuery'    => self::describe_captured_call( $unicode_query ),
			'foldedQuery'     => self::describe_captured_call( $folded_query ),
			'filterRestored'  => $filter_restored,
		);
	}

	private static function prepare_user_email_search_query( string $search, array $search_columns = array() ): array {
		$query = new \WP_User_Query();

		return self::capture_warnings(
			static function () use ( $query, $search, $search_columns ): array {
				$query->prepare_query(
					array(
						'blog_id'       => 0,
						'cache_results' => false,
						'count_total'   => false,
						'fields'        => 'ID',
						'number'        => 1,
						'orderby'       => 'ID',
						'search'        => $search,
						'search_columns' => $search_columns,
					)
				);

				return array(
					'queryWhere' => (string) $query->query_where,
					'queryVars'  => $query->query_vars,
				);
			}
		);
	}

	private static function run_user_email_localpart_search_query( string $search ): array {
		return self::capture_warnings(
			static function () use ( $search ): array {
				$query = new \WP_User_Query(
					array(
						'blog_id'        => 0,
						'cache_results'  => false,
						'count_total'    => false,
						'fields'         => 'ID',
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'search'         => $search,
						'search_columns' => array( 'user_email' ),
					)
				);

				return array(
					'results'    => array_map( 'intval', (array) $query->get_results() ),
					'queryWhere' => (string) $query->query_where,
					'queryVars'  => $query->query_vars,
				);
			}
		);
	}

	private static function expected_byte_preserving_email_search_ids( array $records, string $needle ): array {
		$ids = array();
		foreach ( $records as $record ) {
			if ( str_contains( $record['email'], $needle ) ) {
				$ids[] = (int) $record['id'];
			}
		}

		return $ids;
	}

	private static function captured_query_result_ids( array $query ): array {
		if ( ! is_array( $query['value'] ?? null ) || ! is_array( $query['value']['results'] ?? null ) ) {
			return array();
		}

		return array_map( 'intval', $query['value']['results'] );
	}

	private static function email_localpart( string $address ): string {
		$at = strpos( $address, '@' );
		if ( false === $at ) {
			return $address;
		}

		return substr( $address, 0, $at );
	}

	private static function delete_user_email_cache( string $email ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			\wp_cache_delete( $email, 'useremail' );
		}
	}

	private static function sql_like_literals_for_columns( string $sql, array $columns ): array {
		$out = array();

		foreach ( $columns as $column ) {
			$literals = self::sql_like_literals( $sql, $column );
			if ( array() !== $literals ) {
				$out[ $column ] = $literals;
			}
		}

		return $out;
	}

	private static function flatten_sql_like_literal_groups( array $groups ): array {
		$out = array();
		foreach ( $groups as $literals ) {
			foreach ( (array) $literals as $literal ) {
				$out[] = $literal;
			}
		}

		return $out;
	}

	private static function sql_like_literals( string $sql, string $column ): array {
		$column = preg_quote( $column, '/' );
		if ( ! preg_match_all( '/(?<![A-Za-z0-9_])`?' . $column . '`?\s+LIKE\s+\'((?:\\\\.|[^\'\\\\])*)\'/i', $sql, $matches ) ) {
			return array();
		}

		return array_map( array( self::class, 'unescape_sql_literal' ), $matches[1] );
	}

	private static function unescape_sql_literal( string $literal ): string {
		$out    = '';
		$length = strlen( $literal );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( '\\' !== $literal[ $i ] || $i + 1 >= $length ) {
				$out .= $literal[ $i ];
				continue;
			}

			$next = $literal[ ++$i ];
			if ( '0' === $next ) {
				$out .= "\0";
			} elseif ( in_array( $next, array( '\\', "'", '"' ), true ) ) {
				$out .= $next;
			} else {
				$out .= '\\' . $next;
			}
		}

		return $out;
	}

	private static function can_reset_stub_content(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& is_object( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' );
	}

	private static function reset_stub_content(): void {
		if ( self::can_reset_stub_content() ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		\wp_cache_flush();
	}

	private static function install_email_filters( string $mode ): void {
		\remove_all_filters( 'is_email' );
		\remove_all_filters( 'sanitize_email' );

		if ( 'ascii' === $mode ) {
			\add_filter( 'is_email', 'wp_is_ascii_email', 10, 3 );
			\add_filter( 'sanitize_email', 'wp_sanitize_ascii_email', 10, 3 );
			return;
		}

		\add_filter( 'is_email', 'wp_is_unicode_email', 10, 3 );
		\add_filter( 'sanitize_email', 'wp_sanitize_unicode_email', 10, 3 );
	}

	private static function install_email_filters_from_default_filters( string $charset ): void {
		$default_filters = \ComponentFuzz\repo_root() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR . 'default-filters.php';

		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb'] = new \stdClass();
		}

		$GLOBALS['wpdb']->charset = $charset;
		$wpdb                    = $GLOBALS['wpdb'];
		require $default_filters;
	}

	private static function cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$has_idn                  = self::has_idn();
		$unicode_domain_label_63  = 'u@' . str_repeat( "\u{00E5}", 31 ) . 'a.com';
		$unicode_domain_label_64  = 'u@' . str_repeat( "\u{00E5}", 32 ) . '.com';
		$unicode_domain_63_traits = array( 'unicodeAddress', 'boundaryLength' );
		if ( $has_idn ) {
			$unicode_domain_63_traits[] = 'valid';
		}

		$cases = array(
			self::case( 'ascii-simple', 'user@example.com', array( 'ascii', 'valid' ), 'user@example.com', 'user@example.com', 'user@example.com', 'user@example.com' ),
			self::case( 'ascii-plus-subdomain', 'USER+tag@example.co.uk', array( 'ascii', 'valid' ), 'USER+tag@example.co.uk', 'USER+tag@example.co.uk', 'USER+tag@example.co.uk', 'USER+tag@example.co.uk' ),
			self::case( 'ascii-whatwg-atext-local', 'azAZ09.!#$%&\'*+/=?^_`{|}~-@example.com', array( 'ascii', 'valid', 'whatwgAtext' ), 'azAZ09.!#$%&\'*+/=?^_`{|}~-@example.com', 'azAZ09.!#$%&\'*+/=?^_`{|}~-@example.com', 'azAZ09.!#$%&\'*+/=?^_`{|}~-@example.com', 'azAZ09.!#$%&\'*+/=?^_`{|}~-@example.com' ),
			self::case( 'unicode-local-domain', "gr\u{00E5}@gr\u{00E5}.org", array( 'valid', 'unicodeAddress' ), $has_idn ? "gr\u{00E5}@gr\u{00E5}.org" : false, $has_idn ? "gr\u{00E5}@gr\u{00E5}.org" : '', false, '' ),
			self::case( 'unicode-local-ascii-domain', "jos\u{00E9}@example.com", array( 'valid', 'unicodeAddress' ), "jos\u{00E9}@example.com", "jos\u{00E9}@example.com", false, '' ),
			self::case( 'unicode-combining-local', "jose\u{0301}@example.com", array( 'valid', 'unicodeAddress' ), "jose\u{0301}@example.com", "jose\u{0301}@example.com", false, '' ),
			self::case( 'unicode-domain', "checkout@b\u{00FC}cher.tld", array( 'valid', 'unicodeAddress' ), $has_idn ? "checkout@b\u{00FC}cher.tld" : false, $has_idn ? "checkout@b\u{00FC}cher.tld" : '', false, '' ),
			self::case( 'unicode-cjk-address', "\u{7528}\u{6237}@\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}", array( 'valid', 'unicodeAddress' ), $has_idn ? "\u{7528}\u{6237}@\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" : false, $has_idn ? "\u{7528}\u{6237}@\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" : '', false, '' ),
			self::case( 'unicode-arabic-address', "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}@\u{0645}\u{062B}\u{0627}\u{0644}.\u{0625}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}", array( 'valid', 'unicodeAddress' ), $has_idn ? "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}@\u{0645}\u{062B}\u{0627}\u{0644}.\u{0625}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}" : false, $has_idn ? "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}@\u{0645}\u{062B}\u{0627}\u{0644}.\u{0625}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}" : '', false, '' ),
			self::case( 'unicode-greek-address', "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}@\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}", array( 'valid', 'unicodeAddress' ), $has_idn ? "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}@\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" : false, $has_idn ? "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}@\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" : '', false, '' ),
			self::case( 'unicode-devanagari-local', "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}@example.com", array( 'valid', 'unicodeAddress' ), "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}@example.com", "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}@example.com", false, '' ),
			self::case( 'unicode-cyrillic-local', "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@example.com", array( 'valid', 'unicodeAddress' ), "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@example.com", "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@example.com", false, '' ),
			self::case( 'unicode-hiragana-local', "\u{3086}\u{3046}\u{3056}\u{3042}@example.com", array( 'valid', 'unicodeAddress' ), "\u{3086}\u{3046}\u{3056}\u{3042}@example.com", "\u{3086}\u{3046}\u{3056}\u{3042}@example.com", false, '' ),
			self::case( 'unicode-cyrillic-address', "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}", array( 'valid', 'unicodeAddress' ), $has_idn ? "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}" : false, $has_idn ? "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}@\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}" : '', false, '' ),
			self::case( 'unicode-hiragana-address', "\u{3086}\u{3046}\u{3056}\u{3042}@\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}", array( 'valid', 'unicodeAddress' ), $has_idn ? "\u{3086}\u{3046}\u{3056}\u{3042}@\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}" : false, $has_idn ? "\u{3086}\u{3046}\u{3056}\u{3042}@\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}" : '', false, '' ),
			self::case( 'unicode-eszett-domain', "mail@fa\u{00DF}.de", array( 'valid', 'unicodeAddress' ), $has_idn ? "mail@fa\u{00DF}.de" : false, $has_idn ? "mail@fa\u{00DF}.de" : '', false, '' ),
			self::case( 'punycode-domain', 'books@xn--bcher-kva.de', array( 'valid', 'punycodeUnicodeDomain' ), $has_idn ? "books@b\u{00FC}cher.de" : false, $has_idn ? "books@b\u{00FC}cher.de" : '', false, '' ),
			self::case( 'punycode-cjk-domain', 'mail@xn--fsqu00a.xn--4rr70v', array( 'valid', 'punycodeUnicodeDomain' ), $has_idn ? "mail@\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" : false, $has_idn ? "mail@\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" : '', false, '' ),
			self::case( 'punycode-greek-domain', 'mail@xn--hxajbheg2az3al.xn--jxalpdlp', array( 'valid', 'punycodeUnicodeDomain' ), $has_idn ? "mail@\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" : false, $has_idn ? "mail@\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" : '', false, '' ),
			self::case( 'mixed-case-punycode-prefix', 'books@XN--BCHER-KVA.DE', array( 'reservedAcePrefix' ), false, '', false, '' ),
			self::case( 'display-name-wrapper', 'Display Name <user@example.com>', array( 'displayName' ), false, 'user@example.com', false, 'user@example.com' ),
			self::case( 'display-name-unicode-wrapper', "Display Name <jos\u{00E9} @ gr\u{00E5} . org.>", array( 'displayName', 'recoverableWhitespace' ), false, "jos\u{00E9}@gr\u{00E5}.org", false, '' ),
			self::case( 'display-name-quoted-local-rejected', 'Display <"quoted"@example.com>', array( 'displayName', 'quotedLookingLocal' ), false, '', false, '' ),
			self::case( 'separator-whitespace-and-trailing-dot', " info @ example . com. \t", array( 'recoverableWhitespace' ), false, 'info@example.com', false, 'info@example.com' ),
			self::case( 'nbsp-separator-whitespace', "user\u{00A0}@\u{00A0}example.com", array( 'recoverableWhitespace' ), false, 'user@example.com', false, 'user@example.com' ),
			self::case( 'soft-hyphen-near-dot', "info@example\u{00AD}.com", array( 'recoverableWhitespace' ), false, 'info@example.com', false, 'info@example.com' ),
			self::case( 'multiple-at', 'bad@@example.com', array( 'malformedAt' ), false, '', false, '' ),
			self::case( 'fullwidth-at-separator', "bad\u{FF20}example.com", array( 'unicodeAddress', 'malformedAt' ), false, '', false, '' ),
			self::case( 'missing-at', 'not-an-address.example.com', array( 'malformedAt' ), false, '', false, '' ),
			self::case( 'empty-local', '@example.com', array( 'malformedAt' ), false, '', false, '' ),
			self::case( 'empty-domain', 'user@', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'no-domain-period', 'a@b', array( 'ascii', 'valid', 'whatwgSingleLabelDomain' ), 'a@b', 'a@b', 'a@b', 'a@b' ),
			self::case( 'empty-domain-label', 'name@domain..com', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'leading-domain-dot', 'name@.example.com', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'leading-domain-hyphen', 'name@-example.com', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'trailing-domain-hyphen', 'name@example-.com', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'domain-underscore', 'name@example_corp.com', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'domain-literal', 'name@[127.0.0.1]', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'ideographic-dot-separator', "name@example\u{3002}com", array( 'unicodeAddress', 'malformedDomain' ), false, '', false, '' ),
			self::case( 'quoted-looking-local', '"quoted"@example.com', array( 'quotedLookingLocal' ), false, '', false, '' ),
			self::case( 'quoted-html-looking-local', '"<iframe src=...>"@example.com', array( 'quotedLookingLocal' ), false, '', false, '' ),
			self::case( 'comment-looking-local', 'user(comment)@example.com', array( 'commentLookingLocal' ), false, '', false, '' ),
			self::case( 'local-space', 'first last@example.com', array( 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'leading-combining-local', "\u{0301}bad@example.com", array( 'unicodeAddress', 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'leading-combining-domain', "bad@\u{0301}example.com", array( 'unicodeAddress', 'malformedDomain' ), false, '', false, '' ),
			self::case( 'emoji-local', "emoji\u{1F600}@example.com", array( 'unicodeAddress', 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'zero-width-local', "zero\u{200D}width@example.com", array( 'unicodeAddress', 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'control-byte-local', "control\x01@example.com", array( 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'invalid-utf8-local', "invalid\x80@example.com", array( 'invalidUtf8', 'unicodeAddress' ), false, '', false, '' ),
			self::case( 'invalid-utf8-domain', "user@example.\xC3\x28", array( 'invalidUtf8', 'unicodeAddress' ), false, '', false, '' ),
			self::case( 'overlong-utf8-local', "overlong\xC0\xAF@example.com", array( 'invalidUtf8', 'unicodeAddress' ), false, '', false, '' ),
			self::case( 'truncated-utf8-domain', "user@example.\xE2\x82", array( 'invalidUtf8', 'unicodeAddress' ), false, '', false, '' ),
			self::case( 'surrogate-utf8-local', "surrogate\xED\xA0\x80@example.com", array( 'invalidUtf8', 'unicodeAddress' ), false, '', false, '' ),
			self::case( 'ascii-domain-label-63', 'u@' . str_repeat( 'a', 63 ) . '.com', array( 'ascii', 'valid', 'boundaryLength' ), 'u@' . str_repeat( 'a', 63 ) . '.com', 'u@' . str_repeat( 'a', 63 ) . '.com', 'u@' . str_repeat( 'a', 63 ) . '.com', 'u@' . str_repeat( 'a', 63 ) . '.com' ),
			self::case( 'ascii-domain-label-64', 'u@' . str_repeat( 'a', 64 ) . '.com', array( 'malformedDomain', 'boundaryLength' ), false, '', false, '' ),
			self::case( 'unicode-domain-label-63-bytes', $unicode_domain_label_63, $unicode_domain_63_traits, $has_idn ? $unicode_domain_label_63 : false, $has_idn ? $unicode_domain_label_63 : '', false, '' ),
			self::case( 'unicode-domain-label-64-bytes', $unicode_domain_label_64, array( 'unicodeAddress', 'malformedDomain', 'boundaryLength' ), false, '', false, '' ),
			self::case( 'local-64-bytes', str_repeat( 'a', 64 ) . '@example.com', array( 'ascii', 'valid', 'boundaryLength' ), str_repeat( 'a', 64 ) . '@example.com', str_repeat( 'a', 64 ) . '@example.com', str_repeat( 'a', 64 ) . '@example.com', str_repeat( 'a', 64 ) . '@example.com' ),
			self::case( 'local-65-bytes', str_repeat( 'a', 65 ) . '@example.com', array( 'ascii', 'valid', 'boundaryLength' ), str_repeat( 'a', 65 ) . '@example.com', str_repeat( 'a', 65 ) . '@example.com', str_repeat( 'a', 65 ) . '@example.com', str_repeat( 'a', 65 ) . '@example.com' ),
			self::case( 'leading-local-dot', '.start@example.com', array( 'ascii', 'valid', 'localDot' ), '.start@example.com', '.start@example.com', '.start@example.com', '.start@example.com' ),
			self::case( 'trailing-local-dot', 'end.@example.com', array( 'ascii', 'valid', 'localDot' ), 'end.@example.com', 'end.@example.com', 'end.@example.com', 'end.@example.com' ),
		);

		foreach ( self::generated_cases( $ctx ) as $case ) {
			$cases[] = $case;
		}

		return $cases;
	}

	private static function case( string $label, string $input, array $traits, $expect_raw_unicode = null, $expect_sanitized_unicode = null, $expect_raw_ascii = null, $expect_sanitized_ascii = null ): array {
		$case = array(
			'label'  => $label,
			'input'  => $input,
			'source' => 'corpus',
			'traits' => $traits,
		);

		if ( null !== $expect_raw_unicode ) {
			$case['expectRawUnicode'] = $expect_raw_unicode;
		}
		if ( null !== $expect_sanitized_unicode ) {
			$case['expectSanitizedUnicode'] = $expect_sanitized_unicode;
		}
		if ( null !== $expect_raw_ascii ) {
			$case['expectRawAscii'] = $expect_raw_ascii;
		}
		if ( null !== $expect_sanitized_ascii ) {
			$case['expectSanitizedAscii'] = $expect_sanitized_ascii;
		}

		return $case;
	}

	private static function extension_view_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$locals = array(
			'mail',
			'USER+tag',
			"gr\u{00E5}",
			"jose\u{0301}",
			"\u{7528}\u{6237}",
			"\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}",
			"\u{3086}\u{3046}\u{3056}\u{3042}",
		);
		$domains = array(
			'example.com',
			'sub-domain.example',
		);
		$samples = array(
			array(
				'label'  => 'unicode-local-ascii-domain',
				'source' => 'anchor',
				'local'  => "jos\u{00E9}",
				'domain' => 'example.com',
			),
		);

		if ( self::has_idn() ) {
			$domains[] = "b\u{00FC}cher.de";
			$domains[] = "\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}";
			$domains[] = "\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}";
			$domains[] = "\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}";
			$domains[] = 'xn--bcher-kva.de';
			$samples[] = array(
				'label'  => 'ascii-local-unicode-domain',
				'source' => 'anchor',
				'local'  => 'mail',
				'domain' => "b\u{00FC}cher.de",
			);
			$samples[] = array(
				'label'  => 'unicode-local-unicode-domain',
				'source' => 'anchor',
				'local'  => "\u{7528}\u{6237}",
				'domain' => "\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}",
			);
			$samples[] = array(
				'label'  => 'cyrillic-local-domain',
				'source' => 'anchor',
				'local'  => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}",
				'domain' => "\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}",
			);
			$samples[] = array(
				'label'  => 'hiragana-local-domain',
				'source' => 'anchor',
				'local'  => "\u{3086}\u{3046}\u{3056}\u{3042}",
				'domain' => "\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}",
			);
			$samples[] = array(
				'label'  => 'combining-local-punycode-domain',
				'source' => 'anchor',
				'local'  => "jose\u{0301}",
				'domain' => 'xn--bcher-kva.de',
			);
		}

		$view_ctx = $ctx->fork( 'email-extension-address-views' );
		for ( $i = 0; $i < self::GENERATED_VIEW_CASES; $i++ ) {
			$samples[] = array(
				'label'  => 'generated-view-' . $i,
				'source' => 'generated',
				'local'  => $view_ctx->choice( $locals ),
				'domain' => $view_ctx->choice( $domains ),
			);
		}

		return $samples;
	}

	private static function generated_localpart_alias_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$profiles = array(
			array(
				'label'  => 'latin-acute',
				'locals' => array( 'jose', "jos\u{00E9}", "jose\u{0301}" ),
			),
			array(
				'label'  => 'latin-ring',
				'locals' => array( 'angstrom', "\u{00E5}ngstrom", "a\u{030A}ngstrom" ),
			),
			array(
				'label'  => 'greek-tonos',
				'locals' => array( "\u{03B1}\u{03BB}\u{03C6}\u{03B1}", "\u{03AC}\u{03BB}\u{03C6}\u{03B1}", "\u{03B1}\u{0301}\u{03BB}\u{03C6}\u{03B1}" ),
			),
			array(
				'label'  => 'cyrillic-io',
				'locals' => array( "\u{0435}mail", "\u{0451}mail", "\u{0435}\u{0308}mail" ),
			),
		);
		$domains  = array(
			'example.org',
			'sub-domain.example',
		);

		if ( self::has_idn() ) {
			$domains[] = "gr\u{00E5}.org";
			$domains[] = "b\u{00FC}cher.de";
		}

		$cases = array(
			array(
				'label'     => 'anchor-report-accented-local-domain',
				'profile'   => 'latin-acute-report',
				'domain'    => self::has_idn() ? "gr\u{00E5}.org" : 'example.org',
				'addresses' => self::addresses_for_localparts(
					array(
						'josejose',
						"jos\u{00E9}jos\u{00E9}",
						"jose\u{0301}jose\u{0301}",
					),
					self::has_idn() ? "gr\u{00E5}.org" : 'example.org'
				),
			),
			array(
				'label'     => 'anchor-normalization-sensitive-local',
				'profile'   => 'latin-acute',
				'domain'    => 'example.org',
				'addresses' => self::addresses_for_localparts(
					array( 'jose', "jos\u{00E9}", "jose\u{0301}" ),
					'example.org'
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_LOCALPART_ALIAS_CASES; $i++ ) {
			$profile = $ctx->choice( $profiles );
			$domain  = $ctx->choice( $domains );
			$suffix  = (string) $ctx->int( 100, 999 );
			$locals  = array();

			foreach ( $profile['locals'] as $local ) {
				$locals[] = $local . $suffix;
			}

			$cases[] = array(
				'label'     => 'generated-localpart-alias-' . $i . '-' . $profile['label'],
				'profile'   => $profile['label'],
				'domain'    => $domain,
				'addresses' => self::addresses_for_localparts( $locals, $domain ),
			);
		}

		return $cases;
	}

	private static function generated_user_search_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$profiles = array(
			array(
				'label'   => 'latin-acute',
				'unicode' => "jos\u{00E9}",
				'folded'  => 'jose',
			),
			array(
				'label'   => 'latin-combining',
				'unicode' => "jose\u{0301}",
				'folded'  => 'jose',
			),
			array(
				'label'   => 'latin-ring',
				'unicode' => "\u{00E5}ngstrom",
				'folded'  => 'angstrom',
			),
			array(
				'label'   => 'latin-ring-combining',
				'unicode' => "a\u{030A}ngstrom",
				'folded'  => 'angstrom',
			),
			array(
				'label'   => 'greek-tonos',
				'unicode' => "\u{03AC}\u{03BB}\u{03C6}\u{03B1}",
				'folded'  => "\u{03B1}\u{03BB}\u{03C6}\u{03B1}",
			),
			array(
				'label'   => 'cyrillic-io',
				'unicode' => "\u{0451}mail",
				'folded'  => "\u{0435}mail",
			),
		);
		$domains  = array(
			'example.org',
			'sub-domain.example',
		);

		if ( self::has_idn() ) {
			$domains[] = "gr\u{00E5}.org";
			$domains[] = "b\u{00FC}cher.de";
		}

		$anchor_domain = self::has_idn() ? "gr\u{00E5}.org" : 'example.org';
		$cases         = array(
			array(
				'label'          => 'anchor-report-accented-local-search',
				'profile'        => 'latin-acute-report',
				'unicodeAddress' => "jos\u{00E9}jos\u{00E9}@" . $anchor_domain,
				'foldedAddress'  => 'josejose@' . $anchor_domain,
			),
			array(
				'label'          => 'anchor-normalized-local-search',
				'profile'        => 'latin-combining',
				'unicodeAddress' => "jose\u{0301}@example.org",
				'foldedAddress'  => 'jose@example.org',
			),
		);

		for ( $i = 0; $i < self::GENERATED_USER_SEARCH_CASES; $i++ ) {
			$profile = $ctx->choice( $profiles );
			$domain  = $ctx->choice( $domains );
			$suffix  = (string) $ctx->int( 100, 999 );

			$cases[] = array(
				'label'          => 'generated-user-search-' . $i . '-' . $profile['label'],
				'profile'        => $profile['label'],
				'unicodeAddress' => $profile['unicode'] . $suffix . '@' . $domain,
				'foldedAddress'  => $profile['folded'] . $suffix . '@' . $domain,
			);
		}

		return $cases;
	}

	private static function addresses_for_localparts( array $locals, string $domain ): array {
		$addresses = array();

		foreach ( $locals as $local ) {
			$addresses[] = $local . '@' . $domain;
		}

		return $addresses;
	}

	private static function generated_unicode_filter_view_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$locals = array(
			array( 'label' => 'ascii', 'value' => 'user' ),
			array( 'label' => 'ascii-plus', 'value' => 'USER+tag' ),
			array( 'label' => 'whatwg-atext', 'value' => 'azAZ09.!#$%&\'*+/=?^_`{|}~-' ),
			array( 'label' => 'latin-composed', 'value' => "gr\u{00E5}" ),
			array( 'label' => 'latin-combining', 'value' => "jose\u{0301}" ),
			array( 'label' => 'devanagari', 'value' => "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}" ),
			array( 'label' => 'greek', 'value' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" ),
			array( 'label' => 'cyrillic', 'value' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}" ),
			array( 'label' => 'hiragana', 'value' => "\u{3086}\u{3046}\u{3056}\u{3042}" ),
			array( 'label' => 'cjk', 'value' => "\u{7528}\u{6237}" ),
		);
		$domain_specs = array(
			array( 'label' => 'ascii-example', 'domain' => 'example.com' ),
			array( 'label' => 'ascii-subdomain', 'domain' => 'sub-domain.example' ),
			array( 'label' => 'whatwg-single-label', 'domain' => 'localhost' ),
			array( 'label' => 'latin-ring', 'domain' => "gr\u{00E5}.org" ),
			array( 'label' => 'latin-diaeresis', 'domain' => "b\u{00FC}cher.de" ),
			array( 'label' => 'eszett', 'domain' => "fa\u{00DF}.de" ),
			array( 'label' => 'cjk', 'domain' => "\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" ),
			array( 'label' => 'greek', 'domain' => "\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" ),
			array( 'label' => 'cyrillic', 'domain' => "\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}" ),
			array( 'label' => 'hiragana', 'domain' => "\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}" ),
		);
		$domains      = array();

		foreach ( $domain_specs as $domain_spec ) {
			$domains[ $domain_spec['label'] ] = self::unicode_matrix_domain(
				$domain_spec['label'],
				$domain_spec['domain']
			);
		}

		$cases = array(
			self::unicode_matrix_case(
				'anchor-utf8-local-ascii-domain',
				"gr\u{00E5}",
				$domains['ascii-example'],
				'unicode'
			),
			self::unicode_matrix_case(
				'anchor-ascii-local-unicode-domain',
				'mail',
				$domains['latin-ring'],
				'unicode'
			),
			self::unicode_matrix_case(
				'anchor-utf8-local-unicode-domain',
				"jose\u{0301}",
				$domains['latin-diaeresis'],
				'unicode'
			),
			self::unicode_matrix_case(
				'anchor-utf8-local-punycode-domain',
				"\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}",
				$domains['latin-diaeresis'],
				'ascii'
			),
			self::unicode_matrix_case(
				'anchor-whatwg-atext-punycode-domain',
				'azAZ09.!#$%&\'*+/=?^_`{|}~-',
				$domains['cjk'],
				'ascii'
			),
			self::unicode_matrix_case(
				'anchor-ascii-only-whatwg-domain',
				'USER+tag',
				$domains['whatwg-single-label'],
				'unicode'
			),
		);

		$domain_values = array_values( $domains );
		for ( $i = 0; $i < self::GENERATED_UNICODE_MATRIX_CASES; $i++ ) {
			$local  = $ctx->choice( $locals );
			$domain = $ctx->choice( $domain_values );
			$view   = $domain['isIdn'] && $ctx->bool() ? 'ascii' : 'unicode';

			$cases[] = self::unicode_matrix_case(
				'generated-filter-view-' . $i . '-' . $local['label'] . '-' . $domain['label'],
				$local['value'],
				$domain,
				$view
			);
		}

		return $cases;
	}

	private static function generated_malformed_variant_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$locals       = array(
			array( 'label' => 'ascii', 'value' => 'user' ),
			array( 'label' => 'ascii-plus', 'value' => 'USER+tag' ),
			array( 'label' => 'whatwg-atext', 'value' => 'azAZ09.!#$%&\'*+/=?^_`{|}~-' ),
			array( 'label' => 'latin-composed', 'value' => "gr\u{00E5}" ),
			array( 'label' => 'latin-combining', 'value' => "jose\u{0301}" ),
			array( 'label' => 'devanagari', 'value' => "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}" ),
			array( 'label' => 'greek', 'value' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" ),
			array( 'label' => 'cyrillic', 'value' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}" ),
			array( 'label' => 'hiragana', 'value' => "\u{3086}\u{3046}\u{3056}\u{3042}" ),
			array( 'label' => 'cjk', 'value' => "\u{7528}\u{6237}" ),
		);
		$domain_specs = array(
			array( 'label' => 'ascii-example', 'domain' => 'example.com' ),
			array( 'label' => 'ascii-subdomain', 'domain' => 'sub-domain.example' ),
			array( 'label' => 'whatwg-single-label', 'domain' => 'localhost' ),
		);

		if ( self::has_idn() ) {
			$domain_specs[] = array( 'label' => 'latin-ring', 'domain' => "gr\u{00E5}.org" );
			$domain_specs[] = array( 'label' => 'latin-diaeresis', 'domain' => "b\u{00FC}cher.de" );
			$domain_specs[] = array( 'label' => 'cjk', 'domain' => "\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" );
			$domain_specs[] = array( 'label' => 'greek', 'domain' => "\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}" );
		}

		$domains = array();
		foreach ( $domain_specs as $domain_spec ) {
			$domains[ $domain_spec['label'] ] = self::unicode_matrix_domain(
				$domain_spec['label'],
				$domain_spec['domain']
			);
		}

		$cases = array(
			self::malformed_variant_case(
				'anchor-ascii-valid-base',
				'user',
				$domains['ascii-example'],
				'unicode'
			),
			self::malformed_variant_case(
				'anchor-utf8-local-ascii-domain',
				"gr\u{00E5}",
				$domains['ascii-example'],
				'unicode'
			),
			self::malformed_variant_case(
				'anchor-combining-local-single-label-domain',
				"jose\u{0301}",
				$domains['whatwg-single-label'],
				'unicode'
			),
		);

		if ( self::has_idn() ) {
			$cases[] = self::malformed_variant_case(
				'anchor-ascii-local-unicode-domain',
				'mail',
				$domains['latin-ring'],
				'unicode'
			);
			$cases[] = self::malformed_variant_case(
				'anchor-utf8-local-punycode-domain',
				"\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}",
				$domains['latin-diaeresis'],
				'ascii'
			);
		}

		$domain_values = array_values( $domains );
		for ( $i = 0; $i < self::GENERATED_MALFORMED_VARIANT_CASES; $i++ ) {
			$local  = $ctx->choice( $locals );
			$domain = $ctx->choice( $domain_values );
			$view   = $domain['isIdn'] && $ctx->bool() ? 'ascii' : 'unicode';

			$cases[] = self::malformed_variant_case(
				'generated-malformed-base-' . $i . '-' . $local['label'] . '-' . $domain['label'],
				$local['value'],
				$domain,
				$view
			);
		}

		return $cases;
	}

	private static function generated_utf8_localpart_oracle_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$profiles = array(
			array( 'label' => 'ascii-whatwg-atext', 'local' => 'azAZ09.!#$%&\'*+/=?^_`{|}~-', 'valid' => true ),
			array( 'label' => 'latin-composed', 'local' => "gr\u{00E5}", 'valid' => true ),
			array( 'label' => 'latin-combining', 'local' => "jose\u{0301}", 'valid' => true ),
			array( 'label' => 'punctuation-combining', 'local' => "tag.\u{0301}x", 'valid' => true ),
			array( 'label' => 'devanagari-clusters', 'local' => "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}", 'valid' => true ),
			array( 'label' => 'arabic', 'local' => "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}", 'valid' => true ),
			array( 'label' => 'greek', 'local' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}", 'valid' => true ),
			array( 'label' => 'cyrillic', 'local' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}", 'valid' => true ),
			array( 'label' => 'hiragana', 'local' => "\u{3086}\u{3046}\u{3056}\u{3042}", 'valid' => true ),
			array( 'label' => 'cjk', 'local' => "\u{7528}\u{6237}", 'valid' => true ),
			array( 'label' => 'leading-combining', 'local' => "\u{0301}bad", 'valid' => false ),
			array( 'label' => 'emoji-symbol', 'local' => "emoji\u{1F600}", 'valid' => false ),
			array( 'label' => 'snowman-symbol', 'local' => "snow\u{2603}", 'valid' => false ),
			array( 'label' => 'zero-width-joiner', 'local' => "zero\u{200D}width", 'valid' => false ),
			array( 'label' => 'quoted-local', 'local' => '"quoted"', 'valid' => false ),
			array( 'label' => 'comment-local', 'local' => 'user(comment)', 'valid' => false ),
			array( 'label' => 'colon-local', 'local' => 'first:last', 'valid' => false ),
			array( 'label' => 'fullwidth-dot-local', 'local' => "fullwidth\u{FF0E}dot", 'valid' => false ),
			array( 'label' => 'space-local', 'local' => 'first last', 'valid' => false ),
			array( 'label' => 'control-local', 'local' => "control\x01", 'valid' => false ),
			array( 'label' => 'invalid-utf8-local', 'local' => "bad\x80", 'valid' => false ),
		);
		$domains  = array(
			self::unicode_matrix_domain( 'ascii-example', 'example.com' ),
			self::unicode_matrix_domain( 'ascii-subdomain', 'sub-domain.example' ),
			self::unicode_matrix_domain( 'whatwg-single-label', 'localhost' ),
		);
		$cases    = array(
			self::utf8_localpart_oracle_case(
				'anchor-mixed-scripts-valid',
				array(
					'label' => 'mixed-scripts-valid',
					'local' => "a\u{0301}.\u{03B4}\u{0928}\u{094D}\u{7528}7",
					'valid' => true,
				),
				$domains[0],
				'unicode'
			),
			self::utf8_localpart_oracle_case(
				'anchor-disallowed-punctuation-invalid',
				array(
					'label' => 'disallowed-punctuation-invalid',
					'local' => 'first:last',
					'valid' => false,
				),
				$domains[1],
				'unicode'
			),
		);

		if ( self::has_idn() ) {
			$domains[] = self::unicode_matrix_domain( 'latin-ring-idn', "gr\u{00E5}.org" );
			$domains[] = self::unicode_matrix_domain( 'latin-diaeresis-idn', "b\u{00FC}cher.de" );
			$domains[] = self::unicode_matrix_domain( 'cjk-idn', "\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}" );

			$cases[] = self::utf8_localpart_oracle_case(
				'anchor-punycode-domain-valid',
				array(
					'label' => 'latin-combining',
					'local' => "jose\u{0301}",
					'valid' => true,
				),
				$domains[4],
				'ascii'
			);
			$cases[] = self::utf8_localpart_oracle_case(
				'anchor-idn-domain-invalid-local',
				array(
					'label' => 'emoji-symbol',
					'local' => "emoji\u{1F600}",
					'valid' => false,
				),
				$domains[3],
				'unicode'
			);
		}

		for ( $i = 0; $i < self::GENERATED_UTF8_LOCALPART_ORACLE_CASES; $i++ ) {
			$profile = $ctx->choice( $profiles );
			$domain  = $ctx->choice( $domains );
			$view    = $domain['isIdn'] && $ctx->bool() ? 'ascii' : 'unicode';
			$local   = $profile['local'];

			if ( '' !== $local ) {
				$profile['local'] = $local . $ctx->int( 10, 99 );
			}

			$cases[] = self::utf8_localpart_oracle_case(
				'generated-localpart-oracle-' . $i . '-' . $profile['label'] . '-' . $domain['label'],
				$profile,
				$domain,
				$view
			);
		}

		return $cases;
	}

	private static function utf8_localpart_oracle_case( string $label, array $profile, array $domain, string $input_domain_view ): array {
		$input_domain           = 'ascii' === $input_domain_view ? $domain['ascii'] : $domain['unicode'];
		$expected_unicode_valid = $profile['valid'] && null === $domain['conversionError'];

		return array(
			'label'                  => $label,
			'profile'                => $profile['label'],
			'local'                  => $profile['local'],
			'domainLabel'            => $domain['label'],
			'asciiDomain'            => $domain['ascii'],
			'unicodeDomain'          => $domain['unicode'],
			'inputDomainView'        => $input_domain_view,
			'input'                  => $profile['local'] . '@' . $input_domain,
			'expectedAsciiAddress'   => $profile['local'] . '@' . $domain['ascii'],
			'expectedUnicodeAddress' => $profile['local'] . '@' . $domain['unicode'],
			'expectedUnicodeValid'   => $expected_unicode_valid,
			'expectedAsciiValid'     => $expected_unicode_valid && self::is_ascii( $profile['local'] ) && ! $domain['isIdn'],
			'conversionError'        => $domain['conversionError'],
		);
	}

	private static function unicode_matrix_domain( string $label, string $unicode_domain ): array {
		$ascii_domain     = $unicode_domain;
		$decoded_domain   = $unicode_domain;
		$conversion_error = null;

		if ( ! self::is_ascii( $unicode_domain ) ) {
			$ascii_domain = idn_to_ascii( $unicode_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $ascii_domain ) {
				$conversion_error = 'idn_to_ascii failed';
				$ascii_domain     = '';
			} else {
				$decoded_domain = idn_to_utf8( $ascii_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( false === $decoded_domain ) {
					$conversion_error = 'idn_to_utf8 failed';
					$decoded_domain   = $unicode_domain;
				}
			}
		}

		return array(
			'label'           => $label,
			'ascii'           => $ascii_domain,
			'unicode'         => $decoded_domain,
			'isIdn'           => $ascii_domain !== $decoded_domain,
			'conversionError' => $conversion_error,
		);
	}

	private static function unicode_matrix_case( string $label, string $local, array $domain, string $input_domain_view ): array {
		$input_domain = 'ascii' === $input_domain_view ? $domain['ascii'] : $domain['unicode'];

		return array(
			'label'                  => $label,
			'local'                  => $local,
			'domainLabel'            => $domain['label'],
			'asciiDomain'            => $domain['ascii'],
			'unicodeDomain'          => $domain['unicode'],
			'inputDomainView'        => $input_domain_view,
			'input'                  => $local . '@' . $input_domain,
			'expectedAsciiAddress'   => $local . '@' . $domain['ascii'],
			'expectedUnicodeAddress' => $local . '@' . $domain['unicode'],
			'hasUnicodeLocal'        => ! self::is_ascii( $local ),
			'hasUnicodeDomain'       => $domain['isIdn'],
			'asciiModeValid'         => self::is_ascii( $local ) && ! $domain['isIdn'],
			'conversionError'        => $domain['conversionError'],
		);
	}

	private static function malformed_variant_case( string $label, string $local, array $domain, string $input_domain_view ): array {
		$case         = self::unicode_matrix_case( $label, $local, $domain, $input_domain_view );
		$input_domain = 'ascii' === $input_domain_view ? $domain['ascii'] : $domain['unicode'];
		$case['variants'] = self::malformed_email_variants( $local, $input_domain );

		return $case;
	}

	private static function malformed_email_variants( string $local, string $domain ): array {
		$empty_label_domain = str_contains( $domain, '.' )
			? preg_replace( '/\./', '..', $domain, 1 )
			: $domain . '..test';
		$ideographic_dot_domain = str_contains( strtolower( $domain ), 'xn--' )
			? 'bad' . "\u{3002}" . str_replace( '.', '-', $domain )
			: (
				str_contains( $domain, '.' )
					? preg_replace( '/\./', "\u{3002}", $domain, 1 )
					: $domain . "\u{3002}test"
			);

		$empty_label_domain     = is_string( $empty_label_domain ) ? $empty_label_domain : $domain . '..test';
		$ideographic_dot_domain = is_string( $ideographic_dot_domain ) ? $ideographic_dot_domain : $domain . "\u{3002}test";

		return array(
			array(
				'label' => 'empty-local',
				'input' => '@' . $domain,
			),
			array(
				'label' => 'extra-at',
				'input' => $local . '@extra@' . $domain,
			),
			array(
				'label' => 'fullwidth-at-separator',
				'input' => $local . "\u{FF20}" . $domain,
			),
			array(
				'label' => 'internal-local-space',
				'input' => $local . ' x@' . $domain,
			),
			array(
				'label' => 'line-break-local',
				'input' => $local . "\nmore@" . $domain,
			),
			array(
				'label' => 'zero-width-local',
				'input' => $local . "\u{200D}@" . $domain,
			),
			array(
				'label' => 'leading-combining-domain',
				'input' => $local . "@\u{0301}" . $domain,
			),
			array(
				'label' => 'empty-domain-label',
				'input' => $local . '@' . $empty_label_domain,
			),
			array(
				'label' => 'domain-underscore',
				'input' => $local . '@bad_' . $domain,
			),
			array(
				'label' => 'ideographic-dot-domain',
				'input' => $local . '@' . $ideographic_dot_domain,
			),
			array(
				'label' => 'reserved-ace-prefix',
				'input' => $local . '@ab--' . $domain,
			),
			array(
				'label' => 'invalid-punycode-label',
				'input' => $local . '@xn--.' . $domain,
			),
		);
	}

	private static function generated_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$locals = array(
			array( 'value' => 'user', 'traits' => array() ),
			array( 'value' => 'USER+tag', 'traits' => array() ),
			array( 'value' => 'azAZ09.!#$%&\'*+/=?^_`{|}~-', 'traits' => array( 'whatwgAtext' ) ),
			array( 'value' => "gr\u{00E5}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "jos\u{00E9}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "jose\u{0301}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{7528}\u{6237}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{0645}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{043F}\u{043E}\u{0447}\u{0442}\u{0430}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{3086}\u{3046}\u{3056}\u{3042}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "emoji\u{1F600}", 'traits' => array( 'unicodeAddress', 'localInvalidChars' ) ),
			array( 'value' => "\u{0301}bad", 'traits' => array( 'unicodeAddress', 'localInvalidChars' ) ),
			array( 'value' => "zero\u{200D}width", 'traits' => array( 'unicodeAddress', 'localInvalidChars' ) ),
			array( 'value' => '"quoted"', 'traits' => array( 'quotedLookingLocal' ) ),
			array( 'value' => 'user(comment)', 'traits' => array( 'commentLookingLocal' ) ),
			array( 'value' => '.start', 'traits' => array( 'localDot' ) ),
			array( 'value' => 'end.', 'traits' => array( 'localDot' ) ),
			array( 'value' => str_repeat( 'a', 64 ), 'traits' => array( 'boundaryLength' ) ),
			array( 'value' => str_repeat( 'a', 65 ), 'traits' => array( 'boundaryLength' ) ),
			array( 'value' => 'first last', 'traits' => array( 'localInvalidChars' ) ),
			array( 'value' => "control\x01", 'traits' => array( 'localInvalidChars' ) ),
			array( 'value' => "bad\x80", 'traits' => array( 'invalidUtf8', 'unicodeAddress' ) ),
			array( 'value' => "overlong\xC0\xAF", 'traits' => array( 'invalidUtf8', 'unicodeAddress' ) ),
			array( 'value' => "truncated\xE2\x82", 'traits' => array( 'invalidUtf8', 'unicodeAddress' ) ),
		);
		$domains = array(
			array( 'value' => 'example.com', 'traits' => array() ),
			array( 'value' => 'example.co.uk', 'traits' => array() ),
			array( 'value' => 'sub-domain.example', 'traits' => array() ),
			array( 'value' => "gr\u{00E5}.org", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "b\u{00FC}cher.tld", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{4F8B}\u{5B50}.\u{5E7F}\u{544A}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{0645}\u{062B}\u{0627}\u{0644}.\u{0625}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{03C0}\u{03B1}\u{03C1}\u{03AC}\u{03B4}\u{03B5}\u{03B9}\u{03B3}\u{03BC}\u{03B1}.\u{03B4}\u{03BF}\u{03BA}\u{03B9}\u{03BC}\u{03AE}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0438}\u{0441}\u{043F}\u{044B}\u{0442}\u{0430}\u{043D}\u{0438}\u{0435}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "\u{308C}\u{3044}.\u{307F}\u{3093}\u{306A}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "fa\u{00DF}.de", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => 'xn--bcher-kva.de', 'traits' => array( 'punycodeUnicodeDomain' ) ),
			array( 'value' => 'xn--fsqu00a.xn--4rr70v', 'traits' => array( 'punycodeUnicodeDomain' ) ),
			array( 'value' => 'xn--hxajbheg2az3al.xn--jxalpdlp', 'traits' => array( 'punycodeUnicodeDomain' ) ),
			array( 'value' => 'domain..com', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => '.example.com', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => '-example.com', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => 'example-.com', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => 'example_corp.com', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => '[127.0.0.1]', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => "\u{0301}example.com", 'traits' => array( 'unicodeAddress', 'malformedDomain' ) ),
			array( 'value' => "example\u{3002}com", 'traits' => array( 'unicodeAddress', 'malformedDomain' ) ),
			array( 'value' => str_repeat( 'a', 63 ) . '.com', 'traits' => array( 'boundaryLength' ) ),
			array( 'value' => str_repeat( 'a', 64 ) . '.com', 'traits' => array( 'boundaryLength', 'malformedDomain' ) ),
			array( 'value' => str_repeat( "\u{00E5}", 31 ) . 'a.com', 'traits' => array( 'unicodeAddress', 'boundaryLength' ) ),
			array( 'value' => str_repeat( "\u{00E5}", 32 ) . '.com', 'traits' => array( 'unicodeAddress', 'boundaryLength', 'malformedDomain' ) ),
			array( 'value' => 'localhost', 'traits' => array( 'whatwgSingleLabelDomain' ) ),
			array( 'value' => "bad\x80.test", 'traits' => array( 'invalidUtf8', 'unicodeAddress' ) ),
			array( 'value' => "bad\xE2\x82.test", 'traits' => array( 'invalidUtf8', 'unicodeAddress' ) ),
		);
		$cases = array();

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$local  = $ctx->choice( $locals );
			$domain = $ctx->choice( $domains );
			$input  = $local['value'] . '@' . $domain['value'];
			$traits = array_values( array_unique( array_merge( $local['traits'], $domain['traits'] ) ) );

			if ( $ctx->bool( 18 ) ) {
				$input    = 'Name <' . $input . '>';
				$traits[] = 'displayName';
			}
			if ( $ctx->bool( 20 ) ) {
				$input    = str_replace( '@', ' @ ', $input ) . '.';
				$traits[] = 'recoverableWhitespace';
			}
			if ( $ctx->bool( 12 ) ) {
				$input    = str_replace( '@', "\u{00A0}@\u{00A0}", $input );
				$traits[] = 'recoverableWhitespace';
			}
			if ( $ctx->bool( 16 ) ) {
				$input    = str_replace( '@', '@extra@', $input );
				$traits[] = 'malformedAt';
			}
			if ( $ctx->bool( 10 ) ) {
				$input    = str_replace( '@', "\u{FF20}", $input );
				$traits[] = 'unicodeAddress';
				$traits[] = 'malformedAt';
			}
			if ( $ctx->bool( 10 ) ) {
				$input    = str_replace( '@', '(comment)@', $input );
				$traits[] = 'commentLookingLocal';
			}
			if ( $ctx->bool( 12 ) ) {
				$input    = " \t" . $input . "\n";
				$traits[] = 'edgeWhitespace';
			}

			$traits  = array_values( array_unique( $traits ) );
			$cases[] = array(
				'label'  => 'generated-' . $i,
				'input'  => $input,
				'source' => 'generated',
				'traits' => $traits,
			);
		}

		return $cases;
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
			);
		}
	}

	private static function capture_warnings( callable $callback ): array {
		$warnings = array();
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ) use ( &$warnings ): bool {
				$warnings[] = array(
					'severity' => $severity,
					'message'  => $message,
					'file'     => $file,
					'line'     => $line,
				);
				return true;
			}
		);

		try {
			return array(
				'threw'    => false,
				'value'    => $callback(),
				'warnings' => $warnings,
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'warnings'  => $warnings,
			);
		} finally {
			restore_error_handler();
		}
	}

	private static function case_result( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case, string $invariant, bool $ok, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			$ok,
			array_merge(
				array(
					'caseIndex' => $case_index,
					'label'     => $case['label'],
					'source'    => $case['source'],
					'traits'    => implode( ',', $case['traits'] ),
					'input'     => self::describe_string( $case['input'] ),
				),
				$data
			)
		);
	}

	private static function has_trait( array $case, string $trait ): bool {
		return in_array( $trait, $case['traits'], true );
	}

	private static function describe_call( array $call ) {
		if ( $call['threw'] ) {
			return array(
				'threw'     => true,
				'throwable' => $call['throwable'],
			);
		}

		return array(
			'threw' => false,
			'value' => self::describe_value( $call['value'] ),
		);
	}

	private static function describe_captured_call( array $call ): array {
		$description = self::describe_call( $call );
		$description['warnings'] = self::describe_value( $call['warnings'] ?? array() );

		return $description;
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( $value instanceof \WP_Error ) {
			return array(
				'code'    => $value->get_error_code(),
				'message' => self::escape_bytes( $value->get_error_message() ),
				'data'    => self::describe_value( $value->get_error_data() ),
			);
		}
		if ( $value instanceof \WP_Email_Address ) {
			return self::describe_address( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}
		return $value;
	}

	private static function describe_address( \WP_Email_Address $email ): array {
		return array(
			'localpart'      => self::describe_string( $email->get_localpart() ),
			'asciiDomain'    => self::describe_string( $email->get_ascii_domain() ),
			'unicodeDomain'  => self::describe_string( $email->get_unicode_domain() ),
			'asciiAddress'   => self::describe_string( $email->get_ascii_address() ),
			'unicodeAddress' => self::describe_string( $email->get_unicode_address() ),
		);
	}

	private static function describe_string( string $value ): array {
		$valid_utf8 = \wp_is_valid_utf8( $value );
		$out        = array(
			'bytes'   => strlen( $value ),
			'utf8'    => $valid_utf8,
			'preview' => self::escape_bytes( $value ),
			'hex'     => bin2hex( $value ),
		);

		if ( $valid_utf8 && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			$out['text'] = $value;
		}

		return $out;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
		);
	}

	private static function escape_bytes( string $value, int $limit = 160 ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; $i++ ) {
			$byte = ord( $value[ $i ] );
			if ( $byte >= 0x20 && $byte <= 0x7e && 0x5c !== $byte ) {
				$out .= chr( $byte );
			} elseif ( 0x5c === $byte ) {
				$out .= '\\\\';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $limit ) {
			$out .= '...';
		}

		return $out;
	}

	private static function is_ascii( string $value ): bool {
		return ! preg_match( '/[\x80-\xff]/', $value );
	}

	private static function has_idn(): bool {
		return function_exists( 'idn_to_ascii' ) && function_exists( 'idn_to_utf8' );
	}

	private static function snapshot_hook_globals(): array {
		$snapshot = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter' ) as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_hook_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function snapshot_wpdb_charset(): array {
		return array(
			'hasWpdb'     => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ),
			'hasCharset'  => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'charset' ),
			'charset'     => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'charset' ) ? $GLOBALS['wpdb']->charset : null,
			'originalWpdb' => isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null,
		);
	}

	private static function restore_wpdb_charset( array $snapshot ): void {
		if ( ! $snapshot['hasWpdb'] ) {
			unset( $GLOBALS['wpdb'] );
			return;
		}

		$GLOBALS['wpdb'] = $snapshot['originalWpdb'];
		if ( $snapshot['hasCharset'] ) {
			$GLOBALS['wpdb']->charset = $snapshot['charset'];
		} elseif ( is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'charset' ) ) {
			unset( $GLOBALS['wpdb']->charset );
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
}
