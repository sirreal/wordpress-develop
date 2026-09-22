<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the tag has an href attribute
		// The attribute counts as present even if value is empty or written without a value
		$href = $processor->get_attribute( 'href' );
		
		// href attribute is present if get_attribute returns something other than null
		// It can be a string (including empty string "") or true (for <a href>)
		if ( null !== $href ) {
			// Set the target attribute to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
