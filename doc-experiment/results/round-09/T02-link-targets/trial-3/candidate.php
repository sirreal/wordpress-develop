<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if not present, "" if empty, or true if boolean
		$href = $processor->get_attribute( 'href' );
		
		// href counts as present even if empty string or boolean true
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
