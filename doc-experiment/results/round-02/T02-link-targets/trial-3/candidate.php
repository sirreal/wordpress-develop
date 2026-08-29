<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the A tag has an href attribute (even if empty)
		$href = $processor->get_attribute( 'href' );
		
		// The href attribute counts as present when:
		// - It has a value: get_attribute returns that value as string
		// - It's empty: get_attribute returns empty string ""
		// - It's written without a value: get_attribute returns true
		// The href attribute is NOT present when get_attribute returns null
		
		if ( null !== $href ) {
			// A tag has href attribute, set target to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
