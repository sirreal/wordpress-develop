<?php
function mark_last_h2( string $html ): string {
	$p = new WP_HTML_Tag_Processor( $html );

	$found = false;
	while ( $p->next_tag( 'H2' ) ) {
		$p->set_bookmark( 'last-h2' );
		$found = true;
	}

	if ( ! $found ) {
		return $html;
	}

	$p->seek( 'last-h2' );
	$p->add_class( 'final-section' );

	return $p->get_updated_html();
}
