<?php

function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find all A tags that have the 'external' class
	while ( $processor->next_tag( array( 'tag_name' => 'A', 'class_name' => 'external' ) ) ) {
		// Remove the 'external' class from the matched tag
		$processor->remove_class( 'external' );
	}
	
	// Return the modified HTML with all updates applied
	return $processor->get_updated_html();
}
