<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the tag has an href attribute
		// get_attribute returns null if attribute doesn't exist,
		// "" if empty, or true for boolean attributes
		$href = $processor->get_attribute( 'href' );
		
		if ( $href !== null ) {
			// href attribute exists (either with a value, empty, or as href without value)
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
