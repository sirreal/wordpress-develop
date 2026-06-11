<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the A tag has an href attribute.
		// get_attribute returns null if attribute doesn't exist,
		// empty string if href="" or <a href>, or a string value.
		$href = $processor->get_attribute( 'href' );
		
		// Only set target="_blank" if href attribute is present.
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
