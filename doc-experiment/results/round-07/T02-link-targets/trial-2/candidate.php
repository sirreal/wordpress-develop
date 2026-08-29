<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the tag has an href attribute (even if empty)
		$href = $processor->get_attribute( 'href' );
		if ( null !== $href ) {
			// href attribute is present (could be '', true, or a string value)
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
