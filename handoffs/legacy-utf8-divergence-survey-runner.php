<?php
/**
 * One-shot runner for the legacy UTF-8 helper divergence report.
 *
 * Usage:
 *
 *     php handoffs/legacy-utf8-divergence-survey-runner.php 3000000 256 > /tmp/legacy_utf8_divergence_survey_results.json
 *
 * The runner expects the encoding fuzzer checkout beside this repository by
 * default, or in ENCODING_FUZZER_ROOT when provided. It deliberately loads a
 * small subset of WordPress with stubs because the report is about byte-level
 * helper behavior, not full WordPress bootstrap behavior.
 */

use EncodingFuzz\Generator;
use EncodingFuzz\Oracles;
use EncodingFuzz\Prng;

function survey_repo_root(): string {
	return dirname( __DIR__ );
}

function survey_fuzzer_root(): string {
	$from_env = getenv( 'ENCODING_FUZZER_ROOT' );
	if ( is_string( $from_env ) && '' !== $from_env ) {
		return rtrim( $from_env, '/' );
	}

	return dirname( survey_repo_root() ) . '/encoding-fuzzer';
}

$fuzzer_root = survey_fuzzer_root();
require $fuzzer_root . '/tools/encoding-fuzz/lib/autoload.php';

$GLOBALS['survey_blog_charset'] = 'UTF-8';

function _deprecated_function( $function_name, $version, $replacement = '' ) {}
function _deprecated_argument( $function_name, $version, $message = '' ) {}
function get_option( $name ) {
	return 'blog_charset' === $name ? $GLOBALS['survey_blog_charset'] : null;
}
function mbstring_binary_safe_encoding( $reset = false ) {}
function reset_mbstring_encoding() {}

require survey_repo_root() . '/src/wp-includes/compat.php';

function is_utf8_charset( $blog_charset = null ) {
	return _is_utf8_charset( $blog_charset ?? get_option( 'blog_charset' ) );
}

require survey_repo_root() . '/src/wp-includes/compat-utf8.php';
require survey_repo_root() . '/src/wp-includes/utf8.php';
require survey_repo_root() . '/src/wp-includes/formatting.php';

function hx( string $bytes ): string {
	return strtoupper( trim( chunk_split( bin2hex( $bytes ), 2, ' ' ) ) );
}

function visible( string $bytes ): string {
	if ( '' === $bytes ) {
		return "''";
	}

	return hx( $bytes );
}

function check_invalid_with_charset( string $bytes, bool $strip, string $charset ): string {
	$GLOBALS['survey_blog_charset'] = $charset;

	return wp_check_invalid_utf8_uncached( $bytes, $strip );
}

function wp_check_invalid_utf8_uncached( string $text, bool $strip ): string {
	$text = (string) $text;

	if ( 0 === strlen( $text ) ) {
		return '';
	}

	if ( ! is_utf8_charset() || wp_is_valid_utf8( $text ) ) {
		return $text;
	}

	return $strip ? wp_scrub_utf8( $text ) : '';
}

