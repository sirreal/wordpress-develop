<?php
/**
 * Self-test for the fuzz harness. Verifies that:
 *
 *  1. Every available oracle passes the known-answer battery.
 *  2. The real WordPress targets pass every check on the battery vectors.
 *  3. Deliberately broken target implementations ARE caught — the
 *     detection path is mutation-tested, so a silent harness bug cannot
 *     masquerade as "no findings".
 *  4. The generator is deterministic and produces the advertised mix of
 *     valid and invalid inputs across all strategies.
 *  5. A short real fuzz run completes.
 *
 * Exit codes: 0 pass, 1 fail.
 */

namespace EncodingFuzz;

require __DIR__ . '/../lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

Bootstrap::load_targets();

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

// ---------------------------------------------------------------------
// 1. Oracle battery: every oracle that loads must survive verification.
// ---------------------------------------------------------------------
$oracles = Oracles::build( array( 'python3', 'node' ) );
$events  = $oracles->drain_events();
$names   = $oracles->names();

check( 'mb oracle available', $oracles->has_required() );
check(
	'no oracle disabled by battery',
	array() === array_filter( $events, static fn( $e ) => 'oracle-disabled' === $e['type'] ),
	json_encode( $events )
);
check( 'at least one external oracle', in_array( 'python3', $names, true ) || in_array( 'node', $names, true ), implode( ',', $names ) );

// ---------------------------------------------------------------------
// 2. Real targets pass every check on the battery vectors.
// ---------------------------------------------------------------------
$checks          = new Checks( $oracles );
$battery_fails   = array();
$battery_vectors = array_merge(
	array_column( Oracles::battery(), 0 ),
	array_column( Oracles::encode_battery(), 0 ),
	array_column( Oracles::decode_battery(), 0 ),
	array_column( Oracles::noncharacter_battery(), 0 )
);
foreach ( $battery_vectors as $i => $bytes ) {
	foreach ( $checks->run( $bytes ) as $failure ) {
		$battery_fails[] = "vector {$i}: {$failure['signature']}";
	}
}
check( 'real targets clean on battery', array() === $battery_fails, implode( '; ', $battery_fails ) );

/*
 * Documented stance: `wp_has_noncharacters()` is undefined on ill-formed
 * input. On hosts with PCRE-u the public function answers false on ANY
 * ill-formed input (`preg_match` fails) while the fallback skips invalid
 * spans and reports the noncharacters around them. This regression
 * vector pins the divergence; if it ever changes, the semantics were
 * touched and the valid-input-only fuzzing policy must be revisited.
 */
$nonchar_probe = "\xC0\xEF\xBF\xBE"; // Invalid byte, then U+FFFE.
if ( _wp_can_use_pcre_u() ) {
	check(
		'documented wp_has_noncharacters divergence on ill-formed input unchanged',
		false === wp_has_noncharacters( $nonchar_probe ) && true === _wp_has_noncharacters_fallback( $nonchar_probe ),
		sprintf(
			'public: %s, fallback: %s',
			var_export( wp_has_noncharacters( $nonchar_probe ), true ),
			var_export( _wp_has_noncharacters_fallback( $nonchar_probe ), true )
		)
	);
} else {
	echo "SKIP documented wp_has_noncharacters divergence (no PCRE-u: public function aliases the fallback)\n";
}

// ---------------------------------------------------------------------
// 3. Broken implementations must be caught.
// ---------------------------------------------------------------------
$real_targets = array(
	'is_valid'        => 'wp_is_valid_utf8',
	'is_valid_fb'     => '_wp_is_valid_utf8_fallback',
	'scrub'           => 'wp_scrub_utf8',
	'scrub_fb'        => '_wp_scrub_utf8_fallback',
	'codepoint_count' => '_wp_utf8_codepoint_count',
	'utf8_encode_fb'  => '_wp_utf8_encode_fallback',
	'utf8_decode_fb'  => '_wp_utf8_decode_fallback',
	'has_nonchars'    => 'wp_has_noncharacters',
	'has_nonchars_fb' => '_wp_has_noncharacters_fallback',
);

/**
 * Runs every battery vector against a broken variant and reports which
 * checks fired.
 *
 * @return string[] Distinct check names observed.
 */
function broken_run( Oracles $oracles, array $real, array $vectors, array $overrides ): array {
	$checks = new Checks( $oracles, array_merge( $real, $overrides ) );
	$seen   = array();
	foreach ( $vectors as $bytes ) {
		foreach ( $checks->run( $bytes ) as $failure ) {
			$seen[ $failure['check'] ] = true;
		}
	}
	return array_keys( $seen );
}

