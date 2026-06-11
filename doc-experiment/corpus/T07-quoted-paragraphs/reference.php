<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( 'P' ) ) {
		$ancestors = array_slice( $processor->get_breadcrumbs(), 0, -1 );
		if ( in_array( 'BLOCKQUOTE', $ancestors, true ) ) {
			$processor->add_class( 'quoted' );
		}
	}

	return $processor->get_updated_html();
}
