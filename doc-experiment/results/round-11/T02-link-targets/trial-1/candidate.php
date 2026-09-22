<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the tag has an href attribute (even if empty)
		$href = $processor->get_attribute( 'href' );
		
		// If href attribute exists (not null), set the target attribute
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
