<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the 'href' attribute exists
		// get_attribute returns null if not present, true for boolean attributes,
		// or a string value if present
		$href = $processor->get_attribute( 'href' );
		
		// If href attribute exists (not null), set target to _blank
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
