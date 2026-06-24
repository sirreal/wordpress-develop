<?php
namespace ComponentFuzz\Surfaces;

final class EmailSurface {
	public const NAME = 'email';

	private const GENERATED_CASES = 32;
	private const GENERATED_VIEW_CASES = 10;
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

		$rows     = array();
		$snapshot = self::snapshot_hook_globals();

		try {
			$cases = self::cases( $ctx );

			$rows = array_merge( $rows, self::check_utf8mb4_filter_gate( $ctx ) );

			self::install_email_filters( 'unicode' );
			$rows[] = self::check_unicode_filters( $ctx );
			$rows = array_merge( $rows, self::check_direct_filter_callbacks( $ctx ) );
			$rows = array_merge( $rows, self::check_whatwg_examples( $ctx ) );
			$rows = array_merge( $rows, self::check_whatwg_ascii_oracle( $ctx ) );
			$rows = array_merge( $rows, self::check_sanitizer_recovery( $ctx ) );
			$rows = array_merge( $rows, self::check_malformed_utf8_byte_matrix( $ctx ) );
			$rows = array_merge( $rows, self::check_unicode_localpart_byte_boundaries( $ctx ) );

			foreach ( $cases as $case_index => $case ) {
				$rows = array_merge( $rows, self::check_unicode_case( $ctx, $case_index, $case ) );
			}

			$rows = array_merge( $rows, self::check_distinct_localparts( $ctx ) );
			$rows = array_merge( $rows, self::check_normalization_sensitive_localparts( $ctx ) );
			$rows = array_merge( $rows, self::check_comment_author_email_filters( $ctx ) );
			$rows = array_merge( $rows, self::check_user_email_indexes_distinct_localparts( $ctx ) );
			$rows = array_merge( $rows, self::check_user_email_indexes_distinct_domains( $ctx ) );
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
				'wp_is_unicode_email',
				'wp_sanitize_unicode_email',
				'wp_is_ascii_email',
				'wp_sanitize_ascii_email',
				'wp_cache_flush',
				'wp_insert_user',
				'wp_is_valid_utf8',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Email_Address' ) ) {
			$missing[] = 'class WP_Email_Address';
		}
		if ( ! class_exists( 'WP_User' ) ) {
			$missing[] = 'class WP_User';
		}

		return $missing;
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
				&& self::make_clickable_known_partial_email_boundary( $address, $anchors )
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

	private static function make_clickable_known_partial_email_boundary( string $address, array $anchors ): bool {
		if ( ! str_contains( $address, '.xn--' ) ) {
			return false;
		}

		foreach ( $anchors as $anchor ) {
			$href = $anchor['href'] ?? null;
			$text = $anchor['text'] ?? null;
			if (
				is_string( $href )
				&& is_string( $text )
				&& $href === $text
				&& $href !== $address
				&& str_starts_with( $address, $href )
			) {
				return true;
			}
		}

		return false;
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
				$GLOBALS[ $name ] = $entry['value'];
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
