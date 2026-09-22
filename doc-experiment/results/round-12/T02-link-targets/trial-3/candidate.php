<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the tag has an href attribute
		// get_attribute returns null if the attribute doesn't exist,
		// true for boolean attributes, or a string value
		$href = $processor->get_attribute( 'href' );
		
		// The href attribute counts as present even if empty string or written without value
		// Only modify if href exists (is not null)
		if ( $href !== null ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
