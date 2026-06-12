<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the href attribute exists
		// get_attribute returns null if attribute doesn't exist,
		// empty string if it exists with no value, or true for boolean attributes
		$href = $processor->get_attribute( 'href' );
		
		// If href attribute is present (not null), set target="_blank"
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