// 3a. Validator that wrongly accepts a never-valid byte.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'is_valid_fb' => static fn( string $bytes ): bool => str_contains( $bytes, "\xC0" ) ? true : _wp_is_valid_utf8_fallback( $bytes ),
) );
check( 'catches validator accepting 0xC0', in_array( 'validity-mismatch', $seen, true ), implode( ',', $seen ) );

// 3b. Validator that wrongly rejects noncharacters (a plausible spec misreading).
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'is_valid' => static fn( string $bytes ): bool => wp_is_valid_utf8( $bytes ) && ! wp_has_noncharacters( $bytes ),
) );
check( 'catches validator rejecting noncharacters', in_array( 'validity-mismatch', $seen, true ), implode( ',', $seen ) );

// 3c. Scrubber that collapses adjacent replacement characters (one-FFFD-per-run
//     instead of one per maximal subpart).
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scrub_fb' => static fn( string $bytes ): string => (string) preg_replace( "/(\u{FFFD})+/u", "\u{FFFD}", _wp_scrub_utf8_fallback( $bytes ) ),
) );
check( 'catches non-maximal-subpart scrubber', in_array( 'scrub-mismatch', $seen, true ), implode( ',', $seen ) );

// 3d. Scrubber that passes invalid bytes through untouched.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scrub_fb' => static fn( string $bytes ): string => $bytes,
) );
check(
	'catches identity scrubber',
	in_array( 'scrub-mismatch', $seen, true ) && in_array( 'scrubbed-not-valid', $seen, true ),
	implode( ',', $seen )
);

// 3e. Scrubber that drops invalid bytes instead of replacing them.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scrub' => static fn( string $bytes ): string => str_replace( "\u{FFFD}", '', wp_scrub_utf8( $bytes ) ),
) );
check( 'catches byte-dropping scrubber', in_array( 'scrub-mismatch', $seen, true ), implode( ',', $seen ) );

// 3f. Code point counter that counts invalid bytes individually.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_count' => static fn( string $bytes ): int => _wp_utf8_codepoint_count( $bytes ) + ( wp_is_valid_utf8( $bytes ) ? 0 : 1 ),
) );
check( 'catches off-by-one code point count', in_array( 'codepoint-count-mismatch', $seen, true ), implode( ',', $seen ) );

// 3g. Throwing target is reported, not fatal.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'is_valid_fb' => static function ( string $bytes ): bool {
		throw new \RuntimeException( 'boom' );
	},
) );
check( 'reports throwing target', in_array( 'target-exception', $seen, true ), implode( ',', $seen ) );

// 3h. Encoder that confuses ISO-8859-1 with Windows-1252 (0x80 becomes '€').
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_encode_fb' => static fn( string $bytes ): string => str_replace( "\xC2\x80", "\xE2\x82\xAC", _wp_utf8_encode_fallback( $bytes ) ),
) );
check( 'catches cp1252-confused encoder', in_array( 'utf8-encode-mismatch', $seen, true ), implode( ',', $seen ) );

// 3i. Encoder that passes high bytes through raw (invalid UTF-8 output).
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_encode_fb' => static fn( string $bytes ): string => $bytes,
) );
check(
	'catches identity encoder',
	in_array( 'utf8-encode-mismatch', $seen, true ) && in_array( 'utf8-encode-not-valid', $seen, true ),
	implode( ',', $seen )
);

// 3j. Decoder that emits one '?' per invalid byte instead of per maximal
//     subpart (`E2 8C` becomes '??' instead of '?').
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => Targets::decode_per_invalid_byte( ... ),
) );
check( 'catches per-byte decoder', in_array( 'utf8-decode-mismatch', $seen, true ), implode( ',', $seen ) );

// 3k. Decoder that mangles a mappable code point on fully valid input.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => static fn( string $bytes ): string => str_replace( "\xFC", "\xFD", _wp_utf8_decode_fallback( $bytes ) ),
) );
check( 'catches decoder mangling valid input', in_array( 'utf8-decode-mismatch', $seen, true ), implode( ',', $seen ) );

// 3l. Decoder that drops U+0080 entirely; the encode→decode round trip
//     must restore every input byte string exactly.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => static fn( string $bytes ): string => str_replace( "\x80", '', _wp_utf8_decode_fallback( $bytes ) ),
) );
check( 'catches round-trip violation', in_array( 'utf8-round-trip-mismatch', $seen, true ), implode( ',', $seen ) );

