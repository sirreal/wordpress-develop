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
 *  5. The deterministic short-boundary corpus is stable and clean.
 *  6. A short real fuzz run completes.
 *  7. The one-shot exhaustive companion test passes and catches its mutant.
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
 * Trunk aligned invalid-input behavior by making the public function search
 * for noncharacter UTF-8 byte sequences directly and deprecating the old
 * private fallback into a wrapper.
 */
$nonchar_probe = "\xC0\xEF\xBF\xBE"; // Invalid byte, then U+FFFE.
check(
	'wp_has_noncharacters detects noncharacters inside ill-formed input',
	true === wp_has_noncharacters( $nonchar_probe ) && true === _wp_has_noncharacters_fallback( $nonchar_probe ),
	sprintf(
		'public: %s, fallback: %s',
		var_export( wp_has_noncharacters( $nonchar_probe ), true ),
		var_export( _wp_has_noncharacters_fallback( $nonchar_probe ), true )
	)
);

$nonchar_absent_probe = "\xC0abc";
check(
	'wp_has_noncharacters ignores ill-formed input without noncharacters',
	false === wp_has_noncharacters( $nonchar_absent_probe ) && false === _wp_has_noncharacters_fallback( $nonchar_absent_probe ),
	sprintf(
		'public: %s, fallback: %s',
		var_export( wp_has_noncharacters( $nonchar_absent_probe ), true ),
		var_export( _wp_has_noncharacters_fallback( $nonchar_absent_probe ), true )
	)
);

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
	'mb_chr'          => '_mb_chr',
	'mb_ord'          => '_mb_ord',
	'codepoint_span'  => '_wp_utf8_codepoint_span',
	'mb_substr'       => '_mb_substr',
	'scan_utf8'       => '_wp_scan_utf8',
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

/**
 * Runs every battery vector through `Targets::resolve()` with a fault
 * environment variable, proving the CLI fault selector names are wired.
 *
 * @return string[] Distinct check names observed.
 */
function fault_run( Oracles $oracles, array $vectors, string $fault ): array {
	$previous_fault = getenv( 'ENCODING_FUZZ_FAULT' );
	putenv( "ENCODING_FUZZ_FAULT={$fault}" );

	try {
		$checks = new Checks( $oracles, Targets::resolve() );
		$seen   = array();
		foreach ( $vectors as $bytes ) {
			foreach ( $checks->run( $bytes ) as $failure ) {
				$seen[ $failure['check'] ] = true;
			}
		}
	} finally {
		if ( false === $previous_fault ) {
			putenv( 'ENCODING_FUZZ_FAULT' );
		} else {
			putenv( "ENCODING_FUZZ_FAULT={$previous_fault}" );
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

// 3f. Code point counter with a simple off-by-one drift on invalid input.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_count' => static fn( string $bytes, ?int $offset = 0, ?int $length = PHP_INT_MAX ): int => _wp_utf8_codepoint_count( $bytes, $offset, $length ) + ( wp_is_valid_utf8( $bytes ) ? 0 : 1 ),
) );
check( 'catches off-by-one code point count', in_array( 'codepoint-count-mismatch', $seen, true ), implode( ',', $seen ) );

// 3g. Code point counter that counts each byte in invalid maximal subparts.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_count' => Targets::codepoint_count_invalid_bytes( ... ),
) );
check( 'catches invalid-byte-counting code point count', in_array( 'codepoint-count-mismatch', $seen, true ), implode( ',', $seen ) );

// 3h. Bounded counter that stops one byte early at the range end.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_count' => Targets::codepoint_count_range_minus_one( ... ),
) );
check( 'catches range-end off-by-one code point count', in_array( 'codepoint-count-mismatch', $seen, true ), implode( ',', $seen ) );

// 3i. Bounded counter that ignores the byte offset.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_count' => Targets::codepoint_count_ignore_offset( ... ),
) );
check( 'catches byte-offset-ignoring code point count', in_array( 'codepoint-count-mismatch', $seen, true ), implode( ',', $seen ) );

