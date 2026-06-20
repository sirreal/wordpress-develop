<?php
namespace ComponentFuzz\Surfaces;

final class EmailSurface {
	public const NAME = 'email';

	private const GENERATED_CASES = 14;

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

			self::install_email_filters( 'unicode' );
			$rows[] = self::check_unicode_filters( $ctx );

			foreach ( $cases as $case_index => $case ) {
				$rows = array_merge( $rows, self::check_unicode_case( $ctx, $case_index, $case ) );
			}

			$rows = array_merge( $rows, self::check_distinct_localparts( $ctx ) );
			$rows = array_merge( $rows, self::check_punycode_views( $ctx ) );

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
				'is_email',
				'sanitize_email',
				'wp_is_unicode_email',
				'wp_sanitize_unicode_email',
				'wp_is_ascii_email',
				'wp_sanitize_ascii_email',
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

		return $missing;
	}

	private static function check_unicode_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$sample    = "gr\u{00E5}@gr\u{00E5}.org";
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

	private static function check_punycode_views( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'idn_to_utf8' ) ) {
			return array(
				$ctx->skip(
					'email.wp-email-address.punycode-domain-decodes',
					'idn_to_utf8() is unavailable.'
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

	private static function cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$has_idn = function_exists( 'idn_to_utf8' );
		$cases   = array(
			self::case( 'ascii-simple', 'user@example.com', array( 'ascii', 'valid' ), 'user@example.com', 'user@example.com', 'user@example.com', 'user@example.com' ),
			self::case( 'ascii-plus-subdomain', 'USER+tag@example.co.uk', array( 'ascii', 'valid' ), 'USER+tag@example.co.uk', 'USER+tag@example.co.uk', 'USER+tag@example.co.uk', 'USER+tag@example.co.uk' ),
			self::case( 'unicode-local-domain', "gr\u{00E5}@gr\u{00E5}.org", array( 'valid', 'unicodeAddress' ), "gr\u{00E5}@gr\u{00E5}.org", "gr\u{00E5}@gr\u{00E5}.org", false, '' ),
			self::case( 'unicode-local-ascii-domain', "jos\u{00E9}@example.com", array( 'valid', 'unicodeAddress' ), "jos\u{00E9}@example.com", "jos\u{00E9}@example.com", false, '' ),
			self::case( 'unicode-combining-local', "jose\u{0301}@example.com", array( 'valid', 'unicodeAddress' ), "jose\u{0301}@example.com", "jose\u{0301}@example.com", false, '' ),
			self::case( 'unicode-domain', "checkout@b\u{00FC}cher.tld", array( 'valid', 'unicodeAddress' ), "checkout@b\u{00FC}cher.tld", "checkout@b\u{00FC}cher.tld", false, '' ),
			self::case( 'punycode-domain', 'books@xn--bcher-kva.de', array( 'valid', 'punycodeUnicodeDomain' ), $has_idn ? "books@b\u{00FC}cher.de" : false, $has_idn ? "books@b\u{00FC}cher.de" : '', false, '' ),
			self::case( 'mixed-case-punycode-prefix', 'books@XN--BCHER-KVA.DE', array( 'reservedAcePrefix' ), false, '', false, '' ),
			self::case( 'display-name-wrapper', 'Display Name <user@example.com>', array( 'displayName' ), false, 'user@example.com', false, 'user@example.com' ),
			self::case( 'separator-whitespace-and-trailing-dot', " info @ example . com. \t", array( 'recoverableWhitespace' ), false, 'info@example.com', false, 'info@example.com' ),
			self::case( 'soft-hyphen-near-dot', "info@example\u{00AD}.com", array( 'recoverableWhitespace' ), false, 'info@example.com', false, 'info@example.com' ),
			self::case( 'multiple-at', 'bad@@example.com', array( 'malformedAt' ), false, '', false, '' ),
			self::case( 'missing-at', 'not-an-address.example.com', array( 'malformedAt' ), false, '', false, '' ),
			self::case( 'empty-local', '@example.com', array( 'malformedAt' ), false, '', false, '' ),
			self::case( 'empty-domain', 'user@', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'no-domain-period', 'a@b', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'empty-domain-label', 'name@domain..com', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'leading-domain-dot', 'name@.example.com', array( 'malformedDomain' ), false, '', false, '' ),
			self::case( 'quoted-looking-local', '"quoted"@example.com', array( 'quotedLookingLocal' ), false, '', false, '' ),
			self::case( 'quoted-html-looking-local', '"<iframe src=...>"@example.com', array( 'quotedLookingLocal' ), false, '', false, '' ),
			self::case( 'local-space', 'first last@example.com', array( 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'emoji-local', "emoji\u{1F600}@example.com", array( 'unicodeAddress', 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'zero-width-local', "zero\u{200D}width@example.com", array( 'unicodeAddress', 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'control-byte-local', "control\x01@example.com", array( 'localInvalidChars' ), false, '', false, '' ),
			self::case( 'invalid-utf8-local', "invalid\x80@example.com", array( 'invalidUtf8', 'unicodeAddress' ), false, '', false, '' ),
			self::case( 'invalid-utf8-domain', "user@example.\xC3\x28", array( 'invalidUtf8', 'unicodeAddress' ), false, '', false, '' ),
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

	private static function generated_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$locals = array(
			array( 'value' => 'user', 'traits' => array() ),
			array( 'value' => 'USER+tag', 'traits' => array() ),
			array( 'value' => "gr\u{00E5}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "jos\u{00E9}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "jose\u{0301}", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "emoji\u{1F600}", 'traits' => array( 'unicodeAddress', 'localInvalidChars' ) ),
			array( 'value' => '"quoted"', 'traits' => array( 'quotedLookingLocal' ) ),
			array( 'value' => '.start', 'traits' => array( 'localDot' ) ),
			array( 'value' => 'end.', 'traits' => array( 'localDot' ) ),
			array( 'value' => 'first last', 'traits' => array( 'localInvalidChars' ) ),
			array( 'value' => "bad\x80", 'traits' => array( 'invalidUtf8', 'unicodeAddress' ) ),
		);
		$domains = array(
			array( 'value' => 'example.com', 'traits' => array() ),
			array( 'value' => 'example.co.uk', 'traits' => array() ),
			array( 'value' => "gr\u{00E5}.org", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => "b\u{00FC}cher.tld", 'traits' => array( 'unicodeAddress' ) ),
			array( 'value' => 'xn--bcher-kva.de', 'traits' => array( 'punycodeUnicodeDomain' ) ),
			array( 'value' => 'domain..com', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => '.example.com', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => 'localhost', 'traits' => array( 'malformedDomain' ) ),
			array( 'value' => "bad\x80.test", 'traits' => array( 'invalidUtf8', 'unicodeAddress' ) ),
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
			if ( $ctx->bool( 16 ) ) {
				$input    = str_replace( '@', '@extra@', $input );
				$traits[] = 'malformedAt';
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
