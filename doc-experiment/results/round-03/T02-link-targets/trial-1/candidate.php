<?php
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find each A tag
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if attribute is not present,
		// a string value (possibly empty) if present,
		// or true if it's a boolean attribute
		$href = $processor->get_attribute( 'href' );
		
		// If href attribute exists (not null), set target to _blank
		if ( $href !== null ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
?>