// 3j. Throwing target is reported, not fatal.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'is_valid_fb' => static function ( string $bytes ): bool {
		throw new \RuntimeException( 'boom' );
	},
) );
check( 'reports throwing target', in_array( 'target-exception', $seen, true ), implode( ',', $seen ) );

// 3k. Encoder that confuses ISO-8859-1 with Windows-1252 (0x80 becomes '€').
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_encode_fb' => static fn( string $bytes ): string => str_replace( "\xC2\x80", "\xE2\x82\xAC", _wp_utf8_encode_fallback( $bytes ) ),
) );
check( 'catches cp1252-confused encoder', in_array( 'utf8-encode-mismatch', $seen, true ), implode( ',', $seen ) );

// 3l. Encoder that passes high bytes through raw (invalid UTF-8 output).
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_encode_fb' => static fn( string $bytes ): string => $bytes,
) );
check(
	'catches identity encoder',
	in_array( 'utf8-encode-mismatch', $seen, true ) && in_array( 'utf8-encode-not-valid', $seen, true ),
	implode( ',', $seen )
);

// 3m. Decoder that emits one '?' per invalid byte instead of per maximal
//     subpart (`E2 8C` becomes '??' instead of '?').
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => Targets::decode_per_invalid_byte( ... ),
) );
check( 'catches per-byte decoder', in_array( 'utf8-decode-mismatch', $seen, true ), implode( ',', $seen ) );

// 3n. Decoder that mangles a mappable code point on fully valid input.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => static fn( string $bytes ): string => str_replace( "\xFC", "\xFD", _wp_utf8_decode_fallback( $bytes ) ),
) );
check( 'catches decoder mangling valid input', in_array( 'utf8-decode-mismatch', $seen, true ), implode( ',', $seen ) );

// 3o. Decoder that drops U+0080 entirely; the encode→decode round trip
//     must restore every input byte string exactly.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => static fn( string $bytes ): string => str_replace( "\x80", '', _wp_utf8_decode_fallback( $bytes ) ),
) );
check( 'catches round-trip violation', in_array( 'utf8-round-trip-mismatch', $seen, true ), implode( ',', $seen ) );

// 3p. Encoder that returns null (the fallbacks are untyped, so a broken
//     variant can return non-strings without throwing); must be reported,
//     not silently skipped by every encode-side check.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_encode_fb' => static fn( string $bytes ) => null,
) );
check( 'catches null-returning encoder', in_array( 'target-bad-return', $seen, true ), implode( ',', $seen ) );

// 3q. Decoder that returns null only for some inputs; must be reported
//     from both the direct call and the round-trip path without crashing.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'utf8_decode_fb' => static fn( string $bytes ) => str_contains( $bytes, "\x80" ) ? null : _wp_utf8_decode_fallback( $bytes ),
) );
check( 'catches sometimes-null decoder', in_array( 'target-bad-return', $seen, true ), implode( ',', $seen ) );

// 3r. Noncharacter detector that never finds anything.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'has_nonchars_fb' => static fn( string $text ): bool => false,
) );
check( 'catches blind noncharacter detector', in_array( 'noncharacters-mismatch', $seen, true ), implode( ',', $seen ) );

// 3s. Detector that misses the contiguous U+FDD0–U+FDEF block (the
//     plane-final pairs alone are a plausible spec misreading).
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'has_nonchars_fb' => Targets::nonchars_missing_fdd0_block( ... ),
) );
check( 'catches detector missing U+FDD0 block', in_array( 'noncharacters-mismatch', $seen, true ), implode( ',', $seen ) );

// 3t. Over-eager detector that flags U+FDCF, just below the block.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'has_nonchars' => Targets::nonchars_overeager( ... ),
) );
check( 'catches over-eager noncharacter detector', in_array( 'noncharacters-mismatch', $seen, true ), implode( ',', $seen ) );

// 3u. Character encoder that confuses U+0080 with Windows-1252's euro sign.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'mb_chr' => static fn( int $code_point ) => 0x80 === $code_point ? "\xE2\x82\xAC" : _mb_chr( $code_point ),
) );
check( 'catches cp1252-confused _mb_chr', in_array( 'mb-chr-mismatch', $seen, true ), implode( ',', $seen ) );

