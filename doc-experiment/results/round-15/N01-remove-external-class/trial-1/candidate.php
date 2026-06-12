<?php

function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		$processor->remove_class( 'external' );
	}
	
	return $processor->get_updated_html();
}
