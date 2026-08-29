<?php
/**
 * Verifies that working-tree changes to the two HTML API class files are
 * docs-only: the PHP token stream with comments and whitespace stripped
 * must be identical to the version at HEAD.
 *
 * Usage: php docs-only-guard.php
 * Exit 0 when clean, 1 when code changed (or a file fails to lint).
 */

$repo_root = dirname( __DIR__, 2 );
$files     = array(
	'src/wp-includes/html-api/class-wp-html-tag-processor.php',
	'src/wp-includes/html-api/class-wp-html-processor.php',
);

function code_fingerprint( string $source ): array {
	$tokens = token_get_all( $source );
	$code   = array();
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
				continue;
			}
			$code[] = array( token_name( $token[0] ), $token[1] );
		} else {
			$code[] = $token;
		}
	}
	return $code;
}

$failed = false;
foreach ( $files as $file ) {
	$path = "{$repo_root}/{$file}";

	exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $lint_out, $lint_status );
	if ( 0 !== $lint_status ) {
		echo "LINT FAIL {$file}\n" . implode( "\n", $lint_out ) . "\n";
		$failed = true;
		continue;
	}

	$head_source = shell_exec( 'git -C ' . escapeshellarg( $repo_root ) . ' show HEAD:' . escapeshellarg( $file ) . ' 2>/dev/null' );
	if ( null === $head_source || '' === $head_source ) {
		echo "ERROR: could not read {$file} at HEAD\n";
		$failed = true;
		continue;
	}

	$head_code = code_fingerprint( $head_source );
	$work_code = code_fingerprint( file_get_contents( $path ) );

	if ( $head_code !== $work_code ) {
		$max = max( count( $head_code ), count( $work_code ) );
		for ( $i = 0; $i < $max; $i++ ) {
			if ( ( $head_code[ $i ] ?? null ) !== ( $work_code[ $i ] ?? null ) ) {
				echo "CODE CHANGED {$file} at code-token #{$i}:\n";
				echo '  HEAD: ' . json_encode( $head_code[ $i ] ?? '<<end>>' ) . "\n";
				echo '  WORK: ' . json_encode( $work_code[ $i ] ?? '<<end>>' ) . "\n";
				break;
			}
		}
		$failed = true;
	} else {
		echo "OK {$file}\n";
	}
}

exit( $failed ? 1 : 0 );