// 3v. Character decoder that accepts an invalid leading C0 byte.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'mb_ord' => static fn( string $bytes ) => str_starts_with( $bytes, "\xC0" ) ? 0 : _mb_ord( $bytes ),
) );
check( 'catches invalid-accepting _mb_ord', in_array( 'mb-ord-mismatch', $seen, true ), implode( ',', $seen ) );

// 3w. Code point span that reports one extra byte.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_span' => Targets::codepoint_span_off_by_one( ... ),
) );
check( 'catches off-by-one code point span', in_array( 'codepoint-span-mismatch', $seen, true ), implode( ',', $seen ) );

// 3x. Code point span that treats invalid maximal subparts as one code
//     point per byte instead of one code point per maximal subpart.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_span' => Targets::codepoint_span_counts_invalid_bytes( ... ),
) );
check( 'catches byte-counted invalid code point span', in_array( 'codepoint-span-mismatch', $seen, true ), implode( ',', $seen ) );

// 3y. Code point span that returns the right byte span but corrupts the
//     by-reference found count.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_span' => Targets::codepoint_span_found_max( ... ),
) );
check( 'catches wrong code point span found count', in_array( 'codepoint-span-found-mismatch', $seen, true ), implode( ',', $seen ) );

// 3z. Code point span that leaves found_code_points stale on empty spans.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'codepoint_span' => Targets::codepoint_span_stale_empty_found( ... ),
) );
check( 'catches stale empty code point span found count', in_array( 'codepoint-span-found-mismatch', $seen, true ), implode( ',', $seen ) );

// 3aa. UTF-8 substring that treats character offsets as byte offsets.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'mb_substr' => Targets::mb_substr_byte_level( ... ),
) );
check( 'catches byte-offset _mb_substr', in_array( 'mb-substr-mismatch', $seen, true ), implode( ',', $seen ) );

// 3ab. UTF-8 substring that slices scrubbed text, losing original invalid bytes.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'mb_substr' => Targets::mb_substr_scrub_invalid( ... ),
) );
check( 'catches scrubbed-input _mb_substr', in_array( 'mb-substr-mismatch', $seen, true ), implode( ',', $seen ) );

// 3ac. UTF-8 substring that ignores negative length semantics.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'mb_substr' => Targets::mb_substr_no_negative_length( ... ),
) );
check( 'catches negative-length _mb_substr', in_array( 'mb-substr-mismatch', $seen, true ), implode( ',', $seen ) );

// 3ad. Non-UTF-8 substring must fall back to byte-level substr().
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'mb_substr' => Targets::mb_substr_force_utf8( ... ),
) );
check( 'catches non-UTF-8 _mb_substr fallback drift', in_array( 'mb-substr-mismatch', $seen, true ), implode( ',', $seen ) );

// 3ae. Bounded scan that ignores max_bytes.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scan_utf8' => Targets::scan_utf8_ignore_max_bytes( ... ),
) );
check( 'catches max_bytes-ignoring scan', in_array( 'scan-utf8-mismatch', $seen, true ), implode( ',', $seen ) );

// 3af. Bounded scan that leaks noncharacters from outside the scanned region.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scan_utf8' => Targets::scan_utf8_noncharacters_leak( ... ),
) );
check( 'catches noncharacter-leaking scan', in_array( 'scan-utf8-mismatch', $seen, true ), implode( ',', $seen ) );

// 3ag. Bounded scan that misses noncharacters inside the scanned region.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scan_utf8' => Targets::scan_utf8_miss_noncharacters( ... ),
) );
check( 'catches noncharacter-missing scan', in_array( 'scan-utf8-mismatch', $seen, true ), implode( ',', $seen ) );

// 3ah. Bounded scan whose ASCII fast path overruns max_code_points.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scan_utf8' => Targets::scan_utf8_ascii_overrun( ... ),
) );
check( 'catches ASCII-overrunning scan', in_array( 'scan-utf8-mismatch', $seen, true ), implode( ',', $seen ) );

