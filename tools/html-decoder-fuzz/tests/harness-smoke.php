<?php
/**
 * Self-test for the WP_HTML_Decoder fuzz harness.
 *
 * Exit codes: 0 pass, 1 fail.
 */

namespace HtmlDecoderFuzz;

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

/**
 * @return array{code: int, stdout: string, stderr: string}
 */
function run_process( array $command, array $env = array() ): array {
	$process = proc_open(
		$command,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		Bootstrap::repo_root(),
		array_merge( getenv() ?: array(), $env )
	);

	if ( ! is_resource( $process ) ) {
		return array(
			'code'   => 127,
			'stdout' => '',
			'stderr' => 'proc_open failed',
		);
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array(
		'code'   => proc_close( $process ),
		'stdout' => (string) $stdout,
		'stderr' => (string) $stderr,
	);
}

function remove_tree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$items = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

/**
 * @return array<int, array{start: mixed, cases: mixed}>
 */
function summary_start_windows( string $dir, string $mode ): array {
	$summary = is_file( $dir . '/summary.ndjson' )
		? file( $dir . '/summary.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES )
		: array();
	$windows = array();

	if ( is_array( $summary ) ) {
		foreach ( $summary as $line ) {
			$record = json_decode( $line, true );
			if ( is_array( $record ) && 'start' === ( $record['type'] ?? null ) && $mode === ( $record['mode'] ?? null ) ) {
				$windows[] = array(
					'start' => $record['start_case'] ?? null,
					'cases' => $record['cases'] ?? null,
				);
			}
		}
	}

	usort(
		$windows,
		static fn( array $a, array $b ): int => ( $a['start'] ?? -1 ) <=> ( $b['start'] ?? -1 )
	);

	return $windows;
}

function start_windows_are_distinct( array $windows, int $cases_per_batch ): bool {
	if ( count( $windows ) < 2 ) {
		return false;
	}

	$previous_window_end = null;
	foreach ( $windows as $window ) {
		if ( ! is_int( $window['start'] ) || $cases_per_batch !== $window['cases'] || 0 !== $window['start'] % $cases_per_batch || ( null !== $previous_window_end && $window['start'] < $previous_window_end ) ) {
			return false;
		}
		$previous_window_end = $window['start'] + $window['cases'];
	}

	return true;
}

$oracles = Oracles::build();
$events  = $oracles->drain_events();
$skip_c1_fault_seed = 2;
$skip_c1_fault_case = 36;

check( 'required oracles available', $oracles->has_required(), json_encode( $events ) );
check( 'secondary entity-decode oracle available', in_array( 'entity-decode', $oracles->names(), true ), implode( ',', $oracles->names() ) );
check(
	'no oracle disabled by battery',
	array() === array_filter( $events, static fn( $e ) => 'oracle-disabled' === $e['type'] ),
	json_encode( $events )
);

$checks        = new Checks( $oracles );
$battery_fails = array();
foreach ( Oracles::battery() as $i => $vector ) {
	list( $context, $payload ) = $vector;
	foreach ( $checks->run( $context, $payload ) as $failure ) {
		$battery_fails[] = "vector {$i}: {$failure['signature']}";
	}
}
check( 'real targets clean on oracle battery', array() === $battery_fails, implode( '; ', $battery_fails ) );

$real_targets = Targets::real();

/**
 * @return string[] Distinct check names observed.
 */
function broken_run( Oracles $oracles, array $real_targets, array $overrides ): array {
	$checks = new Checks( $oracles, array_merge( $real_targets, $overrides ) );
	$seen   = array();

	$cases = array_merge(
		Oracles::battery(),
		array(
			array( 'text', 'a&amp;b' ),
			array( 'attribute', '&notx' ),
			array( 'attribute', 'jav' ),
			array( 'attribute', 'javascript&colon;alert(1)' ),
			array( 'attribute', '&nvlt;tail' ),
		)
	);

	foreach ( $cases as $case ) {
		foreach ( $checks->run( $case[0], $case[1] ) as $failure ) {
			$seen[ $failure['check'] ] = true;
		}
	}

	return array_keys( $seen );
}

/**
 * @return string[] Distinct check names observed.
 */
function fault_run( Oracles $oracles, string $fault, string $payload = 'javascript&colon;alert(1)', string $context = 'attribute' ): array {
	$old_fault = getenv( 'HTML_DECODER_FUZZ_FAULT' );
	putenv( "HTML_DECODER_FUZZ_FAULT={$fault}" );

	try {
		$checks = new Checks( $oracles, Targets::resolve() );
		$seen   = array();

		foreach ( $checks->run( $context, $payload ) as $failure ) {
			$seen[ $failure['check'] ] = true;
		}

		return array_keys( $seen );
	} finally {
		if ( false === $old_fault ) {
			putenv( 'HTML_DECODER_FUZZ_FAULT' );
		} else {
			putenv( "HTML_DECODER_FUZZ_FAULT={$old_fault}" );
		}
	}
}

/**
 * @return string[] Distinct check names observed.
 */
function fault_run_without_oracle( Oracles $oracles, string $fault, string $payload ): array {
	$old_fault = getenv( 'HTML_DECODER_FUZZ_FAULT' );
	putenv( "HTML_DECODER_FUZZ_FAULT={$fault}" );

	try {
		$checks = new Checks( $oracles, Targets::resolve() );
		$seen   = array();

		foreach ( $checks->run_without_oracle( 'both', $payload ) as $failure ) {
			$seen[ $failure['check'] ] = true;
		}

		return array_keys( $seen );
	} finally {
		if ( false === $old_fault ) {
			putenv( 'HTML_DECODER_FUZZ_FAULT' );
		} else {
			putenv( "HTML_DECODER_FUZZ_FAULT={$old_fault}" );
		}
	}
}

/**
 * @return string[] Distinct check names observed.
 */
function broken_oracle_free_run( Oracles $oracles, array $real_targets, array $overrides ): array {
	$checks = new Checks( $oracles, array_merge( $real_targets, $overrides ) );
	$seen   = array();
	$cases  = array(
		array( 'both', "raw\x00bytes" ),
		array( 'both', "\xFF\xFE<\"\r" ),
		array( 'both', "a&notx\x00z" ),
	);

	foreach ( $cases as $case ) {
		foreach ( $checks->run_without_oracle( $case[0], $case[1] ) as $failure ) {
			$seen[ $failure['check'] ] = true;
		}
	}

	return array_keys( $seen );
}

function reference_at_eof_shape( string $payload ): ?string {
	if ( 1 === preg_match( '/&\z/', $payload ) ) {
		return 'bare-introducer';
	}
	if ( 1 === preg_match( '/&#(?:[xX])?\z/', $payload ) ) {
		return 'partial-numeric-introducer';
	}
	if ( 1 === preg_match( '/&#[0-9]+\z/', $payload ) ) {
		return 'decimal-digits';
	}
	if ( 1 === preg_match( '/&#[xX][0-9A-Fa-f]+\z/', $payload ) ) {
		return 'hex-digits';
	}
	if ( 1 === preg_match( '/&[A-Za-z][A-Za-z0-9]*\z/', $payload ) ) {
		return 'named-prefix';
	}
	return null;
}

/**
 * @return array<string, true>
 */
function numeric_reference_ranges( string $payload ): array {
	$ranges = array();
	$match_count = preg_match_all( '/&#(?:([xX])([0-9A-Fa-f]+)|([0-9]+));?/', $payload, $matches, PREG_SET_ORDER );
	if ( false === $match_count || 0 === $match_count ) {
		return $ranges;
	}

	foreach ( $matches as $match ) {
		$is_hex    = '' !== ( $match[1] ?? '' );
		$digits    = $is_hex ? $match[2] : $match[3];
		$base      = $is_hex ? 16 : 10;
		$max_digits = $is_hex ? 6 : 7;
		$zero_count = strspn( $digits, '0' );
		$significant_digits = substr( $digits, $zero_count );

		if ( '' === $significant_digits ) {
			$ranges['zero-only'] = true;
			continue;
		}

		if ( strlen( $significant_digits ) > $max_digits ) {
			$ranges['digit-count-overflow'] = true;
			continue;
		}

		$value = intval( $significant_digits, $base );
		if ( $value <= 0x1F ) {
			$ranges['c0-control'] = true;
		} elseif ( $value >= 0x80 && $value <= 0x9F ) {
			$ranges['c1-control'] = true;
		} elseif ( $value >= 0xA0 && $value <= 0xD7FF ) {
			$ranges['bmp-pre-surrogate'] = true;
		} elseif ( $value >= 0xD800 && $value <= 0xDFFF ) {
			$ranges['surrogate'] = true;
		} elseif ( ( $value >= 0xFDD0 && $value <= 0xFDEF ) || 0xFFFE === $value || 0xFFFF === $value ) {
			$ranges['bmp-noncharacter'] = true;
		} elseif ( $value >= 0xE000 && $value <= 0xFFFD ) {
			$ranges['bmp-post-surrogate'] = true;
		} elseif ( $value >= 0x1FFFE && $value <= 0x10FFFF && ( $value & 0xFFFF ) >= 0xFFFE ) {
			$ranges['plane-noncharacter'] = true;
		} elseif ( $value > 0x10FFFF ) {
			$ranges['above-unicode-legal-digits'] = true;
		} elseif ( $value >= 0x10000 ) {
			$ranges['astral'] = true;
		}
	}

	return $ranges;
}

/**
 * @param string[] $names
 * @return string[]
 */
function name_sweep_base_names( array $names ): array {
	$base_names = array();
	foreach ( $names as $name ) {
		$base = rtrim( $name, ';' );
		if ( '' !== $base ) {
			$base_names[ $base ] = true;
		}
	}
	return array_keys( $base_names );
}

/**
 * @return string[]
 */
function legacy_follower_sweep_followers(): array {
	$followers = array();

	for ( $byte = 1; $byte <= 0x7F; $byte++ ) {
		if ( in_array( $byte, array( 0x0D, 0x22, 0x3C ), true ) ) {
			continue;
		}
		$followers[] = chr( $byte );
	}

	for ( $lead = 0xC2; $lead <= 0xF4; $lead++ ) {
		if ( $lead < 0xE0 ) {
			$followers[] = chr( $lead ) . "\x80";
		} elseif ( 0xE0 === $lead ) {
			$followers[] = "\xE0\xA0\x80";
		} elseif ( $lead < 0xF0 ) {
			$followers[] = chr( $lead ) . "\x80\x80";
		} elseif ( 0xF0 === $lead ) {
			$followers[] = "\xF0\x90\x80\x80";
		} elseif ( $lead < 0xF4 ) {
			$followers[] = chr( $lead ) . "\x80\x80\x80";
		} else {
			$followers[] = "\xF4\x80\x80\x80";
		}
	}

	for ( $continuation = 0x80; $continuation <= 0xBF; $continuation++ ) {
		$followers[] = "\xC2" . chr( $continuation );
	}

	return array_values( array_unique( $followers ) );
}

/**
 * @return string[]
 */
function prefix_family_sweep_references(): array {
	return array(
		'not',
		'not;',
		'notin;',
		'notinva;',
		'ngt;',
		'nGt;',
		'nGtv;',
		'nge;',
		'ngeq;',
		'ngeqq;',
	);
}

/**
 * @return string[]
 */
function prefix_family_sweep_followers(): array {
	return array( '', 'x', 'X', '0', '=', "\u{00E9}" );
}

/**
 * @param string[] $base_names
 * @return array<int, array{reference: string, split: int, follower: string}>
 */
function prefix_family_sweep_cases( array $base_names ): array {
	$base_set = array_fill_keys( $base_names, true );
	$cases    = array();

	foreach ( prefix_family_sweep_references() as $reference ) {
		if ( ! isset( $base_set[ rtrim( $reference, ';' ) ] ) ) {
			continue;
		}

		$full_reference = '&' . $reference;
		for ( $split = 1; $split < strlen( $full_reference ); $split++ ) {
			foreach ( prefix_family_sweep_followers() as $follower ) {
				$cases[] = array(
					'reference' => $full_reference,
					'split'     => $split,
					'follower'  => $follower,
				);
			}
		}
	}

	return $cases;
}

/**
 * @return array<int, array{shape: string, payload: string, prefix?: string, name?: string}>
 */
function token_map_sweep_cases(): array {
	$method = new \ReflectionMethod( Generator::class, 'token_map_sweep_cases' );
	$method->setAccessible( true );
	return $method->invoke( null );
}

/**
 * @return string[]
 */
function numeric_boundary_sweep_cases(): array {
	$cases = array();
	foreach ( array( 'decimal', 'hex-lower', 'hex-upper', 'hex-mixed' ) as $kind ) {
		$is_decimal = 'decimal' === $kind;
		$max_digits = $is_decimal ? 7 : 6;
		foreach ( array( $max_digits, $max_digits + 1 ) as $digit_count ) {
			foreach ( array( false, true ) as $leading_zero ) {
				foreach ( array( false, true ) as $semicolon ) {
					$cases[] = numeric_boundary_reference( $kind, $digit_count, $leading_zero, $semicolon );
				}
			}
		}
	}

	return array_values( array_unique( $cases ) );
}

function numeric_boundary_reference( string $kind, int $digit_count, bool $leading_zero, bool $semicolon ): string {
	if ( 'decimal' === $kind ) {
		$prefix = '&#';
		$digits = 7 === $digit_count ? '1114111' : substr( str_repeat( '9', $digit_count ), 0, $digit_count );
	} else {
		$prefix = 'hex-upper' === $kind ? '&#X' : '&#x';
		$digits = 6 === $digit_count ? '10ffee' : substr( str_repeat( 'abcdef', (int) ceil( $digit_count / 6 ) ), 0, $digit_count );
		if ( 'hex-upper' === $kind ) {
			$digits = strtoupper( $digits );
		} elseif ( 'hex-mixed' === $kind ) {
			$chars = str_split( $digits );
			foreach ( $chars as $i => $char ) {
				if ( 0 === $i % 2 ) {
					$chars[ $i ] = strtoupper( $char );
				}
			}
			$digits = implode( '', $chars );
		}
	}

	if ( $leading_zero ) {
		$digits = '0' . $digits;
	}

	return $prefix . $digits . ( $semicolon ? ';' : '' );
}

/**
 * @return array{base: string, significant_digits: int, leading_zero: bool, semicolon: bool, mixed_hex: bool}
 */
function numeric_boundary_shape( string $payload ): array {
	if ( 1 !== preg_match( '/^&#(?:(x|X)([0-9A-Fa-f]+)|([0-9]+))(;?)$/', $payload, $match ) ) {
		return array(
			'base'               => 'invalid',
			'significant_digits' => 0,
			'leading_zero'       => false,
			'semicolon'          => false,
			'mixed_hex'          => false,
		);
	}

	$is_hex = '' !== ( $match[1] ?? '' );
	$digits = $is_hex ? $match[2] : $match[3];
	$significant = substr( $digits, strspn( $digits, '0' ) );
	$letters = preg_replace( '/[^A-Fa-f]/', '', $digits );

	return array(
		'base'               => $is_hex ? 'hex' : 'decimal',
		'significant_digits' => strlen( $significant ),
		'leading_zero'       => strlen( $digits ) > strlen( $significant ),
		'semicolon'          => ';' === ( $match[4] ?? '' ),
		'mixed_hex'          => $is_hex && '' !== $letters && strtolower( $letters ) !== $letters && strtoupper( $letters ) !== $letters,
	);
}

/**
 * @return string[]
 */
function attribute_prefix_smoke_targets(): array {
	return array(
		'javascript:',
		'JaVaScRiPt:',
		'http://',
		'https://',
		'mailto:user@example.com',
		'data:text/plain,',
		'urn:wp:html5:',
		'ftp://',
	);
}

/**
 * @return string[]
 */
function attribute_prefix_encoding_forms( string $payload ): array {
	$forms = array();

	if ( '' !== $payload && '&' !== $payload[0] ) {
		$forms['literal'] = true;
	}
	if ( 1 === preg_match( '/&#[1-9][0-9]*;?/', $payload ) ) {
		$forms['decimal'] = true;
	}
	if ( 1 === preg_match( '/&#0+[0-9]+;?/', $payload ) ) {
		$forms['leading-zero'] = true;
	}
	if ( 1 === preg_match( '/&#[xX][0-9A-Fa-f]+;?/', $payload ) ) {
		$forms['hex'] = true;
	}
	if ( 1 === preg_match( '/(?:&#[0-9]+(?:$|[^0-9;])|&#[xX][0-9A-Fa-f]+(?:$|[^0-9A-Fa-f;]))/', $payload ) ) {
		$forms['semicolonless'] = true;
	}

	return array_keys( $forms );
}

/**
 * @return string[]
 */
function expected_weighted_strategies(): array {
	return array(
		'adjacency',
		'attribute-discriminator',
		'attribute-prefix',
		'case-mangled-name',
		'composition',
		'lookalike',
		'multibyte-around',
		'named-exact',
		'named-missing-semi',
		'numeric',
		'plain-no-amp',
		'reference-at-eof',
		'truncation-sweep',
	);
}

/**
 * @return string[]
 */
function expected_corpus_strategies(): array {
	return array(
		'corpus-byte-perturb',
		'corpus-reference-duplication',
		'corpus-semicolon-toggle',
		'corpus-splice',
	);
}

/**
 * @return string[]
 */
function corpus_seed_payloads(): array {
	$method = new \ReflectionMethod( Generator::class, 'corpus_payloads' );
	$method->setAccessible( true );
	return $method->invoke( null );
}

/**
 * @param string[] $base_names
 * @return array{base_set: array<string, true>, delete: array<string, true>, substitution: array<int, array<string, true>>, transpose: array<string, true>}
 */
function lookalike_mutation_indexes( array $base_names ): array {
	$base_set              = array_fill_keys( $base_names, true );
	$delete_mutants        = array();
	$substitution_patterns = array();
	$transpose_mutants     = array();

	foreach ( $base_names as $base ) {
		$length = strlen( $base );
		for ( $i = 0; $i < $length; $i++ ) {
			$delete = substr( $base, 0, $i ) . substr( $base, $i + 1 );
			if ( '' !== $delete && ! isset( $base_set[ $delete ] ) ) {
				$delete_mutants[ $delete ] = true;
			}

			$substitution_patterns[ $length ][ substr( $base, 0, $i ) . "\0" . substr( $base, $i + 1 ) ] = true;
		}

		for ( $i = 0; $i < $length - 1; $i++ ) {
			if ( $base[ $i ] === $base[ $i + 1 ] ) {
				continue;
			}
			$transpose = substr( $base, 0, $i ) . $base[ $i + 1 ] . $base[ $i ] . substr( $base, $i + 2 );
			if ( ! isset( $base_set[ $transpose ] ) ) {
				$transpose_mutants[ $transpose ] = true;
			}
		}
	}

	return array(
		'base_set'     => $base_set,
		'delete'       => $delete_mutants,
		'substitution' => $substitution_patterns,
		'transpose'    => $transpose_mutants,
	);
}

/**
 * @param array{base_set: array<string, true>, delete: array<string, true>, substitution: array<int, array<string, true>>, transpose: array<string, true>} $indexes
 * @return string[]
 */
function lookalike_candidate_classes( string $candidate, array $indexes ): array {
	if ( '' === $candidate || isset( $indexes['base_set'][ $candidate ] ) ) {
		return array();
	}

	$classes = array();
	if ( isset( $indexes['delete'][ $candidate ] ) ) {
		$classes['delete'] = true;
	}

	$length = strlen( $candidate );
	for ( $i = 0; $i < $length; $i++ ) {
		$shorter = substr( $candidate, 0, $i ) . substr( $candidate, $i + 1 );
		if ( isset( $indexes['base_set'][ $shorter ] ) ) {
			$classes['insert'] = true;
			break;
		}
	}

	$substitution_patterns = $indexes['substitution'][ $length ] ?? array();
	for ( $i = 0; $i < $length; $i++ ) {
		$pattern = substr( $candidate, 0, $i ) . "\0" . substr( $candidate, $i + 1 );
		if ( isset( $substitution_patterns[ $pattern ] ) ) {
			$classes['substitute'] = true;
			break;
		}
	}

	if ( isset( $indexes['transpose'][ $candidate ] ) ) {
		$classes['transpose'] = true;
	}

	return array_keys( $classes );
}

function sparse_lookalike_operation( string $candidate, string $base ): ?string {
	$candidate_length = strlen( $candidate );
	$base_length      = strlen( $base );

	if ( $candidate_length === $base_length - 1 ) {
		for ( $i = 0; $i < $base_length; $i++ ) {
			if ( substr( $base, 0, $i ) . substr( $base, $i + 1 ) === $candidate ) {
				return 'delete';
			}
		}
	}

	if ( $candidate_length === $base_length + 1 ) {
		for ( $i = 0; $i < $candidate_length; $i++ ) {
			if ( substr( $candidate, 0, $i ) . substr( $candidate, $i + 1 ) === $base ) {
				return 'insert';
			}
		}
	}

	if ( $candidate_length !== $base_length ) {
		return null;
	}

	$diffs = array();
	for ( $i = 0; $i < $base_length; $i++ ) {
		if ( $candidate[ $i ] !== $base[ $i ] ) {
			$diffs[] = $i;
		}
	}

	if ( 1 === count( $diffs ) ) {
		return 'substitute';
	}

	if (
		2 === count( $diffs ) &&
		$diffs[1] === $diffs[0] + 1 &&
		$candidate[ $diffs[0] ] === $base[ $diffs[1] ] &&
		$candidate[ $diffs[1] ] === $base[ $diffs[0] ]
	) {
		return 'transpose';
	}

	return null;
}

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'decode_text'      => static fn( string $text ): string => str_replace( "\u{20AC}", "\u{0080}", \WP_HTML_Decoder::decode_text_node( $text ) ),
		'decode_attribute' => static fn( string $text ): string => str_replace( "\u{20AC}", "\u{0080}", \WP_HTML_Decoder::decode_attribute( $text ) ),
	)
);
check( 'catches decoder skipping C1 remap', in_array( 'decode-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'decode_attribute' => static fn( string $text ): string => \WP_HTML_Decoder::decode_text_node( $text ),
		'read_character_reference' => static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
			return \WP_HTML_Decoder::read_character_reference( 'attribute' === $context ? 'data' : $context, $text, $at, $match_byte_length );
		},
	)
);
check( 'catches semicolonless refs decoded in attributes', in_array( 'decode-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'read_character_reference' => static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
			$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
			if ( null !== $result ) {
				++$match_byte_length;
			}
			return $result;
		},
	)
);
check(
	'catches off-by-one match length',
	in_array( 'reader-decode-mismatch', $seen, true ) || in_array( 'reader-overran-input', $seen, true ),
	implode( ',', $seen )
);

