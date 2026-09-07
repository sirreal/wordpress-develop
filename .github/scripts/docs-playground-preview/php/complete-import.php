<?php
/**
 * Completes the Code Reference import before final runtime policy is applied.
 */

update_option( 'wp_parser_root_import_dir', '/tmp/docs-preview-source' );
if ( ! DevHub_Parser::cache_source_code() ) {
	throw new RuntimeException( 'Source-code caching did not complete.' );
}

// The Blueprint deletes the parser plugin once the import is done.
update_option(
	'active_plugins',
	array_values(
		array_diff(
			get_option( 'active_plugins', array() ),
			array( 'phpdoc-parser/plugin.php' )
		)
	)
);

/*
 * The build reads this marker through the health route: it is the only proof
 * that the import ran to the end rather than stopping partway.
 */
file_put_contents(
	WP_CONTENT_DIR . '/docs-preview-import.json',
	wp_json_encode( array( 'stage' => 'complete-import' ) ) . "\n"
);

flush_rewrite_rules( false );