// 3m. Encoder that returns null (the fallbacks are untyped, so a broken
//     variant can return non-strings without throwing); must be reported,
//     not silently skipped by every encode-side check.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_encode_fb' => static fn( string $bytes ) => null,
) );
check( 'catches null-returning encoder', in_array( 'target-bad-return', $seen, true ), implode( ',', $seen ) );

// 3n. Decoder that returns null only for some inputs; must be reported
//     from both the direct call and the round-trip path without crashing.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => static fn( string $bytes ) => str_contains( $bytes, "\x80" ) ? null : _wp_utf8_decode_fallback( $bytes ),
) );
check( 'catches sometimes-null decoder', in_array( 'target-bad-return', $seen, true ), implode( ',', $seen ) );

// 3o. Noncharacter detector that never finds anything.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'has_nonchars_fb' => static fn( string $text ): bool => false,
) );
check( 'catches blind noncharacter detector', in_array( 'noncharacters-mismatch', $seen, true ), implode( ',', $seen ) );

// 3p. Detector that misses the contiguous U+FDD0–U+FDEF block (the
//     plane-final pairs alone are a plausible spec misreading).
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'has_nonchars_fb' => Targets::nonchars_missing_fdd0_block( ... ),
) );
check( 'catches detector missing U+FDD0 block', in_array( 'noncharacters-mismatch', $seen, true ), implode( ',', $seen ) );

// 3q. Over-eager detector that flags U+FDCF, just below the block.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'has_nonchars' => Targets::nonchars_overeager( ... ),
) );
check( 'catches over-eager noncharacter detector', in_array( 'noncharacters-mismatch', $seen, true ), implode( ',', $seen ) );

// ---------------------------------------------------------------------
// 4. Generator determinism and mix.
// ---------------------------------------------------------------------
$a = ( new Generator( new Prng( '7:3' ), 65536 ) )->generate();
$b = ( new Generator( new Prng( '7:3' ), 65536 ) )->generate();
check( 'generator deterministic for (seed, case)', $a === $b );

$strategies = array();
$valid      = 0;
$invalid    = 0;
$total      = 2000;
for ( $i = 0; $i < $total; $i++ ) {
	$generated = ( new Generator( new Prng( "smoke:{$i}" ), 4096 ) )->generate();
	$strategies[ $generated['strategy'] ] = true;
	if ( mb_check_encoding( $generated['bytes'], 'UTF-8' ) ) {
		++$valid;
	} else {
		++$invalid;
	}
}
check( 'all 9 strategies appear', 9 === count( $strategies ), implode( ',', array_keys( $strategies ) ) );
check(
	"healthy valid/invalid mix ({$valid} valid, {$invalid} invalid of {$total})",
	$valid > $total / 10 && $invalid > $total / 10
);

// ---------------------------------------------------------------------
// 5. Short real fuzz run.
// ---------------------------------------------------------------------
$fuzz_failures = 0;
for ( $i = 0; $i < 300; $i++ ) {
	$generated = ( new Generator( new Prng( "smoke-run:{$i}" ), 8192 ) )->generate();
	$failures  = $checks->run( $generated['bytes'] );
	foreach ( $failures as $failure ) {
		++$fuzz_failures;
		echo "  finding: {$failure['signature']} on " . bin2hex( substr( $generated['bytes'], 0, 48 ) ) . "\n";
	}
}
check( '300-case fuzz run clean (real findings would also surface here)', 0 === $fuzz_failures );

// ---------------------------------------------------------------------
// 6. One-shot exhaustive companion test: must pass, and its detection
//    must provably fire (same mutation-testing rule as everything else).
// ---------------------------------------------------------------------
$exhaustive = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/code-point-to-utf8-exhaustive.php' );

exec( "{$exhaustive} 2>&1", $exh_output, $exh_code );
check( 'code-point-to-utf8 exhaustive test passes', 0 === $exh_code, implode( ' | ', array_slice( $exh_output, -3 ) ) );

exec( "ENCODING_FUZZ_FAULT=codepoint-surrogate-qmark {$exhaustive} 2>&1", $exh_fault_output, $exh_fault_code );
check( 'exhaustive test catches broken surrogate handling', 1 === $exh_fault_code, "exit {$exh_fault_code}" );

$oracles->shutdown();

echo $failed > 0 ? "\n{$failed} smoke check(s) FAILED\n" : "\nAll smoke checks passed\n";
exit( $failed > 0 ? 1 : 0 );