$seen = fault_run( $oracles, 'reader-empty-chunk', 'a&amp;b' );
check( 'fault target reader-empty-chunk exposes empty chunks', in_array( 'reader-returned-empty-chunk', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'reader-short-match-length', 'a&amp;b' );
check( 'fault target reader-short-match-length exposes one-byte matches', in_array( 'reader-match-too-short', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'reader-substring-composition' );
check( 'fault target reader-substring-composition exposes local-reader mismatches', in_array( 'reader-composition-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'reader-null-mutates-match-length', 'a&bogus;b' );
check( 'fault target reader-null-mutates-match-length exposes null match-length mutation', in_array( 'reader-mutated-match-length-on-null', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'reader-non-amp-match', 'a&amp;b' );
check( 'fault target reader-non-amp-match exposes non-amp reader matches', in_array( 'reader-non-amp-match', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'reader-gapless-drop-span', 'a&amp;b' );
check( 'fault target reader-gapless-drop-span exposes non-gapless reader walks', in_array( 'reader-walk-not-gapless', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'numeric-invalid-not-replacement', 'a&#0;b' );
check( 'fault target numeric-invalid-not-replacement exposes invalid numeric replacements', in_array( 'numeric-invalid-not-replacement', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'numeric-c1-not-remapped', 'a&#x80;b' );
check( 'fault target numeric-c1-not-remapped exposes skipped numeric C1 remaps', in_array( 'numeric-c1-not-remapped', $seen, true ), implode( ',', $seen ) );

$seen = fault_run_without_oracle( $oracles, 'raw-c1-not-pass-through', "\x80\x9F" );
check( 'fault target raw-c1-not-pass-through exposes raw C1 byte rewrites', in_array( 'raw-c1-not-pass-through', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'text-secondary-oracle', 'a&amp;b', 'text' );
check( 'fault target text-secondary-oracle exposes secondary text-oracle mismatches', in_array( 'text-secondary-oracle-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'text-secondary-oracle', 'a&#x80;b', 'text' );
check( 'secondary text oracle skips numeric references unsupported by html_entity_decode', ! in_array( 'text-secondary-oracle-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'text-secondary-oracle', 'a&AEliglater;b', 'text' );
check( 'secondary text oracle skips unknown names with legacy prefixes', ! in_array( 'text-secondary-oracle-mismatch', $seen, true ), implode( ',', $seen ) );

$single_level_failures = $checks->run( 'both', '&amp;amp;' );
check( 'single-level decode keeps nested ampersand reference literal', array() === $single_level_failures, json_encode( $single_level_failures ) );

$seen = fault_run( $oracles, 'single-level-overdecode', '&amp;amp;', 'text' );
check( 'fault target single-level-overdecode exposes text double decodes', in_array( 'single-level-decode-overdecoded', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'single-level-overdecode', '&amp;amp;', 'attribute' );
check( 'fault target single-level-overdecode exposes attribute double decodes', in_array( 'single-level-decode-overdecoded', $seen, true ), implode( ',', $seen ) );

$wrong_text = '!a&b';
$wrong_primary_oracles = new class( $wrong_text ) extends Oracles {
	private string $wrong_text;

	public function __construct( string $wrong_text ) {
		$this->wrong_text = $wrong_text;
	}

	public function decode( string $context, string $payload ): string {
		if ( 'text' === $context ) {
			return $this->wrong_text;
		}

		return parent::decode( $context, $payload );
	}
};
$wrong_agreement_checks = new Checks(
	$wrong_primary_oracles,
	array_merge(
		$real_targets,
		array(
			'decode_text' => static fn( string $text ): string => $wrong_text,
		)
	)
);
$wrong_agreement_seen = array();
foreach ( $wrong_agreement_checks->run( 'text', 'a&amp;b' ) as $failure ) {
	$wrong_agreement_seen[ $failure['check'] ] = true;
}
check(
	'secondary text oracle catches primary and target agreement on wrong text',
	isset( $wrong_agreement_seen['text-secondary-oracle-mismatch'] ) &&
		! isset( $wrong_agreement_seen['decode-mismatch'] ),
	implode( ',', array_keys( $wrong_agreement_seen ) )
);

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'decode_attribute' => static function ( string $text ): string {
			$decoded = \WP_HTML_Decoder::decode_attribute( $text );
			return str_contains( $text, '&' ) ? $decoded : '!' . $decoded;
		},
	)
);
check( 'catches attribute no-amp identity violations in oracle mode', in_array( 'attribute-without-ampersand-not-identity', $seen, true ), implode( ',', $seen ) );

$seen = fault_run( $oracles, 'attribute-no-amp-identity', 'plain' );
check( 'fault target attribute-no-amp-identity exposes attribute no-amp identity violations', in_array( 'attribute-without-ampersand-not-identity', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'attribute_starts_with' => static function ( string $haystack, string $search, string $case_sensitivity ): bool {
			unset( $case_sensitivity );
			return '' === $search || strlen( $haystack ) < strlen( $search ) || str_starts_with( \WP_HTML_Decoder::decode_attribute( $haystack ), $search );
		},
	)
);
check( 'catches partial-prefix attribute matcher', in_array( 'attribute-starts-with-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'attribute_starts_with' => static function ( string $haystack, string $search, string $case_sensitivity ) use ( $real_targets ): bool {
			if ( str_starts_with( $haystack, '&nvlt;' ) && "<\xE2" === $search ) {
				return false;
			}
			return $real_targets['attribute_starts_with']( $haystack, $search, $case_sensitivity );
		},
	)
);
check( 'catches partial multi-code-point attribute matcher', in_array( 'attribute-starts-with-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'attribute_starts_with' => static function ( string $haystack, string $search, string $case_sensitivity ) use ( $real_targets ): bool {
			if ( 'jav' === $search ) {
				return false;
			}
			return $real_targets['attribute_starts_with']( $haystack, $search, $case_sensitivity );
		},
	)
);
check( 'catches attribute_starts_with prefix monotonicity violations', in_array( 'attribute-starts-with-prefix-monotonicity', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'attribute_starts_with' => static function ( string $haystack, string $search, string $case_sensitivity ) use ( $real_targets ): bool {
			if ( str_ends_with( $search, "\x7F" ) ) {
				return true;
			}
			return $real_targets['attribute_starts_with']( $haystack, $search, $case_sensitivity );
		},
	)
);
check( 'catches attribute_starts_with extension monotonicity violations', in_array( 'attribute-starts-with-extension-monotonicity', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'attribute_starts_with' => static function ( string $haystack, string $search, string $case_sensitivity ) use ( $real_targets ): bool {
			if ( 'ascii-case-insensitive' === $case_sensitivity && 'jav' === $search ) {
				return false;
			}
			return $real_targets['attribute_starts_with']( $haystack, $search, $case_sensitivity );
		},
	)
);
check( 'catches attribute_starts_with case monotonicity violations', in_array( 'attribute-starts-with-case-monotonicity', $seen, true ), implode( ',', $seen ) );

$attribute_faults = array(
	'attribute-prefix-monotonicity'    => 'attribute-starts-with-prefix-monotonicity',
	'attribute-extension-monotonicity' => 'attribute-starts-with-extension-monotonicity',
	'attribute-case-monotonicity'      => 'attribute-starts-with-case-monotonicity',
);
foreach ( $attribute_faults as $fault => $expected_check ) {
	$seen = fault_run( $oracles, $fault );
	check( "fault target {$fault} exposes {$expected_check}", in_array( $expected_check, $seen, true ), implode( ',', $seen ) );
}

$seen = fault_run( $oracles, 'attribute-multicodepoint-prefix', '&nvlt;tail' );
check( 'fault target attribute-multicodepoint-prefix exposes partial replacement prefixes', in_array( 'attribute-starts-with-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = broken_oracle_free_run(
	$oracles,
	$real_targets,
	array(
		'decode_text'      => static fn( string $text ): string => str_replace( "\x00", '', \WP_HTML_Decoder::decode_text_node( $text ) ),
		'decode_attribute' => static fn( string $text ): string => str_replace( "\x00", '', \WP_HTML_Decoder::decode_attribute( $text ) ),
	)
);
check(
	'catches oracle-free no-amp byte identity violations',
	in_array( 'text-without-ampersand-not-identity', $seen, true ) &&
		in_array( 'attribute-without-ampersand-not-identity', $seen, true ),
	implode( ',', $seen )
);

$names = Bootstrap::named_reference_names();
check( 'uses generated named-reference map', count( $names ) > 2000, (string) count( $names ) );

$a = ( new Generator( new Prng( '7:3' ), 4096, $names ) )->generate();
$b = ( new Generator( new Prng( '7:3' ), 4096, $names ) )->generate();
check( 'generator deterministic for (seed, case)', $a === $b );

$custom_names = array( 'zz;', 'amp;', 'LongName;', 'abc', 'copy', 'z' );
$reversed_custom_names = array_reverse( $custom_names );
$order_stable_error = '';
for ( $i = 0; $i < 80; $i++ ) {
	$ordered_generator  = new Generator( new Prng( "order-stable:{$i}" ), 4096, $custom_names );
	$reversed_generator = new Generator( new Prng( "order-stable:{$i}" ), 4096, $reversed_custom_names );
	if ( $ordered_generator->generate() !== $reversed_generator->generate() ) {
		$order_stable_error = "weighted case {$i}";
		break;
	}

	$ordered_sweep  = new Generator( new Prng( "order-stable-name:{$i}" ), 4096, $custom_names );
	$reversed_sweep = new Generator( new Prng( "order-stable-name:{$i}" ), 4096, $reversed_custom_names );
	if ( $ordered_sweep->generate_name_sweep( $i ) !== $reversed_sweep->generate_name_sweep( $i ) ) {
		$order_stable_error = "name sweep case {$i}";
		break;
	}

	$ordered_legacy  = new Generator( new Prng( "order-stable-legacy:{$i}" ), 4096, $custom_names );
	$reversed_legacy = new Generator( new Prng( "order-stable-legacy:{$i}" ), 4096, $reversed_custom_names );
	if ( $ordered_legacy->generate_legacy_follower_sweep( $i ) !== $reversed_legacy->generate_legacy_follower_sweep( $i ) ) {
		$order_stable_error = "legacy follower case {$i}";
		break;
	}
}
check( 'generator sorts injected named-reference lists deterministically', '' === $order_stable_error, $order_stable_error );

$name_sweep_generator = new Generator( new Prng( 'name-sweep' ), 4096, $names );
$name_sweep_base_names = name_sweep_base_names( $names );
$name_sweep_followers = array( '', 'x', 'X', '0', '=', '-', ' ', '/', "\u{00E9}" );
$name_sweep_period = count( $name_sweep_base_names ) * 2 * count( $name_sweep_followers );
$name_sweep_mismatch = '';
$name_sweep_contexts = array();
$name_sweep_strategies = array();
$name_sweep_unsafe = 0;
for ( $i = 0; $i < $name_sweep_period; $i++ ) {
	$generated = $name_sweep_generator->generate_name_sweep( $i );
	$name_sweep_contexts[ $generated['context'] ] = true;
	$name_sweep_strategies[ $generated['strategy'] ] = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$name_sweep_unsafe;
	}

	if ( '' === $name_sweep_mismatch ) {
		$variant = $i % ( 2 * count( $name_sweep_followers ) );
		$expected = '&' . $name_sweep_base_names[ intdiv( $i, 2 * count( $name_sweep_followers ) ) ] .
			( $variant >= count( $name_sweep_followers ) ? ';' : '' ) .
			$name_sweep_followers[ $variant % count( $name_sweep_followers ) ];
		if ( $generated['payload'] !== $expected ) {
			$name_sweep_mismatch = "case {$i}: expected " . bin2hex( $expected ) . ' got ' . bin2hex( $generated['payload'] );
		}
	}
}
check( 'name-sweep period covers every base/semicolon/follower case', $name_sweep_generator->name_sweep_period() === $name_sweep_period && $name_sweep_period > count( $names ), (string) $name_sweep_period );
check( 'name-sweep generator maps cases deterministically', '' === $name_sweep_mismatch, $name_sweep_mismatch );
check( 'name-sweep cases run both contexts', array( 'both' ) === array_keys( $name_sweep_contexts ), implode( ',', array_keys( $name_sweep_contexts ) ) );
check( 'name-sweep uses one strategy label', array( 'name-sweep' ) === array_keys( $name_sweep_strategies ), implode( ',', array_keys( $name_sweep_strategies ) ) );
check( 'name-sweep payloads are oracle-safe', 0 === $name_sweep_unsafe, (string) $name_sweep_unsafe );

$legacy_follower_generator = new Generator( new Prng( 'legacy-follower-sweep' ), 4096, $names );
$legacy_names = array_values( array_filter( $names, static fn( string $name ): bool => ! str_ends_with( $name, ';' ) ) );
$legacy_followers = legacy_follower_sweep_followers();
$legacy_period = count( $legacy_names ) * count( $legacy_followers );
$legacy_mismatch = '';
$legacy_contexts = array();
$legacy_strategies = array();
$legacy_unsafe = 0;
$legacy_seen_names = array();
$legacy_seen_followers = array();
$legacy_ascii_followers = array();
$legacy_utf8_leads = array();
$legacy_utf8_continuations = array();
for ( $i = 0; $i < $legacy_period; $i++ ) {
	$generated = $legacy_follower_generator->generate_legacy_follower_sweep( $i );
	$name      = $legacy_names[ intdiv( $i, count( $legacy_followers ) ) ];
	$follower  = $legacy_followers[ $i % count( $legacy_followers ) ];
	$expected  = '&' . $name . $follower;

	$legacy_contexts[ $generated['context'] ] = true;
	$legacy_strategies[ $generated['strategy'] ] = true;
	$legacy_seen_names[ $name ] = true;
	$legacy_seen_followers[ $follower ] = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$legacy_unsafe;
	}
	if ( '' === $legacy_mismatch && $expected !== $generated['payload'] ) {
		$legacy_mismatch = "case {$i}: expected " . bin2hex( $expected ) . ' got ' . bin2hex( $generated['payload'] );
	}

	if ( 1 === strlen( $follower ) ) {
		$legacy_ascii_followers[ ord( $follower ) ] = true;
	} else {
		$legacy_utf8_leads[ ord( $follower[0] ) ] = true;
		for ( $j = 1; $j < strlen( $follower ); $j++ ) {
			$legacy_utf8_continuations[ ord( $follower[ $j ] ) ] = true;
		}
	}
}
$expected_ascii_followers = array_values(
	array_filter(
		range( 1, 0x7F ),
		static fn( int $byte ): bool => ! in_array( $byte, array( 0x0D, 0x22, 0x3C ), true )
	)
);
check( 'legacy-follower period covers every legacy name and follower', $legacy_follower_generator->legacy_follower_sweep_period() === $legacy_period && count( $legacy_seen_names ) === count( $legacy_names ) && count( $legacy_seen_followers ) === count( $legacy_followers ), (string) $legacy_period );
check( 'legacy-follower generator maps cases deterministically', '' === $legacy_mismatch, $legacy_mismatch );
check( 'legacy-follower cases run both contexts', array( 'both' ) === array_keys( $legacy_contexts ), implode( ',', array_keys( $legacy_contexts ) ) );
check( 'legacy-follower uses one strategy label', array( 'legacy-follower-sweep' ) === array_keys( $legacy_strategies ), implode( ',', array_keys( $legacy_strategies ) ) );
check( 'legacy-follower payloads are oracle-safe', 0 === $legacy_unsafe, (string) $legacy_unsafe );
check( 'legacy-follower covers every oracle-safe ASCII follower byte', array() === array_diff( $expected_ascii_followers, array_keys( $legacy_ascii_followers ) ), implode( ',', array_keys( $legacy_ascii_followers ) ) );
check( 'legacy-follower covers valid UTF-8 lead bytes', array() === array_diff( range( 0xC2, 0xF4 ), array_keys( $legacy_utf8_leads ) ), implode( ',', array_map( static fn( int $byte ): string => dechex( $byte ), array_keys( $legacy_utf8_leads ) ) ) );
check( 'legacy-follower covers UTF-8 continuation bytes', array() === array_diff( range( 0x80, 0xBF ), array_keys( $legacy_utf8_continuations ) ), implode( ',', array_map( static fn( int $byte ): string => dechex( $byte ), array_keys( $legacy_utf8_continuations ) ) ) );

$prefix_family_generator = new Generator( new Prng( 'prefix-family-sweep' ), 4096, $names );
$prefix_family_cases = prefix_family_sweep_cases( $name_sweep_base_names );
$prefix_family_mismatch = '';
$prefix_family_contexts = array();
$prefix_family_strategies = array();
$prefix_family_unsafe = 0;
$prefix_family_references = array();
$prefix_family_split_keys = array();
$prefix_family_followers = array();
for ( $i = 0; $i < count( $prefix_family_cases ); $i++ ) {
	$generated = $prefix_family_generator->generate_prefix_family_sweep( $i );
	$case      = $prefix_family_cases[ $i ];
	$expected  = substr( $case['reference'], 0, $case['split'] ) . $case['follower'];

	$prefix_family_contexts[ $generated['context'] ] = true;
	$prefix_family_strategies[ $generated['strategy'] ] = true;
	$prefix_family_references[ $case['reference'] ] = true;
	$prefix_family_split_keys[ $case['reference'] . ':' . $case['split'] ] = true;
	$prefix_family_followers[ $case['follower'] ] = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$prefix_family_unsafe;
	}
	if ( '' === $prefix_family_mismatch && $expected !== $generated['payload'] ) {
		$prefix_family_mismatch = "case {$i}: expected " . bin2hex( $expected ) . ' got ' . bin2hex( $generated['payload'] );
	}
}
$expected_prefix_split_count = 0;
foreach ( array_keys( $prefix_family_references ) as $reference ) {
	$expected_prefix_split_count += strlen( $reference ) - 1;
}
check(
	'prefix-family period covers every reference split and follower',
	$prefix_family_generator->prefix_family_sweep_period() === count( $prefix_family_cases ) &&
		array() === array_diff(
			array_map( static fn( string $reference ): string => '&' . $reference, prefix_family_sweep_references() ),
			array_keys( $prefix_family_references )
		) &&
		count( $prefix_family_references ) === count( prefix_family_sweep_references() ) &&
		count( $prefix_family_split_keys ) === $expected_prefix_split_count &&
		count( $prefix_family_followers ) === count( prefix_family_sweep_followers() ),
	(string) count( $prefix_family_cases ) . ' ' . implode( ',', array_keys( $prefix_family_references ) )
);
check( 'prefix-family generator maps cases deterministically', '' === $prefix_family_mismatch, $prefix_family_mismatch );
check( 'prefix-family cases run both contexts', array( 'both' ) === array_keys( $prefix_family_contexts ), implode( ',', array_keys( $prefix_family_contexts ) ) );
check( 'prefix-family uses one strategy label', array( 'prefix-family-sweep' ) === array_keys( $prefix_family_strategies ), implode( ',', array_keys( $prefix_family_strategies ) ) );
check( 'prefix-family payloads are oracle-safe', 0 === $prefix_family_unsafe, (string) $prefix_family_unsafe );
check( 'prefix-family covers expected ambiguous followers', array() === array_diff( prefix_family_sweep_followers(), array_keys( $prefix_family_followers ) ), implode( ',', array_keys( $prefix_family_followers ) ) );

$token_map_generator = new Generator( new Prng( 'token-map-sweep' ), 4096, $names );
$token_map_cases = token_map_sweep_cases();
$token_map_structure = Bootstrap::named_reference_structure();
$token_map_minimal_large_names = array_values(
	array_filter(
		$token_map_structure['large_names'],
		static fn( string $name ): bool => strlen( $name ) === $token_map_structure['key_length'] + 1
	)
);
$token_map_large_name_set = array_fill_keys( $token_map_structure['large_names'], true );
$token_map_small_name_set = array_fill_keys( $token_map_structure['small_names'], true );
$token_map_mismatch = '';
$token_map_contexts = array();
$token_map_strategies = array();
$token_map_shapes = array();
$token_map_prefixes = array();
$token_map_small_exact_names = array();
$token_map_small_extended_names = array();
$token_map_large_exact_names = array();
$token_map_large_extended_names = array();
$token_map_divergence_errors = array();
$token_map_unsafe = 0;
$token_map_fault_case_index = null;
for ( $i = 0; $i < count( $token_map_cases ); $i++ ) {
	$case      = $token_map_cases[ $i ];
	$generated = $token_map_generator->generate_token_map_sweep( $i );
	$shape     = $case['shape'];

	$token_map_contexts[ $generated['context'] ] = true;
	$token_map_strategies[ $generated['strategy'] ] = true;
	$token_map_shapes[ $shape ] = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$token_map_unsafe;
	}
	if ( '' === $token_map_mismatch && $case['payload'] !== $generated['payload'] ) {
		$token_map_mismatch = "case {$i}: expected " . bin2hex( $case['payload'] ) . ' got ' . bin2hex( $generated['payload'] );
	}

	if ( 'large-prefix-divergent' === $shape ) {
		$prefix = $case['prefix'] ?? '';
		$token_map_prefixes[ $prefix ] = true;
		$payload_name = substr( $case['payload'], 1 );
		$rest = substr( $payload_name, strlen( $prefix ) );
		$first_rest = '' === $rest ? '' : $rest[0];
		$used_first_rest_chars = array();
		foreach ( $token_map_structure['large_names_by_prefix'][ $prefix ] ?? array() as $name ) {
			$name_rest = substr( $name, strlen( $prefix ) );
			if ( '' !== $name_rest ) {
				$used_first_rest_chars[ $name_rest[0] ] = true;
			}
		}

		if (
			strlen( $prefix ) !== $token_map_structure['key_length'] ||
			! str_starts_with( $case['payload'], '&' . $prefix ) ||
			! str_ends_with( $case['payload'], ';' ) ||
			isset( $token_map_large_name_set[ $payload_name ] ) ||
			isset( $token_map_small_name_set[ $payload_name ] ) ||
			'' === $first_rest ||
			isset( $used_first_rest_chars[ $first_rest ] )
		) {
			$token_map_divergence_errors[] = "{$i}:" . bin2hex( $case['payload'] );
		}
	} elseif ( 'small-boundary-exact' === $shape ) {
		$token_map_small_exact_names[ $case['name'] ?? '' ] = true;
	} elseif ( 'small-boundary-extended' === $shape ) {
		$token_map_small_extended_names[ $case['name'] ?? '' ] = true;
		if ( null === $token_map_fault_case_index ) {
			$token_map_fault_case_index = $i;
		}
	} elseif ( 'large-boundary-exact' === $shape ) {
		$token_map_large_exact_names[ $case['name'] ?? '' ] = true;
	} elseif ( 'large-boundary-extended' === $shape ) {
		$token_map_large_extended_names[ $case['name'] ?? '' ] = true;
	}
}
$expected_token_map_shapes = array(
	'large-prefix-divergent',
	'small-boundary-exact',
	'small-boundary-extended',
	'large-boundary-exact',
	'large-boundary-extended',
);
check(
	'token-map structure exposes two-byte large-word prefixes',
	2 === $token_map_structure['key_length'] &&
		count( $token_map_structure['group_prefixes'] ) > 0 &&
		count( $token_map_structure['group_prefixes'] ) === count( $token_map_structure['large_names_by_prefix'] ),
	json_encode(
		array(
			'key_length' => $token_map_structure['key_length'],
			'prefixes'   => count( $token_map_structure['group_prefixes'] ),
		)
	)
);
check(
	'token-map period covers prefix divergences and boundary names',
	$token_map_generator->token_map_period() === count( $token_map_cases ) &&
		array() === array_diff( $token_map_structure['group_prefixes'], array_keys( $token_map_prefixes ) ) &&
		count( $token_map_prefixes ) === count( $token_map_structure['group_prefixes'] ) &&
		array() === array_diff( $token_map_structure['small_names'], array_keys( $token_map_small_exact_names ) ) &&
		array() === array_diff( $token_map_structure['small_names'], array_keys( $token_map_small_extended_names ) ) &&
		array() === array_diff( $token_map_minimal_large_names, array_keys( $token_map_large_exact_names ) ) &&
		array() === array_diff( $token_map_minimal_large_names, array_keys( $token_map_large_extended_names ) ),
	(string) count( $token_map_cases )
);
check( 'token-map generator maps cases deterministically', '' === $token_map_mismatch, $token_map_mismatch );
check( 'token-map cases run both contexts', array( 'both' ) === array_keys( $token_map_contexts ), implode( ',', array_keys( $token_map_contexts ) ) );
check( 'token-map uses one strategy label', array( 'token-map-structure-sweep' ) === array_keys( $token_map_strategies ), implode( ',', array_keys( $token_map_strategies ) ) );
check( 'token-map payloads are oracle-safe', 0 === $token_map_unsafe, (string) $token_map_unsafe );
check(
	'token-map emits expected structure-aware shapes',
	array() === array_diff( $expected_token_map_shapes, array_keys( $token_map_shapes ) ) &&
		array() === array_diff( array_keys( $token_map_shapes ), $expected_token_map_shapes ),
	implode( ',', array_keys( $token_map_shapes ) )
);
check( 'token-map large-prefix probes diverge after the shared map prefix', array() === $token_map_divergence_errors, implode( ',', $token_map_divergence_errors ) );
check( 'token-map has semicolonless boundary fault case', null !== $token_map_fault_case_index, json_encode( $token_map_cases ) );

$numeric_boundary_generator = new Generator( new Prng( 'numeric-boundary-sweep' ), 4096, $names );
$numeric_boundary_cases = numeric_boundary_sweep_cases();
$numeric_boundary_mismatch = '';
$numeric_boundary_contexts = array();
$numeric_boundary_strategies = array();
$numeric_boundary_unsafe = 0;
$numeric_boundary_shapes = array();
$numeric_boundary_mixed_hex = false;
$numeric_boundary_exact_max_replacements = array();
$numeric_boundary_overflow_non_replacements = array();
for ( $i = 0; $i < count( $numeric_boundary_cases ); $i++ ) {
	$generated = $numeric_boundary_generator->generate_numeric_boundary_sweep( $i );
	$expected  = $numeric_boundary_cases[ $i ];
	$shape     = numeric_boundary_shape( $generated['payload'] );
	$decoded   = \WP_HTML_Decoder::decode_text_node( $generated['payload'] );

	$numeric_boundary_contexts[ $generated['context'] ] = true;
	$numeric_boundary_strategies[ $generated['strategy'] ] = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$numeric_boundary_unsafe;
	}
	if ( '' === $numeric_boundary_mismatch && $expected !== $generated['payload'] ) {
		$numeric_boundary_mismatch = "case {$i}: expected {$expected} got {$generated['payload']}";
	}
	$numeric_boundary_shapes[] = $shape['base'] . ':' . $shape['significant_digits'] . ':' . ( $shape['leading_zero'] ? 'zero' : 'plain' ) . ':' . ( $shape['semicolon'] ? 'semi' : 'nosemi' );
	$numeric_boundary_mixed_hex = $numeric_boundary_mixed_hex || $shape['mixed_hex'];
	if ( ( 'decimal' === $shape['base'] && 7 === $shape['significant_digits'] ) || ( 'hex' === $shape['base'] && 6 === $shape['significant_digits'] ) ) {
		if ( "\u{FFFD}" === $decoded ) {
			$numeric_boundary_exact_max_replacements[] = $generated['payload'];
		}
	} elseif ( ( 'decimal' === $shape['base'] && 8 === $shape['significant_digits'] ) || ( 'hex' === $shape['base'] && 7 === $shape['significant_digits'] ) ) {
		if ( "\u{FFFD}" !== $decoded ) {
			$numeric_boundary_overflow_non_replacements[] = $generated['payload'] . ':' . bin2hex( $decoded );
		}
	}
}
$expected_numeric_boundary_shapes = array();
foreach ( array( 'decimal' => 7, 'hex' => 6 ) as $base => $max_digits ) {
	foreach ( array( $max_digits, $max_digits + 1 ) as $digit_count ) {
		foreach ( array( 'plain', 'zero' ) as $zero ) {
			foreach ( array( 'nosemi', 'semi' ) as $semicolon ) {
				$expected_numeric_boundary_shapes[] = "{$base}:{$digit_count}:{$zero}:{$semicolon}";
			}
		}
	}
}
check( 'numeric-boundary period covers digit count, leading zero, and semicolon variants', $numeric_boundary_generator->numeric_boundary_sweep_period() === count( $numeric_boundary_cases ) && array() === array_diff( $expected_numeric_boundary_shapes, array_unique( $numeric_boundary_shapes ) ), implode( ',', array_unique( $numeric_boundary_shapes ) ) );
check( 'numeric-boundary period keeps decimal and hex casing variants distinct', 32 === count( $numeric_boundary_cases ), (string) count( $numeric_boundary_cases ) );
check( 'numeric-boundary exact-max digit cases stay in Unicode range', array() === $numeric_boundary_exact_max_replacements, implode( ',', $numeric_boundary_exact_max_replacements ) );
check( 'numeric-boundary max-plus-one digit cases decode as invalid', array() === $numeric_boundary_overflow_non_replacements, implode( ',', $numeric_boundary_overflow_non_replacements ) );
check( 'numeric-boundary generator maps cases deterministically', '' === $numeric_boundary_mismatch, $numeric_boundary_mismatch );
check( 'numeric-boundary cases run both contexts', array( 'both' ) === array_keys( $numeric_boundary_contexts ), implode( ',', array_keys( $numeric_boundary_contexts ) ) );
check( 'numeric-boundary uses one strategy label', array( 'numeric-boundary-sweep' ) === array_keys( $numeric_boundary_strategies ), implode( ',', array_keys( $numeric_boundary_strategies ) ) );
check( 'numeric-boundary payloads are oracle-safe', 0 === $numeric_boundary_unsafe, (string) $numeric_boundary_unsafe );
check( 'numeric-boundary emits mixed-case hex digits', $numeric_boundary_mixed_hex, implode( ',', $numeric_boundary_cases ) );

$corpus_period_generator = new Generator( new Prng( 'corpus-period' ), 4096, $names );
$corpus_strategies = array();
$corpus_contexts = array();
$corpus_payloads = array();
$corpus_unsafe = 0;
for ( $i = 0; $i < 600; $i++ ) {
	$generated = ( new Generator( new Prng( "1:{$i}" ), 4096, $names ) )->generate_corpus_mutation( $i );
	$corpus_strategies[ $generated['strategy'] ] = true;
	$corpus_contexts[ $generated['context'] ] = true;
	$corpus_payloads[ $generated['payload'] ] = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$corpus_unsafe;
	}
}
$seen_corpus_strategies = array_keys( $corpus_strategies );
sort( $seen_corpus_strategies );
$corpus_seed_payloads = corpus_seed_payloads();
$required_corpus_payloads = array(
	'FOO&gt;BAR',
	'ZZ&gt9YY',
	'ZZ&gtaYY',
	'ZZ&pound_id=23',
	'ZZ&prod;_id=23',
	'ZZ&AElig=',
);
check( 'corpus mutation seed corpus includes retained and external vectors', $corpus_period_generator->corpus_period() >= 40, (string) $corpus_period_generator->corpus_period() );
check( 'corpus seed retains html5lib text and attribute entity vectors', array() === array_diff( $required_corpus_payloads, $corpus_seed_payloads ), implode( ',', array_diff( $required_corpus_payloads, $corpus_seed_payloads ) ) );
check( 'corpus mutation generator emits every mutation strategy', expected_corpus_strategies() === $seen_corpus_strategies, implode( ',', $seen_corpus_strategies ) );
check( 'corpus mutation cases run both contexts', array( 'both' ) === array_keys( $corpus_contexts ), implode( ',', array_keys( $corpus_contexts ) ) );
check( 'corpus mutation payloads are oracle-safe', 0 === $corpus_unsafe, (string) $corpus_unsafe );
check( 'corpus mutation diversifies retained payload shapes', count( $corpus_payloads ) > 300, (string) count( $corpus_payloads ) );

$semicolon_toggle_method = new \ReflectionMethod( Generator::class, 'mutate_corpus_semicolon_toggle' );
$semicolon_toggle_method->setAccessible( true );
$duplication_method = new \ReflectionMethod( Generator::class, 'mutate_corpus_reference_duplication' );
$duplication_method->setAccessible( true );
$byte_perturb_method = new \ReflectionMethod( Generator::class, 'mutate_corpus_byte_perturb' );
$byte_perturb_method->setAccessible( true );
$splice_method = new \ReflectionMethod( Generator::class, 'mutate_corpus_splice' );
$splice_method->setAccessible( true );

check(
	'corpus semicolon toggle adds and removes semicolons',
	'&amp' === $semicolon_toggle_method->invoke( new Generator( new Prng( 'corpus-toggle-remove' ), 4096, $names ), '&amp;' ) &&
		'&amp;' === $semicolon_toggle_method->invoke( new Generator( new Prng( 'corpus-toggle-add' ), 4096, $names ), '&amp' )
);
check(
	'corpus reference duplication duplicates matched reference text',
	'x&notin;&notin;y' === $duplication_method->invoke( new Generator( new Prng( 'corpus-duplication' ), 4096, $names ), 'x&notin;y' )
);
$corpus_utf8_mutation_errors = array();
for ( $i = 0; $i < 100; $i++ ) {
	$byte_payload = $byte_perturb_method->invoke( new Generator( new Prng( "corpus-utf8-byte:{$i}" ), 4096, $names ), "\u{00E9}&amp;\u{2603}" );
	$splice_payload = $splice_method->invoke(
		new Generator( new Prng( "corpus-utf8-splice:{$i}" ), 4096, $names ),
		"A\u{00E9}B",
		array( "\u{2603}&amp;\u{00E9}", 'plain &gt;' )
	);
	if ( ! mb_check_encoding( $byte_payload, 'UTF-8' ) ) {
		$corpus_utf8_mutation_errors[] = 'byte:' . $i . ':' . bin2hex( $byte_payload );
	}
	if ( ! mb_check_encoding( $splice_payload, 'UTF-8' ) ) {
		$corpus_utf8_mutation_errors[] = 'splice:' . $i . ':' . bin2hex( $splice_payload );
	}
}
check( 'corpus byte perturb and splice preserve UTF-8 boundaries', array() === $corpus_utf8_mutation_errors, implode( ',', $corpus_utf8_mutation_errors ) );

$lookalike_indexes    = lookalike_mutation_indexes( $name_sweep_base_names );
$lookalike_candidates = array();
for ( $i = 0; $i < 6000; $i++ ) {
	$generated = ( new Generator( new Prng( "lookalike-smoke:{$i}" ), 4096, $names ) )->generate();
	if ( 'lookalike' !== $generated['strategy'] ) {
		continue;
	}
	if ( 1 !== preg_match( '/&([A-Za-z0-9]+);?/', $generated['payload'], $match ) ) {
		continue;
	}

	$candidate = $match[1];
	$classes   = lookalike_candidate_classes( $candidate, $lookalike_indexes );
	if ( array() === $classes ) {
		continue;
	}

	$lookalike_candidates[ $candidate ] = true;
}
check( 'lookalike generator emits edit-distance-1 name misses', count( $lookalike_candidates ) >= 100, (string) count( $lookalike_candidates ) );

$sparse_lookalike_names   = array( 'abcde;', 'vwxyz' );
$sparse_lookalike_bases   = name_sweep_base_names( $sparse_lookalike_names );
$sparse_lookalike_classes = array();
for ( $i = 0; $i < 6000; $i++ ) {
	$generated = ( new Generator( new Prng( "lookalike-sparse-smoke:{$i}" ), 4096, $sparse_lookalike_names ) )->generate();
	if ( 'lookalike' !== $generated['strategy'] || 1 !== preg_match( '/&([A-Za-z0-9]+);?/', $generated['payload'], $match ) ) {
		continue;
	}

	foreach ( $sparse_lookalike_bases as $base ) {
		$operation = sparse_lookalike_operation( $match[1], $base );
		if ( null !== $operation ) {
			$sparse_lookalike_classes[ $operation ] = true;
			break;
		}
	}
}
check(
	'lookalike generator exercises every edit operation branch',
	array() === array_diff( array( 'delete', 'insert', 'substitute', 'transpose' ), array_keys( $sparse_lookalike_classes ) ),
	implode( ',', array_keys( $sparse_lookalike_classes ) )
);

$case_mangled_candidates = array();
$case_mangled_invalid = array();
$base_names_by_lowercase = array();
foreach ( $name_sweep_base_names as $base ) {
	$base_names_by_lowercase[ strtolower( $base ) ][] = $base;
}
for ( $i = 0; $i < 8000; $i++ ) {
	$generated = ( new Generator( new Prng( "case-mangled-smoke:{$i}" ), 4096, $names ) )->generate();
	if ( 'case-mangled-name' !== $generated['strategy'] ) {
		continue;
	}
	if ( 1 !== preg_match( '/&([A-Za-z0-9]+);/', $generated['payload'], $match ) ) {
		continue;
	}

	$candidate = $match[1];
	$case_mangled_candidates[ $candidate ] = true;
	if ( isset( $lookalike_indexes['base_set'][ $candidate ] ) || ! isset( $base_names_by_lowercase[ strtolower( $candidate ) ] ) ) {
		$case_mangled_invalid[] = $candidate;
	}
}
check( 'case-mangled generator emits case-only name misses', count( $case_mangled_candidates ) >= 100 && array() === $case_mangled_invalid, implode( ',', array_slice( $case_mangled_invalid, 0, 20 ) ) . ':' . count( $case_mangled_candidates ) );

$case_mangle_method = new \ReflectionMethod( Generator::class, 'case_mangle_name_base' );
$case_mangle_method->setAccessible( true );
$case_mangle_direct_errors = array();
for ( $i = 0; $i < 50; $i++ ) {
	$lower_mutated = $case_mangle_method->invoke( new Generator( new Prng( "case-mangle-lower:{$i}" ), 4096, $names ), 'amp' );
	$upper_mutated = $case_mangle_method->invoke( new Generator( new Prng( "case-mangle-upper:{$i}" ), 4096, $names ), 'AMP' );
	if ( 'amp' === $lower_mutated || 'amp' !== strtolower( $lower_mutated ) ) {
		$case_mangle_direct_errors[] = 'lower:' . $lower_mutated;
	}
	if ( 'AMP' === $upper_mutated || 'AMP' !== strtoupper( $upper_mutated ) ) {
		$case_mangle_direct_errors[] = 'upper:' . $upper_mutated;
	}
}
check(
	'case-mangle helper flips lowercase and uppercase source letters directly',
	array() === $case_mangle_direct_errors,
	implode( ',', array_slice( $case_mangle_direct_errors, 0, 20 ) )
);

$generator_reflection = new \ReflectionClass( Generator::class );
$alphabet_constant    = $generator_reflection->getReflectionConstant( 'ASCII_ALPHABET' );
$ascii_alphabet       = null === $alphabet_constant ? '' : (string) $alphabet_constant->getValue();
check(
	'oracle-safe generator alphabet includes space, tab, LF, and FF followers',
	str_contains( $ascii_alphabet, ' ' ) &&
		str_contains( $ascii_alphabet, "\t" ) &&
		str_contains( $ascii_alphabet, "\n" ) &&
		str_contains( $ascii_alphabet, "\f" ) &&
		Generator::is_oracle_safe_payload( $ascii_alphabet ),
	bin2hex( $ascii_alphabet )
);

$strategies            = array();
$contexts              = array();
$unsafe                = 0;
$reference_at_eof      = 0;
$reference_at_eof_bad = 0;
$reference_at_eof_shapes = array();
$attribute_multicodepoint_prefix = 0;
$composition = 0;
$composition_bad_shape = 0;
$composition_multi_reference_fragments = 0;
$total                 = 1200;
for ( $i = 0; $i < $total; $i++ ) {
	$generated = ( new Generator( new Prng( "smoke:{$i}" ), 4096, $names ) )->generate();
	$strategies[ $generated['strategy'] ] = true;
	$contexts[ $generated['context'] ]    = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$unsafe;
	}
	if ( 'reference-at-eof' === $generated['strategy'] ) {
		++$reference_at_eof;
		$shape = reference_at_eof_shape( $generated['payload'] );
		if ( null === $shape ) {
			++$reference_at_eof_bad;
		} else {
			$reference_at_eof_shapes[ $shape ] = true;
		}
	}
	if (
		'attribute-prefix' === $generated['strategy'] &&
		(
			str_starts_with( $generated['payload'], '&nvlt;' ) ||
			str_starts_with( $generated['payload'], '&nvgt;' ) ||
			str_starts_with( $generated['payload'], '&NotLessLess;' ) ||
			str_starts_with( $generated['payload'], '&bne;' )
		)
	) {
		++$attribute_multicodepoint_prefix;
	}
	if ( 'composition' === $generated['strategy'] ) {
		++$composition;
		$fragments = explode( '|', $generated['payload'] );
		if ( count( $fragments ) < 2 || count( $fragments ) > 3 || in_array( '', $fragments, true ) ) {
			++$composition_bad_shape;
		}

		$reference_fragments = 0;
		foreach ( $fragments as $fragment ) {
			if ( str_contains( $fragment, '&' ) ) {
				++$reference_fragments;
			}
		}
		if ( $reference_fragments >= 2 ) {
			++$composition_multi_reference_fragments;
		}
	}
}
$seen_strategies = array_keys( $strategies );
sort( $seen_strategies );
check( 'all weighted strategies appear', expected_weighted_strategies() === $seen_strategies, implode( ',', $seen_strategies ) );
check( 'generated cases run both contexts', array( 'both' ) === array_keys( $contexts ), implode( ',', array_keys( $contexts ) ) );
check( 'generated payloads are oracle-safe', 0 === $unsafe, (string) $unsafe );
check( 'attribute-prefix generator emits multi-code-point references', $attribute_multicodepoint_prefix > 0, (string) $attribute_multicodepoint_prefix );
check( 'composition generator emits 2-3 separated fragments', $composition > 0 && 0 === $composition_bad_shape, "{$composition_bad_shape}/{$composition}" );
check( 'composition generator splices multiple reference-bearing fragments', $composition_multi_reference_fragments > 0, "{$composition_multi_reference_fragments}/{$composition}" );
check( 'reference-at-EOF cases end inside a reference', $reference_at_eof > 0 && 0 === $reference_at_eof_bad, "{$reference_at_eof_bad}/{$reference_at_eof}" );
check(
	'reference-at-EOF covers expected suffix shapes',
	array() === array_diff(
		array( 'bare-introducer', 'partial-numeric-introducer', 'decimal-digits', 'hex-digits', 'named-prefix' ),
		array_keys( $reference_at_eof_shapes )
	),
	implode( ',', array_keys( $reference_at_eof_shapes ) )
);

$small_compositions = 0;
$small_composition_bad = array();
foreach ( array( 3, 5, 7, 12 ) as $max_bytes ) {
	for ( $i = 0; $i < 1200; $i++ ) {
		$generated = ( new Generator( new Prng( "composition-small:{$max_bytes}:{$i}" ), $max_bytes, $names ) )->generate();
		if ( 'composition' !== $generated['strategy'] ) {
			continue;
		}

		++$small_compositions;
		$fragments = explode( '|', $generated['payload'] );
		if (
			strlen( $generated['payload'] ) > $max_bytes ||
			count( $fragments ) < 2 ||
			count( $fragments ) > 3 ||
			in_array( '', $fragments, true )
		) {
			$small_composition_bad[] = "{$max_bytes}:{$i}:" . bin2hex( $generated['payload'] );
		}
	}
}
check( 'composition generator keeps small max-bytes fragments nonempty', $small_compositions > 0 && array() === $small_composition_bad, implode( ',', $small_composition_bad ) );

$attribute_prefix_targets = array();
$attribute_prefix_forms = array();
$attribute_prefix_bad_targets = array();
for ( $i = 0; $i < 8000; $i++ ) {
	$generated = ( new Generator( new Prng( "attribute-prefix-smoke:{$i}" ), 4096, $names ) )->generate();
	if ( 'attribute-prefix' !== $generated['strategy'] ) {
		continue;
	}

	$decoded = $oracles->decode( 'attribute', $generated['payload'] );
	foreach ( attribute_prefix_smoke_targets() as $target ) {
		if ( ! str_starts_with( $decoded, $target ) ) {
			continue;
		}

		$attribute_prefix_targets[ $target ] = true;
		foreach ( attribute_prefix_encoding_forms( $generated['payload'] ) as $form ) {
			$attribute_prefix_forms[ $form ] = true;
		}
		if ( ! \WP_HTML_Decoder::attribute_starts_with( $generated['payload'], $target, 'case-sensitive' ) ) {
			$attribute_prefix_bad_targets[] = $target . ':' . bin2hex( substr( $generated['payload'], 0, 64 ) );
		}
		break;
	}
}
check(
	'attribute-prefix encoder covers every target string',
	array() === array_diff( attribute_prefix_smoke_targets(), array_keys( $attribute_prefix_targets ) ),
	implode( ',', array_keys( $attribute_prefix_targets ) )
);
check(
	'attribute-prefix encoder covers literal, numeric, zero, hex, and semicolonless forms',
	array() === array_diff( array( 'literal', 'decimal', 'leading-zero', 'hex', 'semicolonless' ), array_keys( $attribute_prefix_forms ) ),
	implode( ',', array_keys( $attribute_prefix_forms ) )
);
check( 'attribute-prefix encoded targets satisfy attribute_starts_with', array() === $attribute_prefix_bad_targets, implode( ',', $attribute_prefix_bad_targets ) );

$semicolonless_guard = new \ReflectionMethod( Generator::class, 'would_extend_semicolonless_numeric' );
check(
	'attribute-prefix semicolonless numeric guard protects terminators and digits',
	true === $semicolonless_guard->invoke( null, 'decimal', ';' ) &&
		true === $semicolonless_guard->invoke( null, 'hex', ';' ) &&
		true === $semicolonless_guard->invoke( null, 'decimal', '7' ) &&
		true === $semicolonless_guard->invoke( null, 'hex', 'A' ) &&
		false === $semicolonless_guard->invoke( null, 'decimal', 'A' ) &&
		false === $semicolonless_guard->invoke( null, null, ';' )
);

$numeric_ranges = array();
$numeric_c1_values = array();
$numeric_bmp_terminal_noncharacters = array();
$numeric_noncharacter_planes = array();
for ( $i = 0; $i < 6000; $i++ ) {
	$generated = ( new Generator( new Prng( "numeric-range-smoke:{$i}" ), 4096, $names ) )->generate();
	foreach ( numeric_reference_ranges( $generated['payload'] ) as $range => $_ ) {
		$numeric_ranges[ $range ] = true;
	}
	$match_count = preg_match_all( '/&#(?:([xX])([0-9A-Fa-f]+)|([0-9]+));?/', $generated['payload'], $matches, PREG_SET_ORDER );
	if ( false !== $match_count && $match_count > 0 ) {
		foreach ( $matches as $match ) {
			$is_hex = '' !== ( $match[1] ?? '' );
			$digits = $is_hex ? $match[2] : $match[3];
			$significant_digits = substr( $digits, strspn( $digits, '0' ) );
			if ( '' === $significant_digits || strlen( $significant_digits ) > ( $is_hex ? 6 : 7 ) ) {
				continue;
			}

			$value = intval( $significant_digits, $is_hex ? 16 : 10 );
			if ( $value >= 0x80 && $value <= 0x9F ) {
				$numeric_c1_values[ $value ] = true;
			}
			if ( 0xFFFE === $value || 0xFFFF === $value ) {
				$numeric_bmp_terminal_noncharacters[ $value ] = true;
			}
			if ( $value >= 0x1FFFE && $value <= 0x10FFFF && ( $value & 0xFFFF ) >= 0xFFFE ) {
				$numeric_noncharacter_planes[ $value >> 16 ] = true;
			}
		}
	}
	if (
		array() === array_diff(
			array(
				'zero-only',
				'c0-control',
				'c1-control',
				'bmp-pre-surrogate',
				'bmp-post-surrogate',
				'surrogate',
				'bmp-noncharacter',
				'plane-noncharacter',
				'astral',
				'above-unicode-legal-digits',
				'digit-count-overflow',
			),
			array_keys( $numeric_ranges )
		) &&
		32 === count( $numeric_c1_values ) &&
		2 === count( $numeric_bmp_terminal_noncharacters ) &&
		16 === count( $numeric_noncharacter_planes )
	) {
		break;
	}
}
check(
	'numeric generator covers range buckets',
	array() === array_diff(
		array(
			'zero-only',
			'c0-control',
			'c1-control',
			'bmp-pre-surrogate',
			'bmp-post-surrogate',
			'surrogate',
			'bmp-noncharacter',
			'plane-noncharacter',
			'astral',
			'above-unicode-legal-digits',
			'digit-count-overflow',
		),
		array_keys( $numeric_ranges )
	),
	implode( ',', array_keys( $numeric_ranges ) )
);
$expected_c1_values = range( 0x80, 0x9F );
check(
	'numeric generator covers all C1 remap rows',
	array() === array_diff( $expected_c1_values, array_keys( $numeric_c1_values ) ),
	implode( ',', array_map( static fn( int $value ): string => dechex( $value ), array_keys( $numeric_c1_values ) ) )
);
check(
	'numeric generator covers BMP terminal noncharacters',
	array() === array_diff( array( 0xFFFE, 0xFFFF ), array_keys( $numeric_bmp_terminal_noncharacters ) ),
	implode( ',', array_map( static fn( int $value ): string => dechex( $value ), array_keys( $numeric_bmp_terminal_noncharacters ) ) )
);
check(
	'numeric generator covers per-plane noncharacters',
	array() === array_diff( range( 1, 16 ), array_keys( $numeric_noncharacter_planes ) ),
	implode( ',', array_keys( $numeric_noncharacter_planes ) )
);

$byte_strategies = array();
$byte_contexts   = array();
$byte_unsafe     = 0;
$byte_nul        = 0;
for ( $i = 0; $i < $total; $i++ ) {
	$generated = ( new Generator( new Prng( "byte-smoke:{$i}" ), 4096, $names ) )->generate_bytes();
	$byte_strategies[ $generated['strategy'] ] = true;
	$byte_contexts[ $generated['context'] ]    = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$byte_unsafe;
	}
	if ( str_contains( $generated['payload'], "\x00" ) ) {
		++$byte_nul;
	}
}
check( 'all 5 byte-space strategies appear', 5 === count( $byte_strategies ), implode( ',', array_keys( $byte_strategies ) ) );
check( 'byte-space cases run both contexts', array( 'both' ) === array_keys( $byte_contexts ), implode( ',', array_keys( $byte_contexts ) ) );
check( 'byte-space generator emits unsafe payloads', $byte_unsafe > 0, (string) $byte_unsafe );
check( 'byte-space generator emits NUL bytes', $byte_nul > 0, (string) $byte_nul );

$trap_oracles = new class() extends Oracles {
	public function decode( string $context, string $payload ): string {
		throw new \RuntimeException( "oracle trap called for {$context} " . bin2hex( $payload ) );
	}
};
$unsafe_byte_failures = ( new Checks( $trap_oracles ) )->run_without_oracle( 'both', "\xFF\x00<\"\r" );
check(
	'oracle-free byte checks accept unsafe payloads',
	array() === $unsafe_byte_failures,
	json_encode( $unsafe_byte_failures )
);
$raw_c1_failures = ( new Checks( $trap_oracles ) )->run_without_oracle( 'both', "\x80\x9F" );
check(
	'oracle-free byte checks pass raw C1 bytes through unchanged',
	array() === $raw_c1_failures,
	json_encode( $raw_c1_failures )
);

$fuzz_failures = 0;
for ( $i = 0; $i < 300; $i++ ) {
	$generated = ( new Generator( new Prng( "smoke-run:{$i}" ), 4096, $names ) )->generate();
	$failures  = $checks->run( $generated['context'], $generated['payload'] );
	foreach ( $failures as $failure ) {
		++$fuzz_failures;
		echo "  finding: {$failure['signature']} on " . bin2hex( substr( $generated['payload'], 0, 48 ) ) . "\n";
	}
}
check( '300-case fuzz run clean', 0 === $fuzz_failures );

$byte_worker = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'bytes', '--seed', '1', '--cases', '200', '--progress-every', '200' ) );
check( '200-case byte-space worker clean', 0 === $byte_worker['code'], $byte_worker['stdout'] . $byte_worker['stderr'] );

$name_worker = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'names', '--seed', '1', '--cases', '300', '--progress-every', '300' ) );
check(
	'300-case name-sweep worker clean',
	0 === $name_worker['code'] && str_contains( $name_worker['stdout'], '"name-sweep":300' ),
	$name_worker['stdout'] . $name_worker['stderr']
);