function invalid_class( string $bytes ): string {
	$length = strlen( $bytes );
	for ( $i = 0; $i < $length; $i++ ) {
		$b0 = ord( $bytes[ $i ] );
		if ( $b0 < 0x80 ) {
			continue;
		}

		if ( $b0 >= 0x80 && $b0 <= 0xBF ) {
			return 'lone continuation byte';
		}

		if ( $b0 >= 0xC0 && $b0 <= 0xC1 ) {
			return has_continuations( $bytes, $i + 1, 1 ) ? 'overlong 2-byte sequence' : 'truncated C0/C1 lead';
		}

		if ( $b0 >= 0xC2 && $b0 <= 0xDF ) {
			if ( ! has_continuations( $bytes, $i + 1, 1 ) ) {
				return 'truncated 2-byte sequence';
			}
			$i += 1;
			continue;
		}

		if ( 0xE0 === $b0 ) {
			if ( ! has_continuations( $bytes, $i + 1, 2 ) ) {
				return 'truncated 3-byte sequence';
			}
			if ( ord( $bytes[ $i + 1 ] ) < 0xA0 ) {
				return 'overlong 3-byte sequence';
			}
			$i += 2;
			continue;
		}

		if ( $b0 >= 0xE1 && $b0 <= 0xEC ) {
			if ( ! has_continuations( $bytes, $i + 1, 2 ) ) {
				return 'truncated 3-byte sequence';
			}
			$i += 2;
			continue;
		}

		if ( 0xED === $b0 ) {
			if ( ! has_continuations( $bytes, $i + 1, 2 ) ) {
				return 'truncated 3-byte sequence';
			}
			if ( ord( $bytes[ $i + 1 ] ) >= 0xA0 ) {
				return 'UTF-16 surrogate sequence';
			}
			$i += 2;
			continue;
		}

		if ( $b0 >= 0xEE && $b0 <= 0xEF ) {
			if ( ! has_continuations( $bytes, $i + 1, 2 ) ) {
				return 'truncated 3-byte sequence';
			}
			$i += 2;
			continue;
		}

		if ( 0xF0 === $b0 ) {
			if ( ! has_continuations( $bytes, $i + 1, 3 ) ) {
				return 'truncated 4-byte sequence';
			}
			if ( ord( $bytes[ $i + 1 ] ) < 0x90 ) {
				return 'overlong 4-byte sequence';
			}
			$i += 3;
			continue;
		}

		if ( $b0 >= 0xF1 && $b0 <= 0xF3 ) {
			if ( ! has_continuations( $bytes, $i + 1, 3 ) ) {
				return 'truncated 4-byte sequence';
			}
			$i += 3;
			continue;
		}

		if ( 0xF4 === $b0 ) {
			if ( ! has_continuations( $bytes, $i + 1, 3 ) ) {
				return 'truncated 4-byte sequence';
			}
			if ( ord( $bytes[ $i + 1 ] ) > 0x8F ) {
				return 'code point above U+10FFFF';
			}
			$i += 3;
			continue;
		}

		if ( $b0 >= 0xF5 && $b0 <= 0xF7 ) {
			return has_continuations( $bytes, $i + 1, 3 ) ? 'code point above U+10FFFF' : 'invalid F5-F7 lead';
		}

		if ( $b0 >= 0xF8 && $b0 <= 0xFB ) {
			return has_continuations( $bytes, $i + 1, 4 ) ? 'obsolete 5-byte sequence' : 'invalid F8-FB lead';
		}

		if ( $b0 >= 0xFC && $b0 <= 0xFD ) {
			return has_continuations( $bytes, $i + 1, 5 ) ? 'obsolete 6-byte sequence' : 'invalid FC-FD lead';
		}

		return 'FE/FF invalid lead';
	}

	return 'valid';
}

function has_continuations( string $bytes, int $start, int $count ): bool {
	for ( $i = 0; $i < $count; $i++ ) {
		$at = $start + $i;
		if ( $at >= strlen( $bytes ) ) {
			return false;
		}

		$b = ord( $bytes[ $at ] );
		if ( ( $b & 0xC0 ) !== 0x80 ) {
			return false;
		}
	}

	return true;
}

function vector_row( string $name, string $bytes ): array {
	$valid        = wp_is_valid_utf8( $bytes );
	$seems        = seems_utf8( $bytes );
	$scrubbed     = wp_scrub_utf8( $bytes );
	$check_keep   = check_invalid_with_charset( $bytes, false, 'UTF-8' );
	$check_strip  = check_invalid_with_charset( $bytes, true, 'UTF-8' );
	$latin_keep   = check_invalid_with_charset( $bytes, false, 'ISO-8859-1' );
	$latin_strip  = check_invalid_with_charset( $bytes, true, 'ISO-8859-1' );

	return array(
		'name'                  => $name,
		'hex'                   => hx( $bytes ),
		'class'                 => invalid_class( $bytes ),
		'wp_is_valid_utf8'      => $valid,
		'seems_utf8'            => $seems,
		'wp_scrub_utf8_hex'     => visible( $scrubbed ),
		'check_utf8_keep_hex'   => visible( $check_keep ),
		'check_utf8_strip_hex'  => visible( $check_strip ),
		'check_latin_keep_hex'  => visible( $latin_keep ),
		'check_latin_strip_hex' => visible( $latin_strip ),
	);
}

$vectors = array(
	'ascii'                     => 'A',
	'valid 2-byte lower edge'   => "\xC2\x80",
	'valid 3-byte lower edge'   => "\xE0\xA0\x80",
	'valid 4-byte upper edge'   => "\xF4\x8F\xBF\xBF",
	'valid noncharacter U+FFFE' => "\xEF\xBF\xBE",
	'valid replacement U+FFFD'  => "\xEF\xBF\xBD",
	'lone continuation'         => "\x80",
	'FE invalid lead'           => "\xFE",
	'truncated 2-byte'          => "\xC2",
	'truncated 3-byte'          => "\xE2\x8C",
	'truncated 4-byte'          => "\xF1\x80\x80",
	'overlong 2-byte'           => "\xC0\x80",
	'overlong 3-byte'           => "\xE0\x80\x80",
	'overlong 4-byte'           => "\xF0\x80\x80\x80",
	'surrogate U+D800'          => "\xED\xA0\x80",
	'above U+10FFFF F4'         => "\xF4\x90\x80\x80",
	'above U+10FFFF F5'         => "\xF5\x80\x80\x80",
	'obsolete 5-byte'           => "\xF8\x80\x80\x80\x80",
	'obsolete 6-byte'           => "\xFC\x80\x80\x80\x80\x80",
	'mixed invalid in text'     => "A\xC0\x80Z",
);

