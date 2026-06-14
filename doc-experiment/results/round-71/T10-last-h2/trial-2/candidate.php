<?php
function mark_last_h2( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$last_bookmark = null;

	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$last_bookmark = 'last-h2';
		$processor->set_bookmark( $last_bookmark );
	}

	if ( null === $last_bookmark ) {
		return $html;
	}

	if ( ! $processor->seek( $last_bookmark ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );

	return $processor->get_updated_html();
}
