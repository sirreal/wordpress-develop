<?php

function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find all A tags that have the "external" class
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if this tag has the "external" class (case-sensitive)
		if ( $processor->has_class( 'external' ) ) {
			$processor->remove_class( 'external' );
		}
	}
	
	return $processor->get_updated_html();
}
