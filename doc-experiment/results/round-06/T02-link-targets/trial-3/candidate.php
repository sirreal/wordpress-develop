<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the A tag has an href attribute
		// get_attribute() returns null if attribute doesn't exist,
		// returns "" (empty string) if href="" or <a href>,
		// returns the value otherwise
		$href = $processor->get_attribute( 'href' );
		
		// Only process A tags that have an href attribute
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
