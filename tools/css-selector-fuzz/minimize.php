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
 * intent. --seed faithfully minimizes only seeds whose failure is
 * self-contained; the generator-side invariants (ast-mismatch,
 * parse-expectation, path-expectation, model-desync) are invisible to
 * run_pair, so a seed whose failure is only those is refused by default
 * (each of the three known bugs DOES also surface a self-contained
 * signature — Bug 1 -> metamorphic-ast, Bug 2 -> match-mismatch-html,
 * Bug 3 -> metamorphic-parse — reachable via --signature).
 *
 * Usage:
 *   php tools/css-selector-fuzz/minimize.php --seed 1234 [--signature SUBSTR]
 *   php tools/css-selector-fuzz/minimize.php --selector 'sel' --html '<…>' [--signature SUBSTR]
 *
 * Options:
 *   --signature SUBSTR  Target a signature whose id or invariant contains
 *                       SUBSTR. For --seed, also the way to opt into a
 *                       related self-contained signature when the seed's own
 *                       failure is generator-side (printed as a retarget).
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

/*
 * In --seed mode, the seed's OWN failures ( from run_case ) are the source
 * of truth. The minimizer can only preserve "self-contained" signatures
 * ( those run_pair re-checks without the generator's intended AST ); the
 * generator-side ones ( ast-mismatch, parse-expectation, path-expectation,
 * model-desync ) are invisible to run_pair. Targeting must therefore be
 * restricted to the intersection of the seed's failures and run_pair's
 * view — otherwise the minimizer could silently retarget to an unrelated
 * incidental signature and report a false "reproduced".
 */
$seed            = option_int( $options, 'seed', -1 );
$seed_signatures = null;
if ( $seed >= 0 ) {
	$case            = Worker::run_case( $seed );
	$selector        = $case['selector'];
	$html            = $case['html'];
	$seed_signatures = $case['signatures'];
	if ( array() === $seed_signatures ) {
		fwrite( STDERR, "Seed {$seed} produced no failure; nothing to minimize.\n" );
		exit( 1 );
	}
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
	if ( null !== $seed_signatures ) {
		fwrite( STDERR, 'Seed failure(s): ' . implode( ', ', $seed_signatures ) . "\n" );
		fwrite( STDERR, "These are generator-side signatures the minimizer cannot reproduce from the\n" );
		fwrite( STDERR, "pair alone. Minimize a seed whose failure is self-contained, or pass\n" );
		fwrite( STDERR, "--selector/--html directly.\n" );
	}
	fwrite( STDERR, 'selector: ' . printable_bytes( $selector ) . "\n" );
	exit( 1 );
}

/*
 * Candidate targets are matched at the INVARIANT level, not the exact
 * signature hash: a signature embeds transform-specific detail ( e.g.
 * metamorphic-parse via `rerender` vs via `dup-branch` ), and run_pair's
 * fixed metamorphic draws may expose the same invariant through a
 * different transform than run_case did. Same invariant == same bug class,
 * so that is faithful. A DIFFERENT invariant ( e.g. the seed's generator-
 * side ast-mismatch vs an incidental self-contained metamorphic-ast ) is a
 * genuine retarget and must be opted into.
 */
$invariant_of = static function ( string $signature ): string {
	$pos = strrpos( $signature, ':' );
	return false === $pos ? $signature : substr( $signature, $pos + 1 );
};

$retargeted = false;
if ( null === $seed_signatures ) {
	$candidates = $baseline;
} else {
	$seed_invariants = array_map( $invariant_of, $seed_signatures );
	$candidates      = array();
	foreach ( $baseline as $signature ) {
		if ( in_array( $invariant_of( $signature ), $seed_invariants, true ) ) {
			$candidates[] = $signature;
		}
	}
}

if ( array() === $candidates ) {
	// The seed's failures are all generator-side ( no self-contained
	// invariant in common ); refuse to silently minimize an unrelated
	// incidental signature.
	fwrite( STDERR, "Seed {$seed}'s failures are not self-contained, so the minimizer cannot\n" );
	fwrite( STDERR, "faithfully reproduce them.\n" );
	fwrite( STDERR, 'Seed failure(s):       ' . implode( ', ', $seed_signatures ) . "\n" );
	fwrite( STDERR, 'Self-contained nearby: ' . implode( ', ', $baseline ) . "\n" );
	fwrite( STDERR, "Re-run with --signature <id> to minimize one of the nearby signatures\n" );
	fwrite( STDERR, "explicitly ( understanding it is a related, not identical, failure ).\n" );
	if ( null === $sig_filter ) {
		exit( 1 );
	}
	// User explicitly opted into a nearby signature.
	$candidates = $baseline;
	$retargeted = true;
}

// Pick the target signature from the eligible candidates.
$target = $candidates[0];
if ( null !== $sig_filter ) {
	foreach ( $candidates as $candidate ) {
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
			'retargeted'    => $retargeted,
			'seedSignatures' => $seed_signatures,
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
if ( $retargeted ) {
	echo 'NOTE:      seed failure(s) ' . implode( ', ', $seed_signatures ) . " are generator-side;\n";
	echo "           minimized the related self-contained signature above instead.\n";
}
echo 'reproduced: ' . ( $ok ? 'yes' : 'NO' ) . "\n";
echo "attempts:  {$attempts}\n";
echo 'selector:  ' . printable_bytes( $selector ) . ' (' . strlen( $selector ) . " bytes)\n";
echo 'html:      ' . printable_bytes( $html ) . ' (' . strlen( $html ) . " bytes)\n";
echo "\nreplay:\n";
echo '  php tools/css-selector-fuzz/replay.php --selector ' . escapeshellarg( $selector )
	. ' --html ' . escapeshellarg( $html ) . "\n";
exit( $ok ? 0 : 2 );
