<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if not present, empty string if present but empty
		$href = $processor->get_attribute( 'href' );
		
		// Only set target if href attribute exists (not null)
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}