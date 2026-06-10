<?php
/**
 * One-shot exhaustive test of `WP_HTML_Decoder::code_point_to_utf8_bytes()`.
 *
 * The function's domain is small enough (code points 0x0–0x10FFFF plus a
 * handful of out-of-range probes) to test completely instead of fuzzing:
 * total coverage in a few seconds, done forever.
 *
 * Documented contract (see the method docblock): a Unicode scalar value
 * encodes to its UTF-8 byte sequence; surrogates and out-of-range values
 * yield the replacement character U+FFFD.
 *
 * The genuinely independent oracle is `Generator::encode_code_point()`,
 * the fuzzer's pure-arithmetic UTF-8 encoder (no mbstring involvement).
 * A second comparison against `mb_chr( $cp, 'UTF-8' )` is a consistency
 * cross-check, NOT an independent oracle — the implementation is itself
 * mb_chr-backed — but it guards the arithmetic oracle and would expose
 * a bug shared between the implementation and the arithmetic encoder.
 *
 * `ENCODING_FUZZ_FAULT=codepoint-surrogate-qmark` injects a broken
 * variant (surrogates yield '?' instead of U+FFFD) so the harness smoke
 * test can prove this script's detection actually fires.
 *
 * Known caveat, asserted below: the implementation calls `mb_chr()`
 * WITHOUT an explicit encoding, so it inherits `mb_internal_encoding()`.
 * WordPress sets that from `blog_charset`, so on a non-UTF-8 site the
 * method can return non-UTF-8 bytes (e.g. `"\xE9"` for U+00E9 under
 * ISO-8859-1) despite its documented contract. This script pins the
 * internal encoding to UTF-8 for the exhaustive sweep, then demonstrates
 * the sensitivity as a separate documented finding.
 *
 * Exit codes: 0 pass, 1 findings, 2 harness error.
 */

namespace EncodingFuzz;

require __DIR__ . '/../lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

require Bootstrap::repo_root() . '/src/wp-includes/html-api/class-wp-html-decoder.php';

$failed = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failed;
	if ( $ok ) {
		echo "PASS {$label}\n";
	} else {
		++$failed;
		echo "FAIL {$label}" . ( '' !== $detail ? ": {$detail}" : '' ) . "\n";
	}
}

if ( ! function_exists( 'mb_chr' ) ) {
	fwrite( STDERR, "mbstring is required (both by this test's cross-check and by the implementation itself).\n" );
	exit( 2 );
}

$previous_encoding = mb_internal_encoding();
mb_internal_encoding( 'UTF-8' );

// Deliberately broken variant so the smoke test can prove detection fires.
$encode = static fn( int $cp ): string => \WP_HTML_Decoder::code_point_to_utf8_bytes( $cp );
if ( 'codepoint-surrogate-qmark' === getenv( 'ENCODING_FUZZ_FAULT' ) ) {
	$encode = static fn( int $cp ): string => ( $cp >= 0xD800 && $cp <= 0xDFFF )
		? '?'
		: \WP_HTML_Decoder::code_point_to_utf8_bytes( $cp );
}

// ---------------------------------------------------------------------
// 1. Exhaustive sweep over the entire code point domain.
// ---------------------------------------------------------------------
$replacement      = "\u{FFFD}";
$mismatches       = array();
$mismatch_count   = 0;
$oracle_conflicts = array();
$conflict_count   = 0;

for ( $cp = 0; $cp <= 0x10FFFF; $cp++ ) {
	$is_surrogate = $cp >= 0xD800 && $cp <= 0xDFFF;
	$expected     = $is_surrogate ? $replacement : Generator::encode_code_point( $cp );
	$got          = $encode( $cp );

	if ( $got !== $expected ) {
		++$mismatch_count;
		if ( count( $mismatches ) < 10 ) {
			$mismatches[] = sprintf( 'U+%04X: expected %s, got %s', $cp, bin2hex( $expected ), bin2hex( $got ) );
		}
	}

	// Cross-check the arithmetic oracle against mb_chr: `mb_chr()` returns
	// false exactly for surrogates, and the arithmetic encoder must match
	// it everywhere else (this would expose a bug shared between the
	// mb_chr-backed implementation and the arithmetic encoder).
	$mb = mb_chr( $cp, 'UTF-8' );
	if ( $is_surrogate ? false !== $mb : $mb !== $expected ) {
		++$conflict_count;
		if ( count( $oracle_conflicts ) < 10 ) {
			$oracle_conflicts[] = sprintf(
				'U+%04X: arithmetic %s, mb_chr %s',
				$cp,
				bin2hex( $expected ),
				is_string( $mb ) ? bin2hex( $mb ) : var_export( $mb, true )
			);
		}
	}
}

check(
	'all 1,114,112 code points encode correctly (surrogates → U+FFFD)',
	0 === $mismatch_count,
	"{$mismatch_count} mismatches, first " . count( $mismatches ) . ': ' . implode( '; ', $mismatches )
);
check(
	'arithmetic oracle and mb_chr agree on the whole domain',
	0 === $conflict_count,
	"{$conflict_count} conflicts, first " . count( $oracle_conflicts ) . ': ' . implode( '; ', $oracle_conflicts )
);

// ---------------------------------------------------------------------
// 2. Out-of-range values must yield the replacement character.
// ---------------------------------------------------------------------
$out_of_range_fails = array();
foreach ( array( -1, -0xE9, PHP_INT_MIN, 0x110000, 0x7FFFFFFF, PHP_INT_MAX ) as $cp ) {
	$got = $encode( $cp );
	if ( $replacement !== $got ) {
		$out_of_range_fails[] = sprintf( '%d: got %s', $cp, bin2hex( $got ) );
	}
}
check( 'out-of-range values yield U+FFFD', array() === $out_of_range_fails, implode( '; ', $out_of_range_fails ) );

// ---------------------------------------------------------------------
// 3. Documented finding: sensitivity to `mb_internal_encoding()`.
//
// Not a pass/fail gate on the WordPress contract — it pins the CURRENT
// (arguably buggy) behavior so any change is noticed. Under a non-UTF-8
// internal encoding the method returns non-UTF-8 bytes, contradicting
// its docblock. Fix would be `mb_chr( $code_point, 'UTF-8' )`.
// ---------------------------------------------------------------------
mb_internal_encoding( 'ISO-8859-1' );
$latin1_e9   = \WP_HTML_Decoder::code_point_to_utf8_bytes( 0xE9 );
$latin1_d800 = \WP_HTML_Decoder::code_point_to_utf8_bytes( 0xD800 );
mb_internal_encoding( 'UTF-8' );

check(
	'KNOWN ISSUE pin: mb_internal_encoding sensitivity unchanged (a FAIL here means upstream behavior changed — update or remove this pin)',
	"\xE9" === $latin1_e9 && $replacement === $latin1_d800,
	sprintf( 'U+00E9 → %s, U+D800 → %s', bin2hex( $latin1_e9 ), bin2hex( $latin1_d800 ) )
);
echo "NOTE  code_point_to_utf8_bytes() inherits mb_internal_encoding(); under ISO-8859-1 it returns raw latin1 bytes\n";
echo "NOTE  for mappable code points while still returning UTF-8 U+FFFD for invalid ones. WordPress sets the internal\n";
echo "NOTE  encoding from blog_charset, so non-UTF-8 sites are affected. Suggested fix: mb_chr( \$code_point, 'UTF-8' ).\n";

mb_internal_encoding( $previous_encoding );

echo $failed > 0 ? "\n{$failed} check(s) FAILED\n" : "\nAll checks passed\n";
exit( $failed > 0 ? 1 : 0 );
