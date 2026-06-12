<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Create an HTML processor for fragment parsing
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $sources;
	}
	
	// Loop through all tags in the document
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only process IMG elements in the HTML namespace
		// SVG IMAGE elements will be in the 'svg' namespace
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Only add if src exists and is not null/empty
		// get_attribute returns null if attribute doesn't exist,
		// and returns the decoded value if it does
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
