<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'A' ) ) {
		// Check if the href attribute exists (not null)
		$href = $processor->get_attribute( 'href' );
		
		// href is present if it's not null (can be empty string or true for boolean-like attributes)
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
