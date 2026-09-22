<?php
function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	while ( $processor->next_tag( 'h2' ) ) {
		$last_h2_bookmark = 'last-h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}

	if ( null === $last_h2_bookmark ) {
		return $html;
	}

	if ( $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( $last_h2_bookmark );

	return $processor->get_updated_html();
}
