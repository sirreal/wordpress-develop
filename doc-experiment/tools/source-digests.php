<?php
/**
 * Emits SHA-256 fingerprints for the HTML API source files used by the docs
 * experiment.
 *
 * The behavior fingerprint strips comments and whitespace from PHP's token
 * stream. This mirrors docs-only-guard.php and gives prepared rounds a compact
 * way to prove that only documentation text changed.
 *
 * Usage:
 *   php source-digests.php [--json] [--ref <git-ref>]
 */

$repo_root = dirname( __DIR__, 2 );
$files     = array(
	'src/wp-includes/html-api/class-wp-html-tag-processor.php',
	'src/wp-includes/html-api/class-wp-html-processor.php',
);

$json = false;
$ref  = null;

for ( $i = 1; $i < $argc; $i++ ) {
	if ( '--json' === $argv[ $i ] ) {
		$json = true;
		continue;
	}

	if ( '--ref' === $argv[ $i ] ) {
		if ( ! isset( $argv[ $i + 1 ] ) ) {
			fwrite( STDERR, "source-digests.php: --ref requires a git ref\n" );
			exit( 2 );
		}
		$ref = $argv[++$i];
		continue;
	}

	fwrite( STDERR, "source-digests.php: unknown argument {$argv[$i]}\n" );
	exit( 2 );
}

function source_at_ref( string $repo_root, string $file, ?string $ref ): string {
	if ( null === $ref ) {
		$path   = "{$repo_root}/{$file}";
		$source = file_get_contents( $path );
		if ( false === $source ) {
			throw new RuntimeException( "could not read {$file}" );
		}
		return $source;
	}

	$command = 'git -C ' . escapeshellarg( $repo_root ) . ' show ' . escapeshellarg( "{$ref}:{$file}" ) . ' 2>/dev/null';
	$source  = shell_exec( $command );
	if ( null === $source || '' === $source ) {
		throw new RuntimeException( "could not read {$file} at {$ref}" );
	}
	return $source;
}

function php_behavior_tokens( string $source ): array {
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

try {
	$result = array(
		'ref'                       => $ref ?? 'working-tree',
		'algorithm'                 => 'sha256',
		'php_behavior_fingerprint'  => 'token_get_all with T_COMMENT, T_DOC_COMMENT, and T_WHITESPACE removed; JSON-encoded token names and text',
		'files'                     => array(),
	);

	foreach ( $files as $file ) {
		$source          = source_at_ref( $repo_root, $file, $ref );
		$behavior_tokens = php_behavior_tokens( $source );
		$behavior_json   = json_encode( $behavior_tokens, JSON_UNESCAPED_SLASHES );

		$result['files'][ $file ] = array(
			'source_sha256'                     => hash( 'sha256', $source ),
			'php_without_comments_sha256'       => hash( 'sha256', $behavior_json ),
			'php_without_comments_token_count'  => count( $behavior_tokens ),
		);
	}
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'source-digests.php: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}

if ( $json ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

echo "HTML API source digests ({$result['ref']})\n";
foreach ( $result['files'] as $file => $digests ) {
	echo "- {$file}\n";
	echo "  source_sha256: {$digests['source_sha256']}\n";
	echo "  php_without_comments_sha256: {$digests['php_without_comments_sha256']}\n";
	echo "  php_without_comments_token_count: {$digests['php_without_comments_token_count']}\n";
}
