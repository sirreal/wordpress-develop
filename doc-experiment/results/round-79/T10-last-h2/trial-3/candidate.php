<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$bookmark  = null;

	while ( $processor->next_tag( 'h2' ) ) {
		$bookmark = 'last-h2';
		$processor->set_bookmark( $bookmark );
	}

	if ( null === $bookmark ) {
		return $html;
	}

	if ( ! $processor->seek( $bookmark ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	return $processor->get_updated_html();
}