$rows = array();
foreach ( $vectors as $name => $bytes ) {
	$rows[] = vector_row( $name, $bytes );
}

$battery_rows = array();
foreach ( Oracles::battery() as $i => $vector ) {
	$battery_rows[] = vector_row( "battery {$i}", $vector[0] );
}

$cases     = (int) ( $argv[1] ?? 100000 );
$max_bytes = (int) ( $argv[2] ?? 256 );
$stats     = array(
	'cases'                               => 0,
	'bytes'                               => 0,
	'strict_valid'                        => 0,
	'strict_invalid'                      => 0,
	'seems_accepts_strict_invalid'        => 0,
	'seems_rejects_strict_invalid'        => 0,
	'seems_rejects_strict_valid'          => 0,
	'check_utf8_keep_empty_on_invalid'    => 0,
	'check_utf8_strip_matches_scrub'      => 0,
	'check_utf8_strip_mismatches_scrub'   => 0,
	'check_latin1_passthrough_on_invalid' => 0,
	'seems_accepts_invalid_by_class'      => array(),
	'seems_rejects_invalid_by_class'      => array(),
	'first_example_by_class'              => array(),
	'strategy_counts'                     => array(),
);

$start = microtime( true );
for ( $case = 0; $case < $cases; $case++ ) {
	$prng      = new Prng( "legacy-utf8-divergence:{$case}" );
	$generator = new Generator( $prng, $max_bytes );
	$generated = $generator->generate();
	$bytes     = $generated['bytes'];
	$strategy  = $generated['strategy'];
	$valid     = wp_is_valid_utf8( $bytes );
	$seems     = seems_utf8( $bytes );
	$class     = $valid ? 'valid' : invalid_class( $bytes );
	$scrubbed  = wp_scrub_utf8( $bytes );

	++$stats['cases'];
	$stats['bytes'] += strlen( $bytes );
	$stats['strategy_counts'][ $strategy ] = ( $stats['strategy_counts'][ $strategy ] ?? 0 ) + 1;

	if ( $valid ) {
		++$stats['strict_valid'];
		if ( ! $seems ) {
			++$stats['seems_rejects_strict_valid'];
		}
		continue;
	}

	++$stats['strict_invalid'];
	if ( $seems ) {
		++$stats['seems_accepts_strict_invalid'];
		$stats['seems_accepts_invalid_by_class'][ $class ] = ( $stats['seems_accepts_invalid_by_class'][ $class ] ?? 0 ) + 1;
	} else {
		++$stats['seems_rejects_strict_invalid'];
		$stats['seems_rejects_invalid_by_class'][ $class ] = ( $stats['seems_rejects_invalid_by_class'][ $class ] ?? 0 ) + 1;
	}

	if ( ! isset( $stats['first_example_by_class'][ $class ] ) ) {
		$stats['first_example_by_class'][ $class ] = hx( strlen( $bytes ) > 24 ? substr( $bytes, 0, 24 ) : $bytes );
	}

	if ( '' === check_invalid_with_charset( $bytes, false, 'UTF-8' ) ) {
		++$stats['check_utf8_keep_empty_on_invalid'];
	}

	if ( check_invalid_with_charset( $bytes, true, 'UTF-8' ) === $scrubbed ) {
		++$stats['check_utf8_strip_matches_scrub'];
	} else {
		++$stats['check_utf8_strip_mismatches_scrub'];
	}

	if (
		check_invalid_with_charset( $bytes, false, 'ISO-8859-1' ) === $bytes &&
		check_invalid_with_charset( $bytes, true, 'ISO-8859-1' ) === $bytes
	) {
		++$stats['check_latin1_passthrough_on_invalid'];
	}
}

ksort( $stats['seems_accepts_invalid_by_class'] );
ksort( $stats['seems_rejects_invalid_by_class'] );
ksort( $stats['strategy_counts'] );

$stats['elapsed_sec'] = round( microtime( true ) - $start, 3 );

echo json_encode(
	array(
		'environment' => array(
			'php'       => PHP_VERSION,
			'mbstring'  => extension_loaded( 'mbstring' ),
			'intl'      => extension_loaded( 'intl' ),
			'pcre_u'    => _wp_can_use_pcre_u(),
			'cases'     => $cases,
			'max_bytes' => $max_bytes,
		),
		'vectors'     => $rows,
		'battery'     => $battery_rows,
		'stats'       => $stats,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
