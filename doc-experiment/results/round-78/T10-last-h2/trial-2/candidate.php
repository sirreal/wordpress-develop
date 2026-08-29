<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$bookmark  = 'last-h2';
	$found     = false;

	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$found = $processor->set_bookmark( $bookmark );
	}

	if ( ! $found ) {
		return $html;
	}

	if ( ! $processor->seek( $bookmark ) ) {
		$processor->release_bookmark( $bookmark );
		return $html;
	}

	$processor->add_class( 'final-section' );
	$processor->release_bookmark( $bookmark );

	return $processor->get_updated_html();
}
