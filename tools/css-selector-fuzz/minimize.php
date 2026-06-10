#!/usr/bin/env php
<?php
/**
 * Delta-debugging minimizer for CSS-selector fuzz failures.
 *
 * Given a failing case — by seed, or by explicit selector/html — shrinks
 * BOTH the selector string and the HTML document to a minimal pair that
 * still reproduces a chosen failure signature, then prints the reproducer.
 *
 * The minimizer drives Worker::run_pair, which checks only self-contained
 * invariants (computable from the pair alone), so it needs no generator
 * intent. The three known bugs reduce to self-contained signatures:
 * Bug 1 -> metamorphic-ast, Bug 2 -> match-mismatch-html, Bug 3 ->
 * metamorphic-parse.
 *
 * Usage:
 *   php tools/css-selector-fuzz/minimize.php --seed 1234 [--signature SUBSTR]
 *   php tools/css-selector-fuzz/minimize.php --selector 'sel' --html '<…>' [--signature SUBSTR]
 *
 * Options:
 *   --signature SUBSTR  Target a signature whose id or invariant contains
 *                       SUBSTR (default: the first signature of the seed's
 *                       failure set).
 *   --max-attempts N    Cap test evaluations (default 4000).
 *   --json              Emit the reproducer as JSON.
 */

require_once __DIR__ . '/lib/autoload.php';

use CssSelectorFuzz\Worker;
use function CssSelectorFuzz\json_encode_safe;
use function CssSelectorFuzz\option_bool;
use function CssSelectorFuzz\option_int;
use function CssSelectorFuzz\option_string;
use function CssSelectorFuzz\parse_cli_options;
use function CssSelectorFuzz\printable_bytes;

$options      = parse_cli_options( $argv );
$max_attempts = option_int( $options, 'max-attempts', 20000 );
$sig_filter   = option_string( $options, 'signature', null );

$seed = option_int( $options, 'seed', -1 );
if ( $seed >= 0 ) {
	$case     = Worker::run_case( $seed );
	$selector = $case['selector'];
	$html     = $case['html'];
} else {
	$selector = option_string( $options, 'selector', null );
	$html     = option_string( $options, 'html', null );
	if ( null === $selector || null === $html ) {
		fwrite( STDERR, "Provide --seed N, or both --selector and --html.\n" );
		exit( 1 );
	}
}

/** Signatures produced by a pair ( $target lets run_pair short-circuit ). */
$signatures_of = static function ( string $selector, string $html, ?string $target = null ): array {
	return Worker::run_pair( $selector, $html, $target )['signatures'];
};

$baseline = $signatures_of( $selector, $html );
if ( array() === $baseline ) {
	fwrite( STDERR, "The starting pair does not reproduce any self-contained failure.\n" );
	fwrite( STDERR, 'selector: ' . printable_bytes( $selector ) . "\n" );
	exit( 1 );
}

// Pick the target signature.
$target = $baseline[0];
if ( null !== $sig_filter ) {
	foreach ( $baseline as $candidate ) {
		if ( false !== strpos( $candidate, $sig_filter ) ) {
			$target = $candidate;
			break;
		}
	}
}

$attempts = 0;
$reproduces = static function ( string $selector, string $html ) use ( $signatures_of, $target, &$attempts, $max_attempts ): bool {
	if ( $attempts >= $max_attempts ) {
		return false;
	}
	++$attempts;
	return in_array( $target, $signatures_of( $selector, $html, $target ), true );
};

/**
 * Delta-debugging shrink of one byte string: ddmin chunk removal followed
 * by per-position single-byte simplification. $test( candidate ) decides
 * whether a candidate still reproduces.
 */
$shrink = static function ( string $current, callable $test ) use ( &$attempts, $max_attempts ): string {
	$chunks = 2;
	while ( strlen( $current ) > 0 && $attempts < $max_attempts ) {
		$length     = strlen( $current );
		$chunk_size = (int) ceil( $length / $chunks );
		$changed    = false;

		for ( $offset = 0; $offset < $length && $attempts < $max_attempts; $offset += $chunk_size ) {
			$candidate = substr( $current, 0, $offset ) . substr( $current, min( $length, $offset + $chunk_size ) );
			if ( $candidate === $current ) {
				continue;
			}
			if ( $test( $candidate ) ) {
				$current = $candidate;
				$chunks  = max( 2, $chunks - 1 );
				$changed = true;
				break;
			}
		}

		if ( ! $changed ) {
			if ( $chunks >= $length ) {
				break;
			}
			$chunks = min( $length, $chunks * 2 );
		}
	}

	// Per-byte canonicalization: replace each byte with a simpler stand-in.
	$replacements = array( 'a', ' ', '' );
	for ( $i = 0; $i < strlen( $current ) && $attempts < $max_attempts; $i++ ) {
		foreach ( $replacements as $replacement ) {
			$candidate = substr( $current, 0, $i ) . $replacement . substr( $current, $i + 1 );
			if ( $candidate === $current ) {
				continue;
			}
			if ( $test( $candidate ) ) {
				$current = $candidate;
				$i       = max( -1, $i - 2 );
				break;
			}
		}
	}

	return $current;
};

// Alternate shrinking the HTML and the selector until neither moves.
// HTML first: when the signature is selector-only (e.g. metamorphic-parse)
// the document collapses cheaply before the costlier selector pass.
$prev = null;
while ( $attempts < $max_attempts && ( $selector . "\0" . $html ) !== $prev ) {
	$prev = $selector . "\0" . $html;

	$html = $shrink(
		$html,
		static function ( string $candidate ) use ( $reproduces, &$selector ): bool {
			return $reproduces( $selector, $candidate );
		}
	);
	$selector = $shrink(
		$selector,
		static function ( string $candidate ) use ( $reproduces, &$html ): bool {
			return $reproduces( $candidate, $html );
		}
	);
}

$final = $signatures_of( $selector, $html );
$ok    = in_array( $target, $final, true );

if ( option_bool( $options, 'json', false ) ) {
	echo json_encode_safe(
		array(
			'target'        => $target,
			'reproduced'    => $ok,
			'attempts'      => $attempts,
			'selector'      => printable_bytes( $selector ),
			'selectorBytes' => strlen( $selector ),
			'html'          => printable_bytes( $html ),
			'htmlBytes'     => strlen( $html ),
			'selectorBase64' => base64_encode( $selector ),
			'htmlBase64'     => base64_encode( $html ),
		)
	) . "\n";
	exit( $ok ? 0 : 2 );
}

echo "target:    {$target}\n";
echo 'reproduced: ' . ( $ok ? 'yes' : 'NO' ) . "\n";
echo "attempts:  {$attempts}\n";
echo 'selector:  ' . printable_bytes( $selector ) . ' (' . strlen( $selector ) . " bytes)\n";
echo 'html:      ' . printable_bytes( $html ) . ' (' . strlen( $html ) . " bytes)\n";
echo "\nreplay:\n";
echo '  php tools/css-selector-fuzz/replay.php --selector ' . escapeshellarg( $selector )
	. ' --html ' . escapeshellarg( $html ) . "\n";
exit( $ok ? 0 : 2 );