$legacy_follower_worker = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'legacy-followers', '--seed', '1', '--cases', '300', '--progress-every', '300' ) );
check(
	'300-case legacy-follower worker clean',
	0 === $legacy_follower_worker['code'] && str_contains( $legacy_follower_worker['stdout'], '"legacy-follower-sweep":300' ),
	$legacy_follower_worker['stdout'] . $legacy_follower_worker['stderr']
);

$prefix_family_worker = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'prefix-families', '--seed', '1', '--cases', '300', '--progress-every', '300' ) );
check(
	'300-case prefix-family worker clean',
	0 === $prefix_family_worker['code'] && str_contains( $prefix_family_worker['stdout'], '"prefix-family-sweep":300' ),
	$prefix_family_worker['stdout'] . $prefix_family_worker['stderr']
);

$numeric_boundary_worker = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'numeric-boundaries', '--seed', '1', '--cases', '64', '--progress-every', '64' ) );
check(
	'64-case numeric-boundary worker clean',
	0 === $numeric_boundary_worker['code'] && str_contains( $numeric_boundary_worker['stdout'], '"numeric-boundary-sweep":64' ),
	$numeric_boundary_worker['stdout'] . $numeric_boundary_worker['stderr']
);

$corpus_worker = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'corpus', '--seed', '1', '--cases', '300', '--progress-every', '300' ) );
$corpus_worker_has_strategies = true;
foreach ( expected_corpus_strategies() as $strategy ) {
	$corpus_worker_has_strategies = $corpus_worker_has_strategies && str_contains( $corpus_worker['stdout'], '"' . $strategy . '"' );
}
check(
	'300-case corpus mutation worker clean',
	0 === $corpus_worker['code'] && $corpus_worker_has_strategies,
	$corpus_worker['stdout'] . $corpus_worker['stderr']
);

