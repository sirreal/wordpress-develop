<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the tag has an href attribute
		// get_attribute returns null if not present, "" if empty, or the value
		$href = $processor->get_attribute( 'href' );
		
		// href is considered present if it's not null (includes empty string and boolean true)
		if ( null !== $href ) {
			// Set the target attribute to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
