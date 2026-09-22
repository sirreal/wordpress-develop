<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$blockquote_depth = 0;

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();

		if ( 'BLOCKQUOTE' === $tag_name ) {
			if ( $processor->is_tag_closer() ) {
				$blockquote_depth--;
			} else {
				$blockquote_depth++;
			}
		} elseif ( 'P' === $tag_name && ! $processor->is_tag_closer() ) {
			if ( $blockquote_depth > 0 ) {
				$processor->add_class( 'quoted' );
			}
		}
	}

	return $processor->get_updated_html();
}