$token_map_worker = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'token-map', '--seed', '1', '--cases', '300', '--progress-every', '300' ) );
check(
	'300-case token-map worker clean',
	0 === $token_map_worker['code'] && str_contains( $token_map_worker['stdout'], '"token-map-structure-sweep":300' ),
	$token_map_worker['stdout'] . $token_map_worker['stderr']
);

$coverage_unavailable_worker = run_process(
	array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'coverage', '--seed', '1', '--cases', '1', '--progress-every', '1' ),
	array(
		'HTML_DECODER_FUZZ_DISABLE_PCOV'  => '1',
		'HTML_DECODER_FUZZ_FAKE_COVERAGE' => '0',
	)
);
check(
	'coverage worker reports unavailable pcov',
	2 === $coverage_unavailable_worker['code'] && str_contains( $coverage_unavailable_worker['stdout'], 'coverage mode requires pcov' ),
	$coverage_unavailable_worker['stdout'] . $coverage_unavailable_worker['stderr']
);

$coverage_worker_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-coverage-worker-' . getmypid();
remove_tree( $coverage_worker_dir );
$coverage_worker = run_process(
	array( PHP_BINARY, __DIR__ . '/../worker.php', '--mode', 'coverage', '--seed', '1', '--cases', '8', '--progress-every', '8', '--output-dir', $coverage_worker_dir ),
	array( 'HTML_DECODER_FUZZ_FAKE_COVERAGE' => '1' )
);
$coverage_worker_manifests = glob( $coverage_worker_dir . '/coverage-corpus/payload-*/coverage.json' );
$coverage_worker_manifest = is_array( $coverage_worker_manifests ) && array() !== $coverage_worker_manifests
	? json_decode( (string) file_get_contents( $coverage_worker_manifests[0] ), true )
	: array();
check(
	'coverage worker retains fake new-edge payloads',
	0 === $coverage_worker['code'] &&
		str_contains( $coverage_worker['stdout'], '"type":"coverage"' ) &&
		str_contains( $coverage_worker['stdout'], '"coverage_new_edges"' ) &&
		is_array( $coverage_worker_manifests ) &&
		count( $coverage_worker_manifests ) > 0,
	$coverage_worker['stdout'] . $coverage_worker['stderr']
);
check(
	'coverage corpus manifest records payload and target edges',
	is_array( $coverage_worker_manifest ) &&
		'coverage' === ( $coverage_worker_manifest['mode'] ?? null ) &&
		'fake' === ( $coverage_worker_manifest['coverage_provider'] ?? null ) &&
		is_string( $coverage_worker_manifest['payload_base64'] ?? null ) &&
		( $coverage_worker_manifest['new_edge_count'] ?? 0 ) > 0 &&
		is_array( $coverage_worker_manifest['new_edges'] ?? null ),
	json_encode( $coverage_worker_manifest )
);
remove_tree( $coverage_worker_dir );

$byte_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-byte-runner-' . getmypid();
remove_tree( $byte_runner_dir );
$byte_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'bytes',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'200',
		'--summary-mode',
		'none',
		'--output-dir',
		$byte_runner_dir,
	)
);
$byte_runner_state = is_file( $byte_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $byte_runner_dir . '/state.json' ), true )
	: array();
check(
	'200-case byte-space runner clean',
	0 === $byte_runner['code'] &&
		200 === ( $byte_runner_state['cases'] ?? 0 ) &&
		200 === ( $byte_runner_state['by_context']['both'] ?? 0 ),
	$byte_runner['stdout'] . $byte_runner['stderr'] . json_encode( $byte_runner_state )
);
remove_tree( $byte_runner_dir );

$name_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-name-runner-' . getmypid();
remove_tree( $name_runner_dir );
$name_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'names',
		'--lanes',
		'2',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'100',
		'--summary-mode',
		'all',
		'--output-dir',
		$name_runner_dir,
	)
);
$name_runner_state = is_file( $name_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $name_runner_dir . '/state.json' ), true )
	: array();
check(
	'name-sweep runner clean',
	0 === $name_runner['code'] &&
		( $name_runner_state['cases'] ?? 0 ) >= 200 &&
		( $name_runner_state['cases'] ?? null ) === ( $name_runner_state['by_strategy']['name-sweep'] ?? null ) &&
		( $name_runner_state['cases'] ?? null ) === ( $name_runner_state['by_context']['both'] ?? null ),
	$name_runner['stdout'] . $name_runner['stderr'] . json_encode( $name_runner_state )
);
$name_runner_windows = summary_start_windows( $name_runner_dir, 'names' );
check(
	'name-sweep runner uses distinct start-case windows',
	start_windows_are_distinct( $name_runner_windows, 100 ),
	json_encode( $name_runner_windows )
);
remove_tree( $name_runner_dir );

$legacy_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-legacy-follower-runner-' . getmypid();
remove_tree( $legacy_runner_dir );
$legacy_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'legacy-followers',
		'--lanes',
		'2',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'100',
		'--summary-mode',
		'all',
		'--output-dir',
		$legacy_runner_dir,
	)
);
$legacy_runner_state = is_file( $legacy_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $legacy_runner_dir . '/state.json' ), true )
	: array();
