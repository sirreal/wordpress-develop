<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'A' ) ) {
		// Check if the A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		
		// Only set target="_blank" if href exists (even if empty)
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
