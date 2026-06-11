<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Loop through all A tags
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the href attribute exists (null means not present)
		$href = $processor->get_attribute( 'href' );
		
		// If href attribute is present (even if empty string or boolean true), set target
		if ( $href !== null ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