check(
	'legacy-follower runner clean',
	0 === $legacy_runner['code'] &&
		( $legacy_runner_state['cases'] ?? 0 ) >= 200 &&
		( $legacy_runner_state['cases'] ?? null ) === ( $legacy_runner_state['by_strategy']['legacy-follower-sweep'] ?? null ) &&
		( $legacy_runner_state['cases'] ?? null ) === ( $legacy_runner_state['by_context']['both'] ?? null ),
	$legacy_runner['stdout'] . $legacy_runner['stderr'] . json_encode( $legacy_runner_state )
);
$legacy_runner_windows = summary_start_windows( $legacy_runner_dir, 'legacy-followers' );
check(
	'legacy-follower runner uses distinct start-case windows',
	start_windows_are_distinct( $legacy_runner_windows, 100 ),
	json_encode( $legacy_runner_windows )
);
remove_tree( $legacy_runner_dir );

$prefix_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-prefix-family-runner-' . getmypid();
remove_tree( $prefix_runner_dir );
$prefix_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'prefix-families',
		'--lanes',
		'2',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'100',
		'--summary-mode',
		'all',
		'--output-dir',
		$prefix_runner_dir,
	)
);
$prefix_runner_state = is_file( $prefix_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $prefix_runner_dir . '/state.json' ), true )
	: array();
check(
	'prefix-family runner clean',
	0 === $prefix_runner['code'] &&
		( $prefix_runner_state['cases'] ?? 0 ) >= 200 &&
		( $prefix_runner_state['cases'] ?? null ) === ( $prefix_runner_state['by_strategy']['prefix-family-sweep'] ?? null ) &&
		( $prefix_runner_state['cases'] ?? null ) === ( $prefix_runner_state['by_context']['both'] ?? null ),
	$prefix_runner['stdout'] . $prefix_runner['stderr'] . json_encode( $prefix_runner_state )
);
$prefix_runner_windows = summary_start_windows( $prefix_runner_dir, 'prefix-families' );
check(
	'prefix-family runner uses distinct start-case windows',
	start_windows_are_distinct( $prefix_runner_windows, 100 ),
	json_encode( $prefix_runner_windows )
);
remove_tree( $prefix_runner_dir );

$numeric_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-numeric-boundary-runner-' . getmypid();
remove_tree( $numeric_runner_dir );
$numeric_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'numeric-boundaries',
		'--lanes',
		'2',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'100',
		'--summary-mode',
		'all',
		'--output-dir',
		$numeric_runner_dir,
	)
);
$numeric_runner_state = is_file( $numeric_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $numeric_runner_dir . '/state.json' ), true )
	: array();
check(
	'numeric-boundary runner clean',
	0 === $numeric_runner['code'] &&
		( $numeric_runner_state['cases'] ?? 0 ) >= 200 &&
		( $numeric_runner_state['cases'] ?? null ) === ( $numeric_runner_state['by_strategy']['numeric-boundary-sweep'] ?? null ) &&
		( $numeric_runner_state['cases'] ?? null ) === ( $numeric_runner_state['by_context']['both'] ?? null ),
	$numeric_runner['stdout'] . $numeric_runner['stderr'] . json_encode( $numeric_runner_state )
);
$numeric_runner_windows = summary_start_windows( $numeric_runner_dir, 'numeric-boundaries' );
check(
	'numeric-boundary runner uses distinct start-case windows',
	start_windows_are_distinct( $numeric_runner_windows, 100 ),
	json_encode( $numeric_runner_windows )
);
remove_tree( $numeric_runner_dir );

$corpus_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-corpus-runner-' . getmypid();
remove_tree( $corpus_runner_dir );
$corpus_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'corpus',
		'--lanes',
		'2',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'100',
		'--summary-mode',
		'all',
		'--output-dir',
		$corpus_runner_dir,
	)
);
$corpus_runner_state = is_file( $corpus_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $corpus_runner_dir . '/state.json' ), true )
	: array();
$corpus_runner_strategies = array_keys( $corpus_runner_state['by_strategy'] ?? array() );
sort( $corpus_runner_strategies );
check(
	'corpus mutation runner clean',
	0 === $corpus_runner['code'] &&
		( $corpus_runner_state['cases'] ?? 0 ) >= 200 &&
		( $corpus_runner_state['cases'] ?? null ) === ( $corpus_runner_state['by_context']['both'] ?? null ) &&
		( $corpus_runner_state['cases'] ?? null ) === array_sum( $corpus_runner_state['by_strategy'] ?? array() ) &&
		expected_corpus_strategies() === $corpus_runner_strategies,
	$corpus_runner['stdout'] . $corpus_runner['stderr'] . json_encode( $corpus_runner_state )
);
$corpus_runner_windows = summary_start_windows( $corpus_runner_dir, 'corpus' );
check(
	'corpus mutation runner uses distinct start-case windows',
	start_windows_are_distinct( $corpus_runner_windows, 100 ),
	json_encode( $corpus_runner_windows )
);
remove_tree( $corpus_runner_dir );

$token_map_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-token-map-runner-' . getmypid();
remove_tree( $token_map_runner_dir );
$token_map_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'token-map',
		'--lanes',
		'2',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'100',
		'--summary-mode',
		'all',
		'--output-dir',
		$token_map_runner_dir,
	)
);
$token_map_runner_state = is_file( $token_map_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $token_map_runner_dir . '/state.json' ), true )
	: array();
check(
	'token-map runner clean',
	0 === $token_map_runner['code'] &&
		( $token_map_runner_state['cases'] ?? 0 ) >= 200 &&
		( $token_map_runner_state['cases'] ?? null ) === ( $token_map_runner_state['by_strategy']['token-map-structure-sweep'] ?? null ) &&
		( $token_map_runner_state['cases'] ?? null ) === ( $token_map_runner_state['by_context']['both'] ?? null ),
	$token_map_runner['stdout'] . $token_map_runner['stderr'] . json_encode( $token_map_runner_state )
);
$token_map_runner_windows = summary_start_windows( $token_map_runner_dir, 'token-map' );
check(
	'token-map runner uses distinct start-case windows',
	start_windows_are_distinct( $token_map_runner_windows, 100 ),
	json_encode( $token_map_runner_windows )
);
remove_tree( $token_map_runner_dir );

$coverage_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-coverage-runner-' . getmypid();
remove_tree( $coverage_runner_dir );
$coverage_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'coverage',
		'--lanes',
		'2',
		'--duration-seconds',
		'0',
		'--max-cases',
		'40',
		'--cases-per-batch',
		'20',
		'--summary-mode',
		'failures',
		'--output-dir',
		$coverage_runner_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAKE_COVERAGE' => '1' )
);
$coverage_runner_state = is_file( $coverage_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $coverage_runner_dir . '/state.json' ), true )
	: array();
$coverage_runner_manifests = glob( $coverage_runner_dir . '/coverage-corpus/payload-*/coverage.json' );
$coverage_summary = is_file( $coverage_runner_dir . '/summary.ndjson' )
	? file( $coverage_runner_dir . '/summary.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES )
	: array();
$coverage_summary_retained = 0;
if ( is_array( $coverage_summary ) ) {
	foreach ( $coverage_summary as $line ) {
		$record = json_decode( $line, true );
		if ( is_array( $record ) && 'coverage' === ( $record['type'] ?? null ) && ! empty( $record['coverage_retained'] ) ) {
			++$coverage_summary_retained;
		}
	}
}
check(
	'coverage runner aggregates fake new-edge corpus',
	0 === $coverage_runner['code'] &&
		( $coverage_runner_state['cases'] ?? 0 ) >= 40 &&
		( $coverage_runner_state['cases'] ?? null ) === ( $coverage_runner_state['by_context']['both'] ?? null ) &&
		( $coverage_runner_state['cases'] ?? null ) === array_sum( $coverage_runner_state['by_strategy'] ?? array() ) &&
		( $coverage_runner_state['coverage']['edges'] ?? 0 ) > 0 &&
		( $coverage_runner_state['coverage']['payloads'] ?? 0 ) > 0 &&
		is_array( $coverage_runner_manifests ) &&
		count( $coverage_runner_manifests ) === ( $coverage_runner_state['coverage']['payloads'] ?? -1 ) &&
		$coverage_summary_retained === ( $coverage_runner_state['coverage']['payloads'] ?? -1 ),
	$coverage_runner['stdout'] . $coverage_runner['stderr'] . json_encode( $coverage_runner_state )
);
remove_tree( $coverage_runner_dir );

$name_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'names', '--seed', '1', '--case', '0' ) );
check( 'name-sweep replay regenerates clean case', 0 === $name_replay['code'], $name_replay['stdout'] . $name_replay['stderr'] );

$legacy_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'legacy-followers', '--seed', '1', '--case', '0' ) );
check( 'legacy-follower replay regenerates clean case', 0 === $legacy_replay['code'], $legacy_replay['stdout'] . $legacy_replay['stderr'] );

$prefix_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'prefix-families', '--seed', '1', '--case', '37' ) );
check(
	'prefix-family replay regenerates clean case',
	0 === $prefix_replay['code'] &&
		str_contains( $prefix_replay['stdout'], 'mode prefix-families, strategy prefix-family-sweep' ) &&
		str_contains( $prefix_replay['stdout'], 'Hex preview: 266e6f7478' ),
	$prefix_replay['stdout'] . $prefix_replay['stderr']
);

$prefix_fault_seed_replay = run_process(
	array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'prefix-families', '--seed', '1', '--case', '37' ),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
);
check( 'faulted prefix-family seed replay reproduces generated case', 1 === $prefix_fault_seed_replay['code'], $prefix_fault_seed_replay['stdout'] . $prefix_fault_seed_replay['stderr'] );

$numeric_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'numeric-boundaries', '--seed', '1', '--case', '25' ) );
check(
	'numeric-boundary replay regenerates mixed-case hex case',
	0 === $numeric_replay['code'] &&
		str_contains( $numeric_replay['stdout'], 'mode numeric-boundaries, strategy numeric-boundary-sweep' ) &&
		str_contains( $numeric_replay['stdout'], 'Hex preview: 2623783130466645653b' ),
	$numeric_replay['stdout'] . $numeric_replay['stderr']
);

$numeric_fault_seed_replay = run_process(
	array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'numeric-boundaries', '--seed', '1', '--case', '0' ),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
check( 'faulted numeric-boundary seed replay reproduces generated case', 1 === $numeric_fault_seed_replay['code'], $numeric_fault_seed_replay['stdout'] . $numeric_fault_seed_replay['stderr'] );

$corpus_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'corpus', '--seed', '1', '--case', '0' ) );
check(
	'corpus mutation replay regenerates clean case',
	0 === $corpus_replay['code'] &&
		str_contains( $corpus_replay['stdout'], 'mode corpus, strategy corpus-byte-perturb' ) &&
		str_contains( $corpus_replay['stdout'], 'Hex preview: 64262335383b' ),
	$corpus_replay['stdout'] . $corpus_replay['stderr']
);

$single_level_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'corpus', '--seed', '1', '--case', '11875' ) );
check(
	'corpus replay regenerates single-level decode fixture',
	0 === $single_level_replay['code'] &&
		str_contains( $single_level_replay['stdout'], 'mode corpus, strategy corpus-splice' ) &&
		str_contains( $single_level_replay['stdout'], 'Hex preview: 26616d703b616d703b5a' ),
	$single_level_replay['stdout'] . $single_level_replay['stderr']
);

$corpus_fault_seed_replay = run_process(
	array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'corpus', '--seed', '1', '--case', '0' ),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
check( 'faulted corpus mutation seed replay reproduces generated case', 1 === $corpus_fault_seed_replay['code'], $corpus_fault_seed_replay['stdout'] . $corpus_fault_seed_replay['stderr'] );

$token_map_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'token-map', '--seed', '1', '--case', '0' ) );
check(
	'token-map replay regenerates clean case',
	0 === $token_map_replay['code'] &&
		str_contains( $token_map_replay['stdout'], 'mode token-map, strategy token-map-structure-sweep' ),
	$token_map_replay['stdout'] . $token_map_replay['stderr']
);

if ( null !== $token_map_fault_case_index ) {
	$token_map_fault_seed_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'token-map', '--seed', '1', '--case', (string) $token_map_fault_case_index ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted token-map seed replay reproduces generated case', 1 === $token_map_fault_seed_replay['code'], $token_map_fault_seed_replay['stdout'] . $token_map_fault_seed_replay['stderr'] );
}

$coverage_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'coverage', '--seed', '1', '--case', '0' ) );
check(
	'coverage replay regenerates clean generated case',
	0 === $coverage_replay['code'] &&
		str_contains( $coverage_replay['stdout'], 'mode coverage, strategy numeric' ),
	$coverage_replay['stdout'] . $coverage_replay['stderr']
);

$name_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-name-fault-' . getmypid();
remove_tree( $name_pipeline_dir );
$faulted_name_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'names',
		'--seed',
		'1',
		'--start-case',
		'11593',
		'--cases',
		'1',
		'--output-dir',
		$name_pipeline_dir,
		'--progress-every',
		'1',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
);
check( 'faulted name-sweep worker reports findings', 1 === $faulted_name_worker['code'], $faulted_name_worker['stdout'] . $faulted_name_worker['stderr'] );

$name_failure_files = glob( $name_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted name-sweep worker writes failure artifact', is_array( $name_failure_files ) && array() !== $name_failure_files );

$name_failure_file = is_array( $name_failure_files ) && array() !== $name_failure_files ? $name_failure_files[0] : null;
if ( null !== $name_failure_file ) {
	$name_manifest = json_decode( (string) file_get_contents( $name_failure_file ), true );
	check(
		'name-sweep failure artifact records mode and signature',
		'names' === ( $name_manifest['mode'] ?? null ) &&
			'name-sweep' === ( $name_manifest['strategy'] ?? null ) &&
			11593 === ( $name_manifest['case'] ?? null ) &&
			in_array( 'decode-mismatch:attribute', $name_manifest['signatures'] ?? array(), true ),
		json_encode( $name_manifest )
	);

	$name_fault_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $name_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted name-sweep replay reproduces finding', 1 === $name_fault_replay['code'], $name_fault_replay['stdout'] . $name_fault_replay['stderr'] );

	$name_fault_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $name_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted name-sweep minimizer preserves signature', 0 === $name_fault_minimize['code'], $name_fault_minimize['stdout'] . $name_fault_minimize['stderr'] );
}
remove_tree( $name_pipeline_dir );

$legacy_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-legacy-follower-fault-' . getmypid();
remove_tree( $legacy_pipeline_dir );
$faulted_legacy_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'legacy-followers',
		'--seed',
		'1',
		'--start-case',
		'0',
		'--cases',
		'80',
		'--output-dir',
		$legacy_pipeline_dir,
		'--progress-every',
		'80',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
);
check( 'faulted legacy-follower worker reports findings', 1 === $faulted_legacy_worker['code'], $faulted_legacy_worker['stdout'] . $faulted_legacy_worker['stderr'] );

$legacy_failure_files = glob( $legacy_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted legacy-follower worker writes failure artifact', is_array( $legacy_failure_files ) && array() !== $legacy_failure_files );

$legacy_failure_file = is_array( $legacy_failure_files ) && array() !== $legacy_failure_files ? $legacy_failure_files[0] : null;
if ( null !== $legacy_failure_file ) {
	$legacy_manifest = json_decode( (string) file_get_contents( $legacy_failure_file ), true );
	check(
		'legacy-follower failure artifact records mode and signature',
		'legacy-followers' === ( $legacy_manifest['mode'] ?? null ) &&
			'legacy-follower-sweep' === ( $legacy_manifest['strategy'] ?? null ) &&
			in_array( 'decode-mismatch:attribute', $legacy_manifest['signatures'] ?? array(), true ),
		json_encode( $legacy_manifest )
	);

	$legacy_fault_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $legacy_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted legacy-follower replay reproduces finding', 1 === $legacy_fault_replay['code'], $legacy_fault_replay['stdout'] . $legacy_fault_replay['stderr'] );

	$legacy_fault_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $legacy_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted legacy-follower minimizer preserves signature', 0 === $legacy_fault_minimize['code'], $legacy_fault_minimize['stdout'] . $legacy_fault_minimize['stderr'] );
}
remove_tree( $legacy_pipeline_dir );

$prefix_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-prefix-family-fault-' . getmypid();
remove_tree( $prefix_pipeline_dir );
$faulted_prefix_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'prefix-families',
		'--seed',
		'1',
		'--start-case',
		'37',
		'--cases',
		'1',
		'--output-dir',
		$prefix_pipeline_dir,
		'--progress-every',
		'1',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
);
check( 'faulted prefix-family worker reports findings', 1 === $faulted_prefix_worker['code'], $faulted_prefix_worker['stdout'] . $faulted_prefix_worker['stderr'] );

$prefix_failure_files = glob( $prefix_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted prefix-family worker writes failure artifact', is_array( $prefix_failure_files ) && array() !== $prefix_failure_files );

$prefix_failure_file = is_array( $prefix_failure_files ) && array() !== $prefix_failure_files ? $prefix_failure_files[0] : null;
if ( null !== $prefix_failure_file ) {
	$prefix_manifest = json_decode( (string) file_get_contents( $prefix_failure_file ), true );
	check(
		'prefix-family failure artifact records mode and signature',
		'prefix-families' === ( $prefix_manifest['mode'] ?? null ) &&
			'prefix-family-sweep' === ( $prefix_manifest['strategy'] ?? null ) &&
			37 === ( $prefix_manifest['case'] ?? null ) &&
			in_array( 'decode-mismatch:attribute', $prefix_manifest['signatures'] ?? array(), true ),
		json_encode( $prefix_manifest )
	);

	$prefix_fault_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $prefix_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted prefix-family replay reproduces finding', 1 === $prefix_fault_replay['code'], $prefix_fault_replay['stdout'] . $prefix_fault_replay['stderr'] );

	$prefix_fault_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $prefix_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted prefix-family minimizer preserves signature', 0 === $prefix_fault_minimize['code'], $prefix_fault_minimize['stdout'] . $prefix_fault_minimize['stderr'] );
}
remove_tree( $prefix_pipeline_dir );

$numeric_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-numeric-boundary-fault-' . getmypid();
remove_tree( $numeric_pipeline_dir );
$faulted_numeric_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'numeric-boundaries',
		'--seed',
		'1',
		'--start-case',
		'0',
		'--cases',
		'1',
		'--output-dir',
		$numeric_pipeline_dir,
		'--progress-every',
		'1',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
check( 'faulted numeric-boundary worker reports findings', 1 === $faulted_numeric_worker['code'], $faulted_numeric_worker['stdout'] . $faulted_numeric_worker['stderr'] );

$numeric_failure_files = glob( $numeric_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted numeric-boundary worker writes failure artifact', is_array( $numeric_failure_files ) && array() !== $numeric_failure_files );

$numeric_failure_file = is_array( $numeric_failure_files ) && array() !== $numeric_failure_files ? $numeric_failure_files[0] : null;
if ( null !== $numeric_failure_file ) {
	$numeric_manifest = json_decode( (string) file_get_contents( $numeric_failure_file ), true );
	check(
		'numeric-boundary failure artifact records mode and signature',
		'numeric-boundaries' === ( $numeric_manifest['mode'] ?? null ) &&
			'numeric-boundary-sweep' === ( $numeric_manifest['strategy'] ?? null ) &&
			0 === ( $numeric_manifest['case'] ?? null ) &&
			in_array( 'reader-overran-input:text', $numeric_manifest['signatures'] ?? array(), true ),
		json_encode( $numeric_manifest )
	);

	$numeric_fault_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $numeric_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
	);
	check( 'faulted numeric-boundary replay reproduces finding', 1 === $numeric_fault_replay['code'], $numeric_fault_replay['stdout'] . $numeric_fault_replay['stderr'] );

	$numeric_fault_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $numeric_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
	);
	check( 'faulted numeric-boundary minimizer preserves signature', 0 === $numeric_fault_minimize['code'], $numeric_fault_minimize['stdout'] . $numeric_fault_minimize['stderr'] );
}
remove_tree( $numeric_pipeline_dir );

$corpus_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-corpus-fault-' . getmypid();
remove_tree( $corpus_pipeline_dir );
$faulted_corpus_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'corpus',
		'--seed',
		'1',
		'--start-case',
		'0',
		'--cases',
		'1',
		'--output-dir',
		$corpus_pipeline_dir,
		'--progress-every',
		'1',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
