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
$checks        = new Checks( $oracles );
$battery_fails = array();
foreach ( Oracles::battery() as $i => $vector ) {
	foreach ( $checks->run( $vector[0] ) as $failure ) {
		$battery_fails[] = "vector {$i}: {$failure['signature']}";
	}
}
check( 'real targets clean on battery', array() === $battery_fails, implode( '; ', $battery_fails ) );

// ---------------------------------------------------------------------
// 3. Broken implementations must be caught.
// ---------------------------------------------------------------------
$real_targets = array(
	'is_valid'        => 'wp_is_valid_utf8',
	'is_valid_fb'     => '_wp_is_valid_utf8_fallback',
	'scrub'           => 'wp_scrub_utf8',
	'scrub_fb'        => '_wp_scrub_utf8_fallback',
	'codepoint_count' => '_wp_utf8_codepoint_count',
);

/**
 * Runs the battery against a broken variant and reports which checks fired.
 *
 * @return string[] Distinct check names observed.
 */
function broken_run( Oracles $oracles, array $real, array $overrides ): array {
	$checks = new Checks( $oracles, array_merge( $real, $overrides ) );
	$seen   = array();
	foreach ( Oracles::battery() as $vector ) {
		foreach ( $checks->run( $vector[0] ) as $failure ) {
			$seen[ $failure['check'] ] = true;
		}
	}
	return array_keys( $seen );
}

// 3a. Validator that wrongly accepts a never-valid byte.
$seen = broken_run( $oracles, $real_targets, array(
	'is_valid_fb' => static fn( string $bytes ): bool => str_contains( $bytes, "\xC0" ) ? true : _wp_is_valid_utf8_fallback( $bytes ),
) );
check( 'catches validator accepting 0xC0', in_array( 'validity-mismatch', $seen, true ), implode( ',', $seen ) );

// 3b. Validator that wrongly rejects noncharacters (a plausible spec misreading).
$seen = broken_run( $oracles, $real_targets, array(
	'is_valid' => static fn( string $bytes ): bool => wp_is_valid_utf8( $bytes ) && ! wp_has_noncharacters( $bytes ),
) );
check( 'catches validator rejecting noncharacters', in_array( 'validity-mismatch', $seen, true ), implode( ',', $seen ) );

// 3c. Scrubber that collapses adjacent replacement characters (one-FFFD-per-run
//     instead of one per maximal subpart).
$seen = broken_run( $oracles, $real_targets, array(
	'scrub_fb' => static fn( string $bytes ): string => (string) preg_replace( "/(\u{FFFD})+/u", "\u{FFFD}", _wp_scrub_utf8_fallback( $bytes ) ),
) );
check( 'catches non-maximal-subpart scrubber', in_array( 'scrub-mismatch', $seen, true ), implode( ',', $seen ) );

// 3d. Scrubber that passes invalid bytes through untouched.
$seen = broken_run( $oracles, $real_targets, array(
	'scrub_fb' => static fn( string $bytes ): string => $bytes,
) );
check(
	'catches identity scrubber',
	in_array( 'scrub-mismatch', $seen, true ) && in_array( 'scrubbed-not-valid', $seen, true ),
	implode( ',', $seen )
);

// 3e. Scrubber that drops invalid bytes instead of replacing them.
$seen = broken_run( $oracles, $real_targets, array(
	'scrub' => static fn( string $bytes ): string => str_replace( "\u{FFFD}", '', wp_scrub_utf8( $bytes ) ),
) );
check( 'catches byte-dropping scrubber', in_array( 'scrub-mismatch', $seen, true ), implode( ',', $seen ) );

// 3f. Code point counter that counts invalid bytes individually.
$seen = broken_run( $oracles, $real_targets, array(
	'codepoint_count' => static fn( string $bytes ): int => _wp_utf8_codepoint_count( $bytes ) + ( wp_is_valid_utf8( $bytes ) ? 0 : 1 ),
) );
check( 'catches off-by-one code point count', in_array( 'codepoint-count-mismatch', $seen, true ), implode( ',', $seen ) );

// 3g. Throwing target is reported, not fatal.
$seen = broken_run( $oracles, $real_targets, array(
	'is_valid_fb' => static function ( string $bytes ): bool {
		throw new \RuntimeException( 'boom' );
	},
) );
check( 'reports throwing target', in_array( 'target-exception', $seen, true ), implode( ',', $seen ) );

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

$oracles->shutdown();

echo $failed > 0 ? "\n{$failed} smoke check(s) FAILED\n" : "\nAll smoke checks passed\n";
exit( $failed > 0 ? 1 : 0 );
