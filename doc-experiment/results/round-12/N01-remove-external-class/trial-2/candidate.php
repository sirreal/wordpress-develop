<?php

function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Remove the 'external' class from each A tag
		$processor->remove_class( 'external' );
	}
	
	return $processor->get_updated_html();
}
