#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

\CssDeclarationFuzz\throw_on_php_error();
$options = \CssDeclarationFuzz\parse_cli_options( $argv );

try {
	$summary = \CssDeclarationFuzz\Worker::run_batch( $options );
	echo \CssDeclarationFuzz\json_encode_safe( $summary ) . "\n";
	exit( 0 === $summary['failures'] ? 0 : 2 );
} catch ( Throwable $e ) {
	fwrite(
		STDERR,
		\CssDeclarationFuzz\json_encode_safe(
			array(
				'kind'  => 'css-declaration-fuzz-worker-fatal',
				'error' => \CssDeclarationFuzz\Worker::describe_throwable( $e ),
			)
		) . "\n"
	);
	exit( 1 );
}