check( 'faulted corpus mutation worker reports findings', 1 === $faulted_corpus_worker['code'], $faulted_corpus_worker['stdout'] . $faulted_corpus_worker['stderr'] );

$corpus_failure_files = glob( $corpus_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted corpus mutation worker writes failure artifact', is_array( $corpus_failure_files ) && array() !== $corpus_failure_files );

$corpus_failure_file = is_array( $corpus_failure_files ) && array() !== $corpus_failure_files ? $corpus_failure_files[0] : null;
if ( null !== $corpus_failure_file ) {
	$corpus_manifest = json_decode( (string) file_get_contents( $corpus_failure_file ), true );
	check(
		'corpus mutation failure artifact records mode and signature',
		'corpus' === ( $corpus_manifest['mode'] ?? null ) &&
			'corpus-byte-perturb' === ( $corpus_manifest['strategy'] ?? null ) &&
			0 === ( $corpus_manifest['case'] ?? null ) &&
			in_array( 'reader-overran-input:text', $corpus_manifest['signatures'] ?? array(), true ),
		json_encode( $corpus_manifest )
	);

	$corpus_fault_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $corpus_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
	);
	check( 'faulted corpus mutation replay reproduces finding', 1 === $corpus_fault_replay['code'], $corpus_fault_replay['stdout'] . $corpus_fault_replay['stderr'] );

	$corpus_fault_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $corpus_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
	);
	check( 'faulted corpus mutation minimizer preserves signature', 0 === $corpus_fault_minimize['code'], $corpus_fault_minimize['stdout'] . $corpus_fault_minimize['stderr'] );
}
remove_tree( $corpus_pipeline_dir );

$single_level_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-single-level-fault-' . getmypid();
remove_tree( $single_level_pipeline_dir );
$faulted_single_level_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'corpus',
		'--seed',
		'1',
		'--start-case',
		'11875',
		'--cases',
		'1',
		'--output-dir',
		$single_level_pipeline_dir,
		'--progress-every',
		'1',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'single-level-overdecode' )
);
check( 'faulted single-level corpus worker reports findings', 1 === $faulted_single_level_worker['code'], $faulted_single_level_worker['stdout'] . $faulted_single_level_worker['stderr'] );

$single_level_failure_files = glob( $single_level_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted single-level corpus worker writes failure artifact', is_array( $single_level_failure_files ) && array() !== $single_level_failure_files );

$single_level_failure_file = is_array( $single_level_failure_files ) && array() !== $single_level_failure_files ? $single_level_failure_files[0] : null;
if ( null !== $single_level_failure_file ) {
	$single_level_manifest = json_decode( (string) file_get_contents( $single_level_failure_file ), true );
	check(
		'single-level corpus failure artifact records mode and signature',
		'corpus' === ( $single_level_manifest['mode'] ?? null ) &&
			'corpus-splice' === ( $single_level_manifest['strategy'] ?? null ) &&
			11875 === ( $single_level_manifest['case'] ?? null ) &&
			in_array( 'single-level-decode-overdecoded:text', $single_level_manifest['signatures'] ?? array(), true ) &&
			in_array( 'single-level-decode-overdecoded:attribute', $single_level_manifest['signatures'] ?? array(), true ),
		json_encode( $single_level_manifest )
	);

	$single_level_fault_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $single_level_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'single-level-overdecode' )
	);
	check( 'faulted single-level corpus replay reproduces finding', 1 === $single_level_fault_replay['code'], $single_level_fault_replay['stdout'] . $single_level_fault_replay['stderr'] );

	$single_level_fault_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $single_level_failure_file, '--signature', 'single-level-decode-overdecoded:text' ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'single-level-overdecode' )
	);
	check( 'faulted single-level corpus minimizer preserves signature', 0 === $single_level_fault_minimize['code'], $single_level_fault_minimize['stdout'] . $single_level_fault_minimize['stderr'] );
}
remove_tree( $single_level_pipeline_dir );

if ( null !== $token_map_fault_case_index ) {
	$token_map_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-token-map-fault-' . getmypid();
	remove_tree( $token_map_pipeline_dir );
	$faulted_token_map_worker = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../worker.php',
			'--mode',
			'token-map',
			'--seed',
			'1',
			'--start-case',
			(string) $token_map_fault_case_index,
			'--cases',
			'1',
			'--output-dir',
			$token_map_pipeline_dir,
			'--progress-every',
			'1',
		),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
	);
	check( 'faulted token-map worker reports findings', 1 === $faulted_token_map_worker['code'], $faulted_token_map_worker['stdout'] . $faulted_token_map_worker['stderr'] );

	$token_map_failure_files = glob( $token_map_pipeline_dir . '/failure-*/failure.json' );
	check( 'faulted token-map worker writes failure artifact', is_array( $token_map_failure_files ) && array() !== $token_map_failure_files );

	$token_map_failure_file = is_array( $token_map_failure_files ) && array() !== $token_map_failure_files ? $token_map_failure_files[0] : null;
	if ( null !== $token_map_failure_file ) {
		$token_map_manifest = json_decode( (string) file_get_contents( $token_map_failure_file ), true );
		check(
			'token-map failure artifact records mode and signature',
			'token-map' === ( $token_map_manifest['mode'] ?? null ) &&
				'token-map-structure-sweep' === ( $token_map_manifest['strategy'] ?? null ) &&
				$token_map_fault_case_index === ( $token_map_manifest['case'] ?? null ) &&
				in_array( 'decode-mismatch:attribute', $token_map_manifest['signatures'] ?? array(), true ),
			json_encode( $token_map_manifest )
		);

		$token_map_fault_replay = run_process(
			array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $token_map_failure_file ),
			array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
		);
		check( 'faulted token-map replay reproduces finding', 1 === $token_map_fault_replay['code'], $token_map_fault_replay['stdout'] . $token_map_fault_replay['stderr'] );

		$token_map_fault_minimize = run_process(
			array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $token_map_failure_file ),
			array( 'HTML_DECODER_FUZZ_FAULT' => 'attribute-semicolonless' )
		);
		check( 'faulted token-map minimizer preserves signature', 0 === $token_map_fault_minimize['code'], $token_map_fault_minimize['stdout'] . $token_map_fault_minimize['stderr'] );
	}
	remove_tree( $token_map_pipeline_dir );
}

$coverage_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-coverage-fault-' . getmypid();
remove_tree( $coverage_pipeline_dir );
$faulted_coverage_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'coverage',
		'--seed',
		'1',
		'--start-case',
		'57',
		'--cases',
		'1',
		'--output-dir',
		$coverage_pipeline_dir,
		'--progress-every',
		'1',
	),
	array(
		'HTML_DECODER_FUZZ_FAKE_COVERAGE' => '1',
		'HTML_DECODER_FUZZ_FAULT'         => 'reader-empty-chunk',
	)
);
check( 'faulted coverage worker reports findings', 1 === $faulted_coverage_worker['code'], $faulted_coverage_worker['stdout'] . $faulted_coverage_worker['stderr'] );

$coverage_failure_files = glob( $coverage_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted coverage worker writes failure artifact', is_array( $coverage_failure_files ) && array() !== $coverage_failure_files );

$coverage_failure_file = is_array( $coverage_failure_files ) && array() !== $coverage_failure_files ? $coverage_failure_files[0] : null;
if ( null !== $coverage_failure_file ) {
	$coverage_manifest = json_decode( (string) file_get_contents( $coverage_failure_file ), true );
	check(
		'coverage failure artifact records mode and signature',
		'coverage' === ( $coverage_manifest['mode'] ?? null ) &&
			57 === ( $coverage_manifest['case'] ?? null ) &&
			in_array( 'reader-returned-empty-chunk:text', $coverage_manifest['signatures'] ?? array(), true ),
		json_encode( $coverage_manifest )
	);

	$coverage_fault_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $coverage_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'reader-empty-chunk' )
	);
	check( 'faulted coverage replay reproduces finding', 1 === $coverage_fault_replay['code'], $coverage_fault_replay['stdout'] . $coverage_fault_replay['stderr'] );

	$coverage_fault_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $coverage_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'reader-empty-chunk' )
	);
	check( 'faulted coverage minimizer preserves signature', 0 === $coverage_fault_minimize['code'], $coverage_fault_minimize['stdout'] . $coverage_fault_minimize['stderr'] );
}
remove_tree( $coverage_pipeline_dir );

$reader_fault_pipelines = array(
	array(
		'fault'     => 'reader-empty-chunk',
		'case'      => 57,
		'signature' => 'reader-returned-empty-chunk:text',
	),
	array(
		'fault'     => 'reader-short-match-length',
		'case'      => 57,
		'signature' => 'reader-match-too-short:text',
	),
	array(
		'fault'     => 'reader-substring-composition',
		'case'      => 97,
		'signature' => 'reader-composition-mismatch:text',
	),
	array(
		'fault'     => 'reader-null-mutates-match-length',
		'case'      => 7,
		'signature' => 'reader-mutated-match-length-on-null:text',
	),
	array(
		'fault'     => 'reader-non-amp-match',
		'case'      => 0,
		'signature' => 'reader-non-amp-match:text',
	),
	array(
		'fault'     => 'reader-gapless-drop-span',
		'case'      => 0,
		'signature' => 'reader-walk-not-gapless:text',
	),
	array(
		'fault'     => 'numeric-invalid-not-replacement',
		'case'      => 0,
		'signature' => 'numeric-invalid-not-replacement:text',
	),
	array(
		'fault'     => 'numeric-c1-not-remapped',
		'case'      => 2,
		'signature' => 'numeric-c1-not-remapped:text',
	),
	array(
		'fault'              => 'text-secondary-oracle',
		'case'               => 4,
		'signature'          => 'text-secondary-oracle-mismatch:text',
		'minimize_signature' => 'text-secondary-oracle-mismatch:text',
	),
	array(
		'fault'     => 'attribute-no-amp-identity',
		'case'      => 38,
		'signature' => 'attribute-without-ampersand-not-identity:attribute',
	),
);
foreach ( $reader_fault_pipelines as $reader_pipeline ) {
	$reader_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-' . $reader_pipeline['fault'] . '-' . getmypid();
	remove_tree( $reader_pipeline_dir );
	$faulted_reader_worker = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../worker.php',
			'--seed',
			'1',
			'--start-case',
			(string) $reader_pipeline['case'],
			'--cases',
			'1',
			'--output-dir',
			$reader_pipeline_dir,
			'--progress-every',
			'1',
		),
		array( 'HTML_DECODER_FUZZ_FAULT' => $reader_pipeline['fault'] )
	);
	check( "faulted {$reader_pipeline['fault']} worker reports findings", 1 === $faulted_reader_worker['code'], $faulted_reader_worker['stdout'] . $faulted_reader_worker['stderr'] );

	$reader_failure_files = glob( $reader_pipeline_dir . '/failure-*/failure.json' );
	check( "faulted {$reader_pipeline['fault']} worker writes failure artifact", is_array( $reader_failure_files ) && array() !== $reader_failure_files );

	$reader_failure_file = is_array( $reader_failure_files ) && array() !== $reader_failure_files ? $reader_failure_files[0] : null;
	if ( null !== $reader_failure_file ) {
		$reader_manifest = json_decode( (string) file_get_contents( $reader_failure_file ), true );
		check(
			"{$reader_pipeline['fault']} failure artifact records mode and signature",
			'oracle' === ( $reader_manifest['mode'] ?? null ) &&
				$reader_pipeline['case'] === ( $reader_manifest['case'] ?? null ) &&
				in_array( $reader_pipeline['signature'], $reader_manifest['signatures'] ?? array(), true ),
			json_encode( $reader_manifest )
		);

		$reader_fault_replay = run_process(
			array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $reader_failure_file ),
			array( 'HTML_DECODER_FUZZ_FAULT' => $reader_pipeline['fault'] )
		);
		check( "faulted {$reader_pipeline['fault']} replay reproduces finding", 1 === $reader_fault_replay['code'], $reader_fault_replay['stdout'] . $reader_fault_replay['stderr'] );

		$reader_fault_minimize_command = array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $reader_failure_file );
		if ( isset( $reader_pipeline['minimize_signature'] ) ) {
			$reader_fault_minimize_command[] = '--signature';
			$reader_fault_minimize_command[] = $reader_pipeline['minimize_signature'];
		}
		$reader_fault_minimize = run_process(
			$reader_fault_minimize_command,
			array( 'HTML_DECODER_FUZZ_FAULT' => $reader_pipeline['fault'] )
		);
		check( "faulted {$reader_pipeline['fault']} minimizer preserves signature", 0 === $reader_fault_minimize['code'], $reader_fault_minimize['stdout'] . $reader_fault_minimize['stderr'] );
	}
	remove_tree( $reader_pipeline_dir );
}

$zero_cases = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--cases', '0' ) );
check( 'worker rejects zero cases', 2 === $zero_cases['code'], $zero_cases['stdout'] . $zero_cases['stderr'] );

$zero_batch = run_process( array( PHP_BINARY, __DIR__ . '/../runner.php', '--cases-per-batch', '0', '--duration-seconds', '1', '--output-dir', sys_get_temp_dir() . '/html-decoder-fuzz-smoke-bad-runner-' . getmypid() ) );
check( 'runner rejects zero cases per batch', 2 === $zero_batch['code'], $zero_batch['stdout'] . $zero_batch['stderr'] );

$unwritable_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-unwritable-' . getmypid();
remove_tree( $unwritable_dir );
mkdir( $unwritable_dir, 0555, true );
chmod( $unwritable_dir, 0555 );
clearstatcache( true, $unwritable_dir );
$unwritable_runner = run_process( array( PHP_BINARY, __DIR__ . '/../runner.php', '--duration-seconds', '1', '--output-dir', $unwritable_dir ) );
chmod( $unwritable_dir, 0755 );
clearstatcache( true, $unwritable_dir );
remove_tree( $unwritable_dir );
check( 'runner rejects unwritable output dir', 2 === $unwritable_runner['code'], $unwritable_runner['stdout'] . $unwritable_runner['stderr'] );

$unreadable_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-unreadable-' . getmypid();
remove_tree( $unreadable_dir );
mkdir( $unreadable_dir, 0333, true );
chmod( $unreadable_dir, 0333 );
clearstatcache( true, $unreadable_dir );
$unreadable_runner = run_process( array( PHP_BINARY, __DIR__ . '/../runner.php', '--duration-seconds', '1', '--output-dir', $unreadable_dir ) );
chmod( $unreadable_dir, 0755 );
clearstatcache( true, $unreadable_dir );
remove_tree( $unreadable_dir );
check( 'runner rejects unreadable output dir', 2 === $unreadable_runner['code'], $unreadable_runner['stdout'] . $unreadable_runner['stderr'] );

$bad_state_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-bad-state-' . getmypid();
remove_tree( $bad_state_dir );
mkdir( $bad_state_dir, 0777, true );
mkdir( $bad_state_dir . '/state.json' );
$bad_state_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--output-dir',
		$bad_state_dir,
	)
);
remove_tree( $bad_state_dir );
check( 'runner reports state write failures', 2 === $bad_state_runner['code'], $bad_state_runner['stdout'] . $bad_state_runner['stderr'] );

$state_hardlink_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-state-hardlink-' . getmypid();
remove_tree( $state_hardlink_dir );
mkdir( $state_hardlink_dir, 0777, true );
$state_hardlink_target = $state_hardlink_dir . '-target';
file_put_contents( $state_hardlink_target, "sentinel\n" );
$state_hardlink_created = @link( $state_hardlink_target, $state_hardlink_dir . '/state.json' );
if ( $state_hardlink_created ) {
	$state_hardlink_runner = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../runner.php',
			'--lanes',
			'1',
			'--duration-seconds',
			'0',
			'--max-cases',
			'1',
			'--cases-per-batch',
			'1',
			'--summary-mode',
			'none',
			'--output-dir',
			$state_hardlink_dir,
		)
	);
	check(
		'runner rejects hardlinked state output',
		2 === $state_hardlink_runner['code'] && "sentinel\n" === file_get_contents( $state_hardlink_target ),
		$state_hardlink_runner['stdout'] . $state_hardlink_runner['stderr']
	);
} else {
	check( 'runner rejects hardlinked state output', true, 'hardlink unavailable' );
}
remove_tree( $state_hardlink_dir );
@unlink( $state_hardlink_target );

$bad_summary_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-bad-summary-' . getmypid();
remove_tree( $bad_summary_dir );
mkdir( $bad_summary_dir, 0777, true );
mkdir( $bad_summary_dir . '/summary.ndjson' );
$bad_summary_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--output-dir',
		$bad_summary_dir,
	)
);
remove_tree( $bad_summary_dir );
check( 'runner reports summary open failures', 2 === $bad_summary_runner['code'], $bad_summary_runner['stdout'] . $bad_summary_runner['stderr'] );

$summary_symlink_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-summary-symlink-' . getmypid();
remove_tree( $summary_symlink_dir );
mkdir( $summary_symlink_dir, 0777, true );
$summary_symlink_target = $summary_symlink_dir . '-target';
file_put_contents( $summary_symlink_target, "sentinel\n" );
$summary_symlink_created = @symlink( $summary_symlink_target, $summary_symlink_dir . '/summary.ndjson' );
if ( $summary_symlink_created ) {
	$summary_symlink_runner = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../runner.php',
			'--lanes',
			'1',
			'--duration-seconds',
			'0',
			'--max-cases',
			'1',
			'--cases-per-batch',
			'1',
			'--output-dir',
			$summary_symlink_dir,
		)
	);
	check(
		'runner rejects symlinked summary output',
		2 === $summary_symlink_runner['code'] && "sentinel\n" === file_get_contents( $summary_symlink_target ),
		$summary_symlink_runner['stdout'] . $summary_symlink_runner['stderr']
	);
} else {
	check( 'runner rejects symlinked summary output', true, 'symlink unavailable' );
}
remove_tree( $summary_symlink_dir );
@unlink( $summary_symlink_target );

$summary_hardlink_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-summary-hardlink-' . getmypid();
remove_tree( $summary_hardlink_dir );
mkdir( $summary_hardlink_dir, 0777, true );
$summary_hardlink_target = $summary_hardlink_dir . '-target';
file_put_contents( $summary_hardlink_target, "sentinel\n" );
$summary_hardlink_created = @link( $summary_hardlink_target, $summary_hardlink_dir . '/summary.ndjson' );
if ( $summary_hardlink_created ) {
	$summary_hardlink_runner = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../runner.php',
			'--lanes',
			'1',
			'--duration-seconds',
			'0',
			'--max-cases',
			'1',
			'--cases-per-batch',
			'1',
			'--output-dir',
			$summary_hardlink_dir,
		)
	);
	check(
		'runner rejects hardlinked summary output',
		2 === $summary_hardlink_runner['code'] && "sentinel\n" === file_get_contents( $summary_hardlink_target ),
		$summary_hardlink_runner['stdout'] . $summary_hardlink_runner['stderr']
	);
} else {
	check( 'runner rejects hardlinked summary output', true, 'hardlink unavailable' );
}
remove_tree( $summary_hardlink_dir );
@unlink( $summary_hardlink_target );

$lane_stderr_symlink_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-lane-stderr-symlink-' . getmypid();
remove_tree( $lane_stderr_symlink_dir );
mkdir( $lane_stderr_symlink_dir, 0777, true );
$lane_stderr_symlink_target = $lane_stderr_symlink_dir . '-target';
file_put_contents( $lane_stderr_symlink_target, "sentinel\n" );
$lane_stderr_symlink_created = @symlink( $lane_stderr_symlink_target, $lane_stderr_symlink_dir . '/lane-0-stderr.log' );
if ( $lane_stderr_symlink_created ) {
	$lane_stderr_symlink_runner = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../runner.php',
			'--lanes',
			'1',
			'--duration-seconds',
			'0',
			'--max-cases',
			'1',
			'--cases-per-batch',
			'1',
			'--output-dir',
			$lane_stderr_symlink_dir,
		)
	);
	check(
		'runner rejects symlinked lane stderr output',
		2 === $lane_stderr_symlink_runner['code'] && "sentinel\n" === file_get_contents( $lane_stderr_symlink_target ),
		$lane_stderr_symlink_runner['stdout'] . $lane_stderr_symlink_runner['stderr']
	);
} else {
	check( 'runner rejects symlinked lane stderr output', true, 'symlink unavailable' );
}
remove_tree( $lane_stderr_symlink_dir );
@unlink( $lane_stderr_symlink_target );

