<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the A tag has an href attribute
		// get_attribute() returns null if attribute doesn't exist
		// It returns "" or true for present attributes
		$href = $processor->get_attribute( 'href' );
		
		if ( $href !== null ) {
			// href attribute is present (even if empty or boolean)
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
