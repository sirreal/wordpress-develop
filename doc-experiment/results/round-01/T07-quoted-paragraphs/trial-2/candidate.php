<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( 'P' ) ) {
		// Skip tag closers; we only want opening P tags.
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// Check if BLOCKQUOTE is anywhere in the breadcrumbs (i.e. is an ancestor).
		$breadcrumbs = $processor->get_breadcrumbs();
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			$processor->add_class( 'quoted' );
		}
	}

	// If the processor aborted due to unsupported HTML, return the original.
	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
