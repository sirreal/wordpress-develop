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

$oracles = Oracles::build();
$events  = $oracles->drain_events();

check( 'required oracles available', $oracles->has_required(), json_encode( $events ) );
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
function fault_run( Oracles $oracles, string $fault, string $payload = 'javascript&colon;alert(1)' ): array {
	$old_fault = getenv( 'HTML_DECODER_FUZZ_FAULT' );
	putenv( "HTML_DECODER_FUZZ_FAULT={$fault}" );

	try {
		$checks = new Checks( $oracles, Targets::resolve() );
		$seen   = array();

		foreach ( $checks->run( 'attribute', $payload ) as $failure ) {
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

$strategies            = array();
$contexts              = array();
$unsafe                = 0;
$reference_at_eof      = 0;
$reference_at_eof_bad = 0;
$reference_at_eof_shapes = array();
$attribute_multicodepoint_prefix = 0;
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
}
check( 'all 11 strategies appear', 11 === count( $strategies ), implode( ',', array_keys( $strategies ) ) );
check( 'generated cases run both contexts', array( 'both' ) === array_keys( $contexts ), implode( ',', array_keys( $contexts ) ) );
check( 'generated payloads are oracle-safe', 0 === $unsafe, (string) $unsafe );
check( 'attribute-prefix generator emits multi-code-point references', $attribute_multicodepoint_prefix > 0, (string) $attribute_multicodepoint_prefix );
check( 'reference-at-EOF cases end inside a reference', $reference_at_eof > 0 && 0 === $reference_at_eof_bad, "{$reference_at_eof_bad}/{$reference_at_eof}" );
check(
	'reference-at-EOF covers expected suffix shapes',
	array() === array_diff(
		array( 'bare-introducer', 'partial-numeric-introducer', 'decimal-digits', 'hex-digits', 'named-prefix' ),
		array_keys( $reference_at_eof_shapes )
	),
	implode( ',', array_keys( $reference_at_eof_shapes ) )
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
$name_runner_summary = is_file( $name_runner_dir . '/summary.ndjson' )
	? file( $name_runner_dir . '/summary.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES )
	: array();
$name_runner_windows = array();
if ( is_array( $name_runner_summary ) ) {
	foreach ( $name_runner_summary as $line ) {
		$record = json_decode( $line, true );
		if ( is_array( $record ) && 'start' === ( $record['type'] ?? null ) && 'names' === ( $record['mode'] ?? null ) ) {
			$name_runner_windows[] = array(
				'start' => $record['start_case'] ?? null,
				'cases' => $record['cases'] ?? null,
			);
		}
	}
}
usort(
	$name_runner_windows,
	static fn( array $a, array $b ): int => ( $a['start'] ?? -1 ) <=> ( $b['start'] ?? -1 )
);
$name_runner_windows_valid = count( $name_runner_windows ) > 1;
$previous_window_end       = null;
foreach ( $name_runner_windows as $window ) {
	if ( ! is_int( $window['start'] ) || 100 !== $window['cases'] || 0 !== $window['start'] % 100 || ( null !== $previous_window_end && $window['start'] < $previous_window_end ) ) {
		$name_runner_windows_valid = false;
		break;
	}
	$previous_window_end = $window['start'] + $window['cases'];
}
check(
	'name-sweep runner uses distinct start-case windows',
	$name_runner_windows_valid,
	json_encode( $name_runner_windows )
);
remove_tree( $name_runner_dir );

$name_replay = run_process( array( PHP_BINARY, __DIR__ . '/../replay.php', '--mode', 'names', '--seed', '1', '--case', '0' ) );
check( 'name-sweep replay regenerates clean case', 0 === $name_replay['code'], $name_replay['stdout'] . $name_replay['stderr'] );

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
$symlink_write_created = @symlink( $symlink_write_dir . '/keepdir', $symlink_write_dir . '/failure-seed1-case128' );
if ( $symlink_write_created ) {
	$symlink_write_worker = run_process(
		array(
			PHP_BINARY,
			__DIR__ . '/../worker.php',
			'--seed',
			'1',
			'--cases',
			'200',
			'--output-dir',
			$symlink_write_dir,
			'--progress-every',
			'200',
		),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	$symlink_write_suffixed = glob( $symlink_write_dir . '/failure-seed1-case128-sig*/failure.json' );
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
		'1',
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
		'1',
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
		is_file( $unverified_weak_manifest_dir . '/failure-seed1-case128/failure.json' ) &&
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
		'1',
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
		is_file( $unverified_fake_manifest_dir . '/failure-seed1-case128/failure.json' ) &&
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
		'1',
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
		'1',
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
		'1',
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
		is_file( $runner_dir . '/failure-seed1-case128/failure.json' ) &&
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
		'1',
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
		'1',
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
$different_signature_case128 = glob( $different_signature_dir . '/failure-seed1-case128*/failure.json' );
$different_signature_seen   = array();
foreach ( is_array( $different_signature_case128 ) ? $different_signature_case128 : array() as $failure_file ) {
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
		'1',
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
		'1',
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
		'1',
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
		'1',
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
