<?php
function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2   = null;

	while ( $processor->next_tag( 'h2' ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		if ( ! $processor->set_bookmark( 'last-h2' ) ) {
			return $html;
		}

		$last_h2 = true;
	}

	if ( ! $last_h2 ) {
		return $html;
	}

	if ( ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	$processor->release_bookmark( 'last-h2' );

	return $processor->get_updated_html();
}
