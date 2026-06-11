<?php
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag() ) {
		// Check if this is an A tag
		if ( 'A' !== $processor->get_tag() ) {
			continue;
		}
		
		// Check if the href attribute exists (including empty values)
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			// href attribute doesn't exist, skip this tag
			continue;
		}
		
		// href exists (could be '', true, or a string value)
		// Set the target attribute to _blank
		$processor->set_attribute( 'target', '_blank' );
	}
	
	return $processor->get_updated_html();
}