$lane_stderr_hardlink_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-lane-stderr-hardlink-' . getmypid();
remove_tree( $lane_stderr_hardlink_dir );
mkdir( $lane_stderr_hardlink_dir, 0777, true );
$lane_stderr_hardlink_target = $lane_stderr_hardlink_dir . '-target';
file_put_contents( $lane_stderr_hardlink_target, "sentinel\n" );
$lane_stderr_hardlink_created = @link( $lane_stderr_hardlink_target, $lane_stderr_hardlink_dir . '/lane-0-stderr.log' );
if ( $lane_stderr_hardlink_created ) {
	$lane_stderr_hardlink_runner = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../runner.php',
			'--lanes',
			'1',
			'--duration-seconds',
			'0',
			'--max-cases',
			'1',
			'--cases-per-batch',
			'1',
			'--output-dir',
			$lane_stderr_hardlink_dir,
		)
	);
	check(
		'runner rejects hardlinked lane stderr output',
		2 === $lane_stderr_hardlink_runner['code'] && "sentinel\n" === file_get_contents( $lane_stderr_hardlink_target ),
		$lane_stderr_hardlink_runner['stdout'] . $lane_stderr_hardlink_runner['stderr']
	);
} else {
	check( 'runner rejects hardlinked lane stderr output', true, 'hardlink unavailable' );
}
remove_tree( $lane_stderr_hardlink_dir );
@unlink( $lane_stderr_hardlink_target );

$lane_stderr_fifo_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-lane-stderr-fifo-' . getmypid();
remove_tree( $lane_stderr_fifo_dir );
mkdir( $lane_stderr_fifo_dir, 0777, true );
$lane_stderr_fifo_created = function_exists( 'posix_mkfifo' ) && @posix_mkfifo( $lane_stderr_fifo_dir . '/lane-0-stderr.log', 0600 );
if ( $lane_stderr_fifo_created ) {
	$lane_stderr_fifo_runner = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../runner.php',
			'--lanes',
			'1',
			'--duration-seconds',
			'0',
			'--max-cases',
			'1',
			'--cases-per-batch',
			'1',
			'--output-dir',
			$lane_stderr_fifo_dir,
		)
	);
	check(
		'runner rejects non-regular lane stderr output',
		2 === $lane_stderr_fifo_runner['code'],
		$lane_stderr_fifo_runner['stdout'] . $lane_stderr_fifo_runner['stderr']
	);
} else {
	check( 'runner rejects non-regular lane stderr output', true, 'fifo unavailable' );
}
remove_tree( $lane_stderr_fifo_dir );

$lane_stderr_cap_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-lane-stderr-cap-' . getmypid();
remove_tree( $lane_stderr_cap_dir );
$lane_stderr_cap_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'40',
		'--cases-per-batch',
		'20',
		'--max-stderr-bytes',
		'128',
		'--output-dir',
		$lane_stderr_cap_dir,
	),
	array( 'HTML_DECODER_FUZZ_STDERR_BYTES_PER_CASE' => '100' )
);
$lane_stderr_cap_state = is_file( $lane_stderr_cap_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $lane_stderr_cap_dir . '/state.json' ), true )
	: array();
$lane_stderr_cap_size = is_file( $lane_stderr_cap_dir . '/lane-0-stderr.log' ) ? filesize( $lane_stderr_cap_dir . '/lane-0-stderr.log' ) : 0;
check(
	'runner caps per-lane stderr logs',
	0 === $lane_stderr_cap_runner['code'] &&
		$lane_stderr_cap_size <= 128 &&
		1 === count( $lane_stderr_cap_state['worker_stderr_truncated'] ?? array() ),
	$lane_stderr_cap_runner['stdout'] . $lane_stderr_cap_runner['stderr'] . ' stderr_size=' . $lane_stderr_cap_size . ' state=' . json_encode( $lane_stderr_cap_state['worker_stderr_truncated'] ?? null )
);
$lane_stderr_cap_reuse_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'40',
		'--cases-per-batch',
		'20',
		'--max-stderr-bytes',
		'128',
		'--output-dir',
		$lane_stderr_cap_dir,
	),
	array( 'HTML_DECODER_FUZZ_STDERR_BYTES_PER_CASE' => '100' )
);
$lane_stderr_cap_reuse_size = is_file( $lane_stderr_cap_dir . '/lane-0-stderr.log' ) ? filesize( $lane_stderr_cap_dir . '/lane-0-stderr.log' ) : 0;
check(
	'runner preserves per-lane stderr cap on reused output dirs',
	0 === $lane_stderr_cap_reuse_runner['code'] && $lane_stderr_cap_reuse_size <= 128,
	$lane_stderr_cap_reuse_runner['stdout'] . $lane_stderr_cap_reuse_runner['stderr'] . ' stderr_size=' . $lane_stderr_cap_reuse_size
);
remove_tree( $lane_stderr_cap_dir );

$lane_stderr_oversize_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-lane-stderr-oversize-' . getmypid();
remove_tree( $lane_stderr_oversize_dir );
mkdir( $lane_stderr_oversize_dir, 0777, true );
file_put_contents( $lane_stderr_oversize_dir . '/lane-0-stderr.log', str_repeat( 'X', 512 ) );
$lane_stderr_oversize_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--max-stderr-bytes',
		'128',
		'--output-dir',
		$lane_stderr_oversize_dir,
	)
);
$lane_stderr_oversize_state = is_file( $lane_stderr_oversize_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $lane_stderr_oversize_dir . '/state.json' ), true )
	: array();
$lane_stderr_oversize_size = is_file( $lane_stderr_oversize_dir . '/lane-0-stderr.log' ) ? filesize( $lane_stderr_oversize_dir . '/lane-0-stderr.log' ) : 0;
check(
	'runner truncates oversized reused lane stderr logs',
	0 === $lane_stderr_oversize_runner['code'] &&
		$lane_stderr_oversize_size <= 128 &&
		array() !== ( $lane_stderr_oversize_state['worker_stderr_startup_truncated'] ?? array() ),
	$lane_stderr_oversize_runner['stdout'] . $lane_stderr_oversize_runner['stderr'] . ' stderr_size=' . $lane_stderr_oversize_size . ' state=' . json_encode( $lane_stderr_oversize_state['worker_stderr_startup_truncated'] ?? null )
);
remove_tree( $lane_stderr_oversize_dir );

$lane_stderr_stale_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-lane-stderr-stale-' . getmypid();
remove_tree( $lane_stderr_stale_dir );
mkdir( $lane_stderr_stale_dir, 0777, true );
file_put_contents( $lane_stderr_stale_dir . '/lane-1-stderr.log', str_repeat( 'X', 512 ) );
$lane_stderr_stale_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--max-stderr-bytes',
		'128',
		'--output-dir',
		$lane_stderr_stale_dir,
	)
);
$lane_stderr_stale_size = is_file( $lane_stderr_stale_dir . '/lane-1-stderr.log' ) ? filesize( $lane_stderr_stale_dir . '/lane-1-stderr.log' ) : 0;
check(
	'runner truncates stale stderr logs from inactive lanes',
	0 === $lane_stderr_stale_runner['code'] && $lane_stderr_stale_size <= 128,
	$lane_stderr_stale_runner['stdout'] . $lane_stderr_stale_runner['stderr'] . ' stderr_size=' . $lane_stderr_stale_size
);
remove_tree( $lane_stderr_stale_dir );

$no_summary_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-no-summary-' . getmypid();
remove_tree( $no_summary_dir );
$no_summary_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--summary-mode',
		'none',
		'--output-dir',
		$no_summary_dir,
	)
);
check(
	'runner can disable summary output',
	0 === $no_summary_runner['code'] && ! file_exists( $no_summary_dir . '/summary.ndjson' ),
	$no_summary_runner['stdout'] . $no_summary_runner['stderr']
);
remove_tree( $no_summary_dir );

$partial_artifact_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-partial-artifact-' . getmypid();
remove_tree( $partial_artifact_dir );
mkdir( $partial_artifact_dir . '/failure-orphan', 0777, true );
file_put_contents( $partial_artifact_dir . '/failure-orphan/payload.txt', 'orphaned payload' );
$partial_artifact_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--artifact-retention',
		'none',
		'--output-dir',
		$partial_artifact_dir,
	)
);
$partial_artifact_state = is_file( $partial_artifact_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $partial_artifact_dir . '/state.json' ), true )
	: array();
check(
	'runner prunes partial failure artifacts on startup',
	0 === $partial_artifact_runner['code'] &&
		! is_dir( $partial_artifact_dir . '/failure-orphan' ) &&
		( $partial_artifact_state['artifact_retention']['startup_pruned_partial'] ?? 0 ) > 0,
	$partial_artifact_runner['stdout'] . $partial_artifact_runner['stderr'] . json_encode( $partial_artifact_state['artifact_retention'] ?? null )
);
remove_tree( $partial_artifact_dir );

$symlink_artifact_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-symlink-artifact-' . getmypid();
remove_tree( $symlink_artifact_dir );
mkdir( $symlink_artifact_dir . '/keepdir', 0777, true );
file_put_contents( $symlink_artifact_dir . '/keepdir/important.txt', 'keep me' );
$symlink_created = @symlink( $symlink_artifact_dir . '/keepdir', $symlink_artifact_dir . '/failure-link' );
if ( $symlink_created ) {
	$symlink_artifact_runner = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../runner.php',
			'--lanes',
			'1',
			'--duration-seconds',
			'0',
			'--max-cases',
			'1',
			'--cases-per-batch',
			'1',
			'--artifact-retention',
			'none',
			'--output-dir',
			$symlink_artifact_dir,
		)
	);
	check(
		'runner prunes artifact symlinks without deleting targets',
		0 === $symlink_artifact_runner['code'] &&
			! file_exists( $symlink_artifact_dir . '/failure-link' ) &&
			is_file( $symlink_artifact_dir . '/keepdir/important.txt' ),
		$symlink_artifact_runner['stdout'] . $symlink_artifact_runner['stderr']
	);
} else {
	check( 'runner prunes artifact symlinks without deleting targets', true, 'symlink unavailable' );
}
remove_tree( $symlink_artifact_dir );

$glob_meta_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-glob-meta-' . getmypid();
remove_tree( $glob_meta_dir );
mkdir( $glob_meta_dir . '/run-*', 0777, true );
mkdir( $glob_meta_dir . '/run-victim/keepdir', 0777, true );
file_put_contents( $glob_meta_dir . '/run-victim/keepdir/important.txt', 'keep me' );
$glob_meta_symlink_created = @symlink( $glob_meta_dir . '/run-victim/keepdir', $glob_meta_dir . '/run-victim/failure-link' );
$glob_meta_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--artifact-retention',
		'none',
		'--output-dir',
		$glob_meta_dir . '/run-*',
	)
);
check(
	'runner treats output dir metacharacters literally during startup pruning',
	0 === $glob_meta_runner['code'] &&
		( ! $glob_meta_symlink_created || file_exists( $glob_meta_dir . '/run-victim/failure-link' ) ) &&
		is_file( $glob_meta_dir . '/run-victim/keepdir/important.txt' ),
	$glob_meta_runner['stdout'] . $glob_meta_runner['stderr']
);
remove_tree( $glob_meta_dir );

$symlink_write_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-symlink-write-' . getmypid();
remove_tree( $symlink_write_dir );
mkdir( $symlink_write_dir . '/keepdir', 0777, true );
file_put_contents(
	$symlink_write_dir . '/keepdir/failure.json',
	json_encode(
		array(
			'signatures' => array( 'decode-mismatch:text', 'reader-decode-mismatch:text' ),
		)
	)
);
$symlink_write_created = @symlink( $symlink_write_dir . '/keepdir', $symlink_write_dir . "/failure-seed{$skip_c1_fault_seed}-case{$skip_c1_fault_case}" );
if ( $symlink_write_created ) {
	$symlink_write_worker = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../worker.php',
			'--seed',
			(string) $skip_c1_fault_seed,
			'--cases',
			'200',
			'--output-dir',
			$symlink_write_dir,
			'--progress-every',
			'200',
		),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	$symlink_write_suffixed = glob( $symlink_write_dir . "/failure-seed{$skip_c1_fault_seed}-case{$skip_c1_fault_case}-sig*/failure.json" );
	check(
		'worker does not write through symlinked failure artifact dirs',
		1 === $symlink_write_worker['code'] &&
			! is_file( $symlink_write_dir . '/keepdir/payload.txt' ) &&
			is_array( $symlink_write_suffixed ) &&
			array() !== $symlink_write_suffixed,
		$symlink_write_worker['stdout'] . $symlink_write_worker['stderr']
	);
} else {
	check( 'worker does not write through symlinked failure artifact dirs', true, 'symlink unavailable' );
}
remove_tree( $symlink_write_dir );

$incomplete_manifest_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-incomplete-manifest-' . getmypid();
remove_tree( $incomplete_manifest_dir );
mkdir( $incomplete_manifest_dir . '/failure-bad', 0777, true );
file_put_contents(
	$incomplete_manifest_dir . '/failure-bad/failure.json',
	json_encode(
		array(
			'signatures'     => array( 'reader-decode-mismatch:text' ),
			'context'        => 'text',
			'payload_base64' => '',
		)
	)
);
$incomplete_manifest_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--seed-base',
		'1',
		'--cases-per-batch',
		'1',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$incomplete_manifest_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
$incomplete_manifest_state = is_file( $incomplete_manifest_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $incomplete_manifest_dir . '/state.json' ), true )
	: array();
$incomplete_manifest_files = glob( $incomplete_manifest_dir . '/failure-*/failure.json' );
$retained_manifest         = is_array( $incomplete_manifest_files ) && 1 === count( $incomplete_manifest_files )
	? json_decode( (string) file_get_contents( $incomplete_manifest_files[0] ), true )
	: array();
check(
	'runner ignores incomplete manifests when enforcing retention cap',
	1 === $incomplete_manifest_runner['code'] &&
		! is_dir( $incomplete_manifest_dir . '/failure-bad' ) &&
		( $incomplete_manifest_state['artifact_retention']['startup_pruned_partial'] ?? 0 ) > 0 &&
		is_array( $retained_manifest ) &&
		isset( $retained_manifest['payload_base64'] ),
	$incomplete_manifest_runner['stdout'] . $incomplete_manifest_runner['stderr'] . json_encode( $incomplete_manifest_state['artifact_retention'] ?? null )
);
remove_tree( $incomplete_manifest_dir );

$nonreproducing_manifest_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-nonreproducing-manifest-' . getmypid();
remove_tree( $nonreproducing_manifest_dir );
mkdir( $nonreproducing_manifest_dir . '/failure-fake', 0777, true );
$fake_payload = 'plain text';
file_put_contents(
	$nonreproducing_manifest_dir . '/failure-fake/failure.json',
	json_encode(
		array(
			'signatures'     => array( 'reader-decode-mismatch:text' ),
			'context'        => 'text',
			'input_size'     => strlen( $fake_payload ),
			'payload_base64' => base64_encode( $fake_payload ),
			'failures'       => array(
				array( 'signature' => 'reader-decode-mismatch:text' ),
			),
		)
	)
);
$nonreproducing_manifest_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--seed-base',
		'1',
		'--cases-per-batch',
		'1',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$nonreproducing_manifest_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
$nonreproducing_manifest_state = is_file( $nonreproducing_manifest_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $nonreproducing_manifest_dir . '/state.json' ), true )
	: array();
$nonreproducing_manifest_files = glob( $nonreproducing_manifest_dir . '/failure-*/failure.json' );
$nonreproducing_retained       = is_array( $nonreproducing_manifest_files ) && 1 === count( $nonreproducing_manifest_files )
	? json_decode( (string) file_get_contents( $nonreproducing_manifest_files[0] ), true )
	: array();
check(
	'runner ignores non-reproducing manifests when enforcing retention cap',
	1 === $nonreproducing_manifest_runner['code'] &&
		! is_dir( $nonreproducing_manifest_dir . '/failure-fake' ) &&
		( $nonreproducing_manifest_state['artifact_retention']['startup_pruned_partial'] ?? 0 ) > 0 &&
		is_array( $nonreproducing_retained ) &&
		isset( $nonreproducing_retained['payload_base64'] ) &&
		'plain text' !== base64_decode( $nonreproducing_retained['payload_base64'], true ),
	$nonreproducing_manifest_runner['stdout'] . $nonreproducing_manifest_runner['stderr'] . json_encode( $nonreproducing_manifest_state['artifact_retention'] ?? null )
);
remove_tree( $nonreproducing_manifest_dir );

$unverified_manifest_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-unverified-manifest-' . getmypid();
remove_tree( $unverified_manifest_dir );
$unverified_seed_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$unverified_manifest_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$unverified_before = glob( $unverified_manifest_dir . '/failure-*/failure.json' );
$unverified_runner = run_process(
	array(
		PHP_BINARY,
		'-d',
		'disable_functions=mb_check_encoding',
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--seed-base',
		'9999',
		'--cases-per-batch',
		'1',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$unverified_manifest_dir,
	)
);
$unverified_after = glob( $unverified_manifest_dir . '/failure-*/failure.json' );
$unverified_state = is_file( $unverified_manifest_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $unverified_manifest_dir . '/state.json' ), true )
	: array();
check(
	'runner preserves retained artifacts when startup verification is unavailable',
	1 === $unverified_seed_runner['code'] &&
		0 === $unverified_runner['code'] &&
		is_array( $unverified_before ) &&
		is_array( $unverified_after ) &&
		count( $unverified_before ) === count( $unverified_after ) &&
		( false !== ( $unverified_state['artifact_retention']['startup_verification_unavailable'] ?? false ) ),
	$unverified_seed_runner['stdout'] . $unverified_seed_runner['stderr'] . $unverified_runner['stdout'] . $unverified_runner['stderr'] . json_encode( $unverified_state['artifact_retention'] ?? null )
);
remove_tree( $unverified_manifest_dir );

$unverified_weak_manifest_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-unverified-weak-manifest-' . getmypid();
remove_tree( $unverified_weak_manifest_dir );
$unverified_weak_seed_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$unverified_weak_manifest_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
mkdir( $unverified_weak_manifest_dir . '/failure-000weak', 0777, true );
file_put_contents(
	$unverified_weak_manifest_dir . '/failure-000weak/failure.json',
	json_encode(
		array(
			'signatures'     => array( 'decode-mismatch:text', 'reader-decode-mismatch:text' ),
			'context'        => 'text',
			'payload_base64' => base64_encode( 'x' ),
		)
	)
);
$unverified_weak_runner = run_process(
	array(
		PHP_BINARY,
		'-d',
		'disable_functions=mb_check_encoding',
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--seed-base',
		'9999',
		'--cases-per-batch',
		'1',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$unverified_weak_manifest_dir,
	)
);
$unverified_weak_state = is_file( $unverified_weak_manifest_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $unverified_weak_manifest_dir . '/state.json' ), true )
	: array();
check(
	'runner ignores weak manifests when startup verification is unavailable',
	1 === $unverified_weak_seed_runner['code'] &&
		0 === $unverified_weak_runner['code'] &&
		! is_dir( $unverified_weak_manifest_dir . '/failure-000weak' ) &&
		is_file( $unverified_weak_manifest_dir . "/failure-seed{$skip_c1_fault_seed}-case{$skip_c1_fault_case}/failure.json" ) &&
		( $unverified_weak_state['artifact_retention']['startup_pruned_partial'] ?? 0 ) > 0 &&
		( false !== ( $unverified_weak_state['artifact_retention']['startup_verification_unavailable'] ?? false ) ),
	$unverified_weak_seed_runner['stdout'] . $unverified_weak_seed_runner['stderr'] . $unverified_weak_runner['stdout'] . $unverified_weak_runner['stderr'] . json_encode( $unverified_weak_state['artifact_retention'] ?? null )
);
remove_tree( $unverified_weak_manifest_dir );

$unverified_fake_manifest_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-unverified-fake-manifest-' . getmypid();
remove_tree( $unverified_fake_manifest_dir );
$unverified_fake_seed_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$unverified_fake_manifest_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$fake_payload = 'x';
mkdir( $unverified_fake_manifest_dir . '/failure-000fake', 0777, true );
file_put_contents(
	$unverified_fake_manifest_dir . '/failure-000fake/failure.json',
	json_encode(
		array(
			'signatures'     => array( 'decode-mismatch:text', 'reader-decode-mismatch:text' ),
			'context'        => 'text',
			'input_size'     => strlen( $fake_payload ),
			'payload_base64' => base64_encode( $fake_payload ),
			'failures'       => array(
				array( 'signature' => 'decode-mismatch:text' ),
				array( 'signature' => 'reader-decode-mismatch:text' ),
			),
		)
	)
);
$unverified_fake_runner = run_process(
	array(
		PHP_BINARY,
		'-d',
		'disable_functions=mb_check_encoding',
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--seed-base',
		'9999',
		'--cases-per-batch',
		'1',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$unverified_fake_manifest_dir,
	)
);
$unverified_fake_state = is_file( $unverified_fake_manifest_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $unverified_fake_manifest_dir . '/state.json' ), true )
	: array();
