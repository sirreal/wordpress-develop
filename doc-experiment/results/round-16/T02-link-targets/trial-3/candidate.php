<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the tag has an href attribute (including href="" or href without value)
		$href = $processor->get_attribute( 'href' );
		
		// The href attribute counts as present if it's null (not present), true (boolean),
		// or any string value including empty string. We only want to skip if get_attribute
		// returns null, which means the attribute is completely absent.
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