// 3ai. Bounded scan that preserves a stale noncharacter flag.
$seen = broken_run( $oracles, $real_targets, $battery_vectors, array(
	'scan_utf8' => Targets::scan_utf8_stale_noncharacters( ... ),
) );
check( 'catches stale noncharacter scan flag', in_array( 'scan-utf8-mismatch', $seen, true ), implode( ',', $seen ) );

foreach ( array( 'scan-ignore-bytes', 'scan-nonchars-leak', 'scan-miss-nonchars', 'scan-ascii-overrun', 'scan-stale-nonchars' ) as $fault ) {
	$seen = fault_run( $oracles, $battery_vectors, $fault );
	check( "fault selector {$fault} is wired", in_array( 'scan-utf8-mismatch', $seen, true ), implode( ',', $seen ) );
}

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
// 5. Deterministic short-boundary corpus.
// ---------------------------------------------------------------------
$corpus_cases      = Corpus::short_boundary_cases();
$corpus_categories = array();
foreach ( $corpus_cases as $entry ) {
	$category                       = explode( ':', $entry['label'], 2 )[0];
	$corpus_categories[ $category ] = true;
}

$expected_categories = array(
	'lead',
	'two-second',
	'three-second',
	'three-third',
	'four-second',
	'four-third',
	'four-fourth',
	'adjacent-invalid',
	'sandwich',
	'truncation',
	'noncharacter-boundary',
);
$missing_categories  = array_values( array_diff( $expected_categories, array_keys( $corpus_categories ) ) );
check(
	'short-boundary corpus has broad deterministic coverage',
	1133 === count( $corpus_cases ) && array() === $missing_categories,
	'count ' . count( $corpus_cases ) . ', missing ' . implode( ',', $missing_categories )
);

$corpus_fingerprint = static function ( array $cases ): string {
	$parts = array();
	foreach ( $cases as $entry ) {
		$parts[] = $entry['label'] . '=' . bin2hex( $entry['bytes'] );
	}
	return hash( 'sha256', implode( "\n", $parts ) );
};
check(
	'short-boundary corpus deterministic',
	'93f63dec5d9534e0ed1db643d5eb0596ececb0807cc3fb92cc6fe21fc4c60fbd' === $corpus_fingerprint( $corpus_cases )
);

$corpus_failures = 0;
foreach ( $corpus_cases as $entry ) {
	$failures = $checks->run( $entry['bytes'] );
	foreach ( $failures as $failure ) {
		++$corpus_failures;
		echo "  corpus finding: {$failure['signature']} on {$entry['label']} " . bin2hex( $entry['bytes'] ) . "\n";
	}
}
check( 'short-boundary corpus clean (' . count( $corpus_cases ) . ' cases)', 0 === $corpus_failures );

$corpus_command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../corpus.php' ) . ' --external none';
exec( "{$corpus_command} 2>&1", $corpus_output, $corpus_code );
$corpus_start = null;
$corpus_done  = null;
foreach ( $corpus_output as $line ) {
	$record = json_decode( $line, true );
	if ( ! is_array( $record ) ) {
		continue;
	}

	if ( 'start' === ( $record['type'] ?? null ) ) {
		$corpus_start = $record;
	} elseif ( 'done' === ( $record['type'] ?? null ) ) {
		$corpus_done = $record;
	}
}
check(
	'short-boundary corpus CLI clean',
	0 === $corpus_code &&
	is_array( $corpus_start ) &&
	is_array( $corpus_done ) &&
	'start' === ( $corpus_start['type'] ?? null ) &&
	'done' === ( $corpus_done['type'] ?? null ) &&
	1133 === ( $corpus_start['cases'] ?? null ) &&
	1133 === ( $corpus_done['stats']['cases'] ?? null ) &&
	0 === ( $corpus_done['stats']['failures'] ?? null ),
	implode( ' | ', array_slice( $corpus_output, -3 ) )
);

// ---------------------------------------------------------------------
// 6. Short real fuzz run.
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
// 7. One-shot exhaustive companion test: must pass, and its detection
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
