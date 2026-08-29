<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the tag has an href attribute
		// get_attribute returns null if not present, true for boolean, or string for regular attributes
		$href = $processor->get_attribute( 'href' );
		
		// href attribute must be present (even if empty)
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