$unverified_fake_counts = $unverified_fake_state['artifact_retention']['retained_by_signature'] ?? array();
check(
	'runner preserves real artifacts when startup verification cannot reject full-shape fakes',
	1 === $unverified_fake_seed_runner['code'] &&
		0 === $unverified_fake_runner['code'] &&
		is_file( $unverified_fake_manifest_dir . '/failure-000fake/failure.json' ) &&
		is_file( $unverified_fake_manifest_dir . "/failure-seed{$skip_c1_fault_seed}-case{$skip_c1_fault_case}/failure.json" ) &&
		array_sum( is_array( $unverified_fake_counts ) ? $unverified_fake_counts : array() ) >= 2 &&
		( false !== ( $unverified_fake_state['artifact_retention']['startup_verification_unavailable'] ?? false ) ),
	$unverified_fake_seed_runner['stdout'] . $unverified_fake_seed_runner['stderr'] . $unverified_fake_runner['stdout'] . $unverified_fake_runner['stderr'] . json_encode( $unverified_fake_state['artifact_retention'] ?? null )
);
remove_tree( $unverified_fake_manifest_dir );

$bad_integer = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--cases', 'abc' ) );
check( 'worker rejects non-numeric integer options', 2 === $bad_integer['code'], $bad_integer['stdout'] . $bad_integer['stderr'] );

$huge_integer = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--cases', '999999999999999999999999999999999999999' ) );
check( 'worker rejects out-of-range integer options', 2 === $huge_integer['code'], $huge_integer['stdout'] . $huge_integer['stderr'] );

$byte_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-byte-' . getmypid();
remove_tree( $byte_pipeline_dir );
$faulted_byte_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'bytes',
		'--seed',
		'1',
		'--cases',
		'200',
		'--output-dir',
		$byte_pipeline_dir,
		'--progress-every',
		'200',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'byte-no-amp-identity' )
);
check( 'faulted byte-space worker reports findings', 1 === $faulted_byte_worker['code'], $faulted_byte_worker['stdout'] . $faulted_byte_worker['stderr'] );

$byte_failure_files = glob( $byte_pipeline_dir . '/failure-*/failure.json' );
check( 'faulted byte-space worker writes failure artifact', is_array( $byte_failure_files ) && array() !== $byte_failure_files );

$byte_failure_file = is_array( $byte_failure_files ) && array() !== $byte_failure_files ? $byte_failure_files[0] : null;
if ( null !== $byte_failure_file ) {
	$byte_manifest = json_decode( (string) file_get_contents( $byte_failure_file ), true );
	check( 'byte-space failure artifact records mode', 'bytes' === ( $byte_manifest['mode'] ?? null ) );

	$byte_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $byte_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'byte-no-amp-identity' )
	);
	check( 'faulted byte-space replay reproduces finding', 1 === $byte_replay['code'], $byte_replay['stdout'] . $byte_replay['stderr'] );

	$byte_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $byte_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'byte-no-amp-identity' )
	);
	check( 'faulted byte-space minimizer preserves signature', 0 === $byte_minimize['code'], $byte_minimize['stdout'] . $byte_minimize['stderr'] );
}
remove_tree( $byte_pipeline_dir );

$raw_c1_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-raw-c1-' . getmypid();
remove_tree( $raw_c1_pipeline_dir );
$faulted_raw_c1_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'bytes',
		'--seed',
		'1',
		'--start-case',
		'3',
		'--cases',
		'1',
		'--output-dir',
		$raw_c1_pipeline_dir,
		'--progress-every',
		'1',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'raw-c1-not-pass-through' )
);
check( 'faulted raw-C1 byte worker reports findings', 1 === $faulted_raw_c1_worker['code'], $faulted_raw_c1_worker['stdout'] . $faulted_raw_c1_worker['stderr'] );

$raw_c1_failure_file = $raw_c1_pipeline_dir . '/failure-seed1-case3/failure.json';
check( 'faulted raw-C1 byte worker writes failure artifact', is_file( $raw_c1_failure_file ) );

if ( is_file( $raw_c1_failure_file ) ) {
	$raw_c1_manifest = json_decode( (string) file_get_contents( $raw_c1_failure_file ), true );
	check(
		'raw-C1 byte failure artifact records mode and signature',
		'bytes' === ( $raw_c1_manifest['mode'] ?? null ) &&
			in_array( 'raw-c1-not-pass-through:text', $raw_c1_manifest['signatures'] ?? array(), true ),
		json_encode( $raw_c1_manifest )
	);

	$raw_c1_replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $raw_c1_failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'raw-c1-not-pass-through' )
	);
	check( 'faulted raw-C1 byte replay reproduces finding', 1 === $raw_c1_replay['code'], $raw_c1_replay['stdout'] . $raw_c1_replay['stderr'] );

	$raw_c1_minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $raw_c1_failure_file, '--signature', 'raw-c1-not-pass-through:text' ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'raw-c1-not-pass-through' )
	);
	check( 'faulted raw-C1 byte minimizer preserves signature', 0 === $raw_c1_minimize['code'], $raw_c1_minimize['stdout'] . $raw_c1_minimize['stderr'] );
}
remove_tree( $raw_c1_pipeline_dir );

$byte_mode_collision_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-byte-mode-collision-' . getmypid();
remove_tree( $byte_mode_collision_dir );
mkdir( $byte_mode_collision_dir . '/failure-seed1-case3', 0777, true );
file_put_contents(
	$byte_mode_collision_dir . '/failure-seed1-case3/failure.json',
	json_encode(
		array(
			'signatures' => array(
				'text-without-ampersand-not-identity:text',
				'reader-decode-mismatch:text',
				'attribute-without-ampersand-not-identity:attribute',
				'reader-decode-mismatch:attribute',
			),
		)
	)
);
$byte_mode_collision_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--mode',
		'bytes',
		'--seed',
		'1',
		'--cases',
		'4',
		'--output-dir',
		$byte_mode_collision_dir,
		'--progress-every',
		'4',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'byte-no-amp-identity' )
);
$byte_mode_collision_suffixed = glob( $byte_mode_collision_dir . '/failure-seed1-case3-sig*/failure.json' );
check(
	'worker separates same-signature artifacts by mode',
	1 === $byte_mode_collision_worker['code'] &&
		is_file( $byte_mode_collision_dir . '/failure-seed1-case3/failure.json' ) &&
		is_array( $byte_mode_collision_suffixed ) &&
		array() !== $byte_mode_collision_suffixed,
	$byte_mode_collision_worker['stdout'] . $byte_mode_collision_worker['stderr']
);
remove_tree( $byte_mode_collision_dir );

$byte_runner_pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-byte-runner-fault-' . getmypid();
remove_tree( $byte_runner_pipeline_dir );
$faulted_byte_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'bytes',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$byte_runner_pipeline_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'byte-no-amp-identity' )
);
$faulted_byte_runner_state = is_file( $byte_runner_pipeline_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $byte_runner_pipeline_dir . '/state.json' ), true )
	: array();
$faulted_byte_runner_modes = array_unique( array_map( static fn( $seed ): string => $seed['mode'] ?? '', $faulted_byte_runner_state['failure_seeds'] ?? array() ) );
check(
	'faulted byte-space runner reports findings',
	1 === $faulted_byte_runner['code'] &&
		( $faulted_byte_runner_state['failures'] ?? 0 ) > 0 &&
		array( 'bytes' ) === array_values( $faulted_byte_runner_modes ),
	$faulted_byte_runner['stdout'] . $faulted_byte_runner['stderr'] . json_encode( $faulted_byte_runner_state )
);
remove_tree( $byte_runner_pipeline_dir );

$mixed_mode_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-mixed-mode-runner-' . getmypid();
remove_tree( $mixed_mode_runner_dir );
$mixed_mode_oracle_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		'1',
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$mixed_mode_runner_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
$mixed_mode_byte_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--mode',
		'bytes',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		'1',
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$mixed_mode_runner_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
$mixed_mode_state = is_file( $mixed_mode_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $mixed_mode_runner_dir . '/state.json' ), true )
	: array();
$mixed_mode_failure_modes = array_unique( array_map( static fn( $seed ): string => $seed['mode'] ?? '', $mixed_mode_state['failure_seeds'] ?? array() ) );
check(
	'runner separates retained same-signature artifacts by mode',
	1 === $mixed_mode_oracle_runner['code'] &&
		1 === $mixed_mode_byte_runner['code'] &&
		in_array( 'bytes', $mixed_mode_failure_modes, true ),
	$mixed_mode_oracle_runner['stdout'] . $mixed_mode_oracle_runner['stderr'] . $mixed_mode_byte_runner['stdout'] . $mixed_mode_byte_runner['stderr'] . json_encode( $mixed_mode_state['artifact_retention'] ?? null )
);
remove_tree( $mixed_mode_runner_dir );

$pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-' . getmypid();
remove_tree( $pipeline_dir );
$faulted_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--seed',
		(string) $skip_c1_fault_seed,
		'--cases',
		'200',
		'--output-dir',
		$pipeline_dir,
		'--progress-every',
		'200',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
check( 'faulted worker reports findings', 1 === $faulted_worker['code'], $faulted_worker['stdout'] . $faulted_worker['stderr'] );

$failure_files = glob( $pipeline_dir . '/failure-*/failure.json' );
check( 'faulted worker writes failure artifact', is_array( $failure_files ) && array() !== $failure_files );

$failure_file = is_array( $failure_files ) && array() !== $failure_files ? $failure_files[0] : null;
if ( null !== $failure_file ) {
	$manifest = json_decode( (string) file_get_contents( $failure_file ), true );
	$detail   = $manifest['failures'][0]['detail'] ?? array();
	check( 'failure artifact includes full expected/got', isset( $detail['expected_base64'], $detail['got_base64'] ) );

	$replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	check( 'faulted replay reproduces finding', 1 === $replay['code'], $replay['stdout'] . $replay['stderr'] );

	$minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	check( 'faulted minimizer preserves signature', 0 === $minimize['code'], $minimize['stdout'] . $minimize['stderr'] );

	$minimize_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-minimize-' . getmypid();
	remove_tree( $minimize_dir );
	$minimize_output_dir = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $failure_file, '--output-dir', $minimize_dir ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	check(
		'minimizer creates requested output directory',
		0 === $minimize_output_dir['code'] && is_file( $minimize_dir . '/minimized.json' ),
		$minimize_output_dir['stdout'] . $minimize_output_dir['stderr']
	);
	remove_tree( $minimize_dir );
}

remove_tree( $pipeline_dir );

$runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-runner-' . getmypid();
remove_tree( $runner_dir );
$faulted_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1000',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'1000',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$runner_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$runner_state = is_file( $runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $runner_dir . '/state.json' ), true )
	: array();
check(
	'faulted runner reports findings',
	1 === $faulted_runner['code'] && ( $runner_state['failures'] ?? 0 ) > 0,
	$faulted_runner['stdout'] . $faulted_runner['stderr']
);
$retained_counts = $runner_state['artifact_retention']['retained_by_signature'] ?? array();
check(
	'faulted runner caps retained artifacts by signature',
	array() !== $retained_counts && array() === array_filter( $retained_counts, static fn( $count ) => $count > 1 ),
	json_encode( $retained_counts )
);
check(
	'faulted runner prunes repeated failure artifacts',
	( $runner_state['artifact_retention']['pruned'] ?? 0 ) > 0,
	json_encode( $runner_state['artifact_retention'] ?? null )
);
$retained_failure_dirs = glob( $runner_dir . '/failure-*/failure.json' );
check(
	'faulted runner prunes over-cap failure directories',
	is_array( $retained_failure_dirs ) && count( $retained_failure_dirs ) === array_sum( $retained_counts ),
	'dirs=' . ( is_array( $retained_failure_dirs ) ? count( $retained_failure_dirs ) : 0 ) . ' counts=' . json_encode( $retained_counts )
);
$runner_summary_failures = array();
if ( is_file( $runner_dir . '/summary.ndjson' ) ) {
	foreach ( file( $runner_dir . '/summary.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
		$summary_record = json_decode( $line, true );
		if ( is_array( $summary_record ) && 'failure' === ( $summary_record['type'] ?? null ) ) {
			$runner_summary_failures[] = $summary_record;
		}
	}
}
check(
	'faulted runner writes bounded default failure summary',
	count( $runner_summary_failures ) === array_sum( $retained_counts ) &&
		( $runner_state['failures'] ?? 0 ) > count( $runner_summary_failures ),
	'failures=' . ( $runner_state['failures'] ?? 0 ) . ' summary_failures=' . count( $runner_summary_failures )
);
$runner_state_failure_seeds = $runner_state['failure_seeds'] ?? array();
check(
	'faulted runner writes bounded failure seed state',
	is_array( $runner_state_failure_seeds ) &&
		count( $runner_state_failure_seeds ) === array_sum( $retained_counts ) &&
		( $runner_state['failures'] ?? 0 ) > count( $runner_state_failure_seeds ),
	'failures=' . ( $runner_state['failures'] ?? 0 ) . ' state_failure_seeds=' . count( $runner_state_failure_seeds )
);

$reuse_same_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1000',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'1000',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$runner_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$reuse_same_state = is_file( $runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $runner_dir . '/state.json' ), true )
	: array();
$reuse_same_counts = $reuse_same_state['artifact_retention']['retained_by_signature'] ?? array();
$reuse_same_dirs   = glob( $runner_dir . '/failure-*/failure.json' );
check(
	'runner preserves retained same-seed artifacts on reuse',
	1 === $reuse_same_runner['code'] &&
		is_array( $reuse_same_dirs ) &&
		count( $reuse_same_dirs ) === array_sum( $reuse_same_counts ) &&
		is_file( $runner_dir . "/failure-seed{$skip_c1_fault_seed}-case{$skip_c1_fault_case}/failure.json" ) &&
		array() === array_filter( $reuse_same_counts, static fn( $count ) => $count > 1 ),
	$reuse_same_runner['stdout'] . $reuse_same_runner['stderr'] . json_encode( $reuse_same_state['artifact_retention'] ?? null )
);
remove_tree( $runner_dir );

$different_signature_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-different-signature-reuse-' . getmypid();
remove_tree( $different_signature_dir );
$different_signature_first = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'100',
		'--output-dir',
		$different_signature_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$different_signature_second = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--max-artifacts-per-signature',
		'100',
		'--artifact-retention',
		'all',
		'--output-dir',
		$different_signature_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'match-length-off-by-one' )
);
$different_signature_case_files = glob( $different_signature_dir . "/failure-seed{$skip_c1_fault_seed}-case{$skip_c1_fault_case}*/failure.json" );
$different_signature_seen   = array();
foreach ( is_array( $different_signature_case_files ) ? $different_signature_case_files : array() as $failure_file ) {
	$manifest = json_decode( (string) file_get_contents( $failure_file ), true );
	if ( is_array( $manifest ) && isset( $manifest['signatures'] ) && is_array( $manifest['signatures'] ) ) {
		$different_signature_seen[] = implode( ',', $manifest['signatures'] );
	}
}
check(
	'runner preserves same-seed artifacts with different signatures',
	1 === $different_signature_first['code'] &&
		1 === $different_signature_second['code'] &&
		in_array( 'decode-mismatch:text,reader-decode-mismatch:text,decode-mismatch:attribute,reader-decode-mismatch:attribute', $different_signature_seen, true ) &&
		in_array( 'reader-decode-mismatch:text,reader-decode-mismatch:attribute', $different_signature_seen, true ),
	$different_signature_first['stdout'] . $different_signature_first['stderr'] . $different_signature_second['stdout'] . $different_signature_second['stderr'] . json_encode( $different_signature_seen )
);
remove_tree( $different_signature_dir );

$overcap_reuse_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-overcap-reuse-' . getmypid();
remove_tree( $overcap_reuse_dir );
$overcap_seed_run = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1000',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'1000',
		'--artifact-retention',
		'all',
		'--output-dir',
		$overcap_reuse_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$overcap_before_dirs = glob( $overcap_reuse_dir . '/failure-*/failure.json' );
$overcap_prune_run   = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--seed-base',
		'9999',
		'--cases-per-batch',
		'1',
		'--max-artifacts-per-signature',
		'1',
		'--output-dir',
		$overcap_reuse_dir,
	)
);
$overcap_state = is_file( $overcap_reuse_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $overcap_reuse_dir . '/state.json' ), true )
	: array();
$overcap_counts = $overcap_state['artifact_retention']['retained_by_signature'] ?? array();
$overcap_after_dirs = glob( $overcap_reuse_dir . '/failure-*/failure.json' );
check(
	'runner prunes reused output dirs back under cap',
	1 === $overcap_seed_run['code'] &&
		0 === $overcap_prune_run['code'] &&
		is_array( $overcap_before_dirs ) &&
		is_array( $overcap_after_dirs ) &&
		count( $overcap_before_dirs ) > count( $overcap_after_dirs ) &&
		( $overcap_state['artifact_retention']['startup_pruned'] ?? 0 ) > 0 &&
		count( $overcap_after_dirs ) === array_sum( $overcap_counts ) &&
		array() === array_filter( $overcap_counts, static fn( $count ) => $count > 1 ),
	$overcap_seed_run['stdout'] . $overcap_seed_run['stderr'] . $overcap_prune_run['stdout'] . $overcap_prune_run['stderr'] . json_encode( $overcap_state['artifact_retention'] ?? null )
);
remove_tree( $overcap_reuse_dir );

$no_artifact_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-no-artifacts-' . getmypid();
remove_tree( $no_artifact_dir );
$no_artifact_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--artifact-retention',
		'none',
		'--output-dir',
		$no_artifact_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$no_artifact_state = is_file( $no_artifact_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $no_artifact_dir . '/state.json' ), true )
	: array();
$no_artifact_dirs = glob( $no_artifact_dir . '/failure-*/failure.json' );
check(
	'runner can prune all failure artifacts',
	1 === $no_artifact_runner['code'] && ( $no_artifact_state['failures'] ?? 0 ) > 0 && ( $no_artifact_state['artifact_retention']['pruned'] ?? 0 ) > 0 && is_array( $no_artifact_dirs ) && 0 === count( $no_artifact_dirs ),
	$no_artifact_runner['stdout'] . $no_artifact_runner['stderr'] . json_encode( $no_artifact_state['artifact_retention'] ?? null )
);
remove_tree( $no_artifact_dir );

$corrupt_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-corrupt-runner-' . getmypid();
remove_tree( $corrupt_runner_dir );
$corrupt_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--output-dir',
		$corrupt_runner_dir,
	),
	array(
		'HTML_DECODER_FUZZ_FAULT'                 => 'skip-c1-remap',
		'HTML_DECODER_FUZZ_CORRUPT_FAILURE_EVENT' => '1',
	)
);
$corrupt_runner_state = is_file( $corrupt_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $corrupt_runner_dir . '/state.json' ), true )
	: array();
check(
	'runner treats malformed finding events as harness errors',
	2 === $corrupt_runner['code'] && ( $corrupt_runner_state['harness_errors'] ?? 0 ) > 0,
	$corrupt_runner['stdout'] . $corrupt_runner['stderr']
);
remove_tree( $corrupt_runner_dir );

$bogus_mode_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-bogus-mode-' . getmypid();
remove_tree( $bogus_mode_dir );
$bogus_mode_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'200',
		'--seed-base',
		(string) $skip_c1_fault_seed,
		'--cases-per-batch',
		'200',
		'--output-dir',
		$bogus_mode_dir,
	),
	array(
		'HTML_DECODER_FUZZ_FAULT'              => 'skip-c1-remap',
		'HTML_DECODER_FUZZ_BOGUS_FAILURE_MODE' => '1',
	)
);
$bogus_mode_state = is_file( $bogus_mode_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $bogus_mode_dir . '/state.json' ), true )
	: array();
check(
	'runner treats bogus failure modes as harness errors',
	2 === $bogus_mode_runner['code'] && ( $bogus_mode_state['harness_errors'] ?? 0 ) > 0,
	$bogus_mode_runner['stdout'] . $bogus_mode_runner['stderr']
);
remove_tree( $bogus_mode_dir );

echo $failed > 0 ? "\n{$failed} smoke check(s) FAILED\n" : "\nAll smoke checks passed\n";
exit( $failed > 0 ? 1 : 0 );
