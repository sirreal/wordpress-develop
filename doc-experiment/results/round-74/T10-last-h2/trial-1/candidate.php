<?php
function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = 'last-h2';
	$found = false;

	while ( $processor->next_tag( 'h2' ) ) {
		$processor->set_bookmark( $last_h2_bookmark );
		$found = true;
	}

	if ( ! $found || ! $processor->seek( $last_h2_bookmark ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	$updated_html = $processor->get_updated_html();
	$processor->release_bookmark( $last_h2_bookmark );

	return $updated_html;
}
