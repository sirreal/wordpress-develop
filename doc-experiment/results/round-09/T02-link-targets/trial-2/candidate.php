<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the tag has an href attribute
		// get_attribute returns null if not present, "" if empty, or the value if present
		$href = $processor->get_attribute( 'href' );
		
		// href attribute is considered present if it's not null
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
