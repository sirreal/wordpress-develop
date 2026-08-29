<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip IMG elements that are in SVG namespace (foreign content).
		// Only HTML namespace IMG elements are real img elements.
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (already decoded by get_attribute).
		$src = $processor->get_attribute( 'src' );
		
		// Include the source only if it exists and is not null or empty.
		// get_attribute returns null if attribute doesn't exist,
		// and empty string if attribute exists but has no value.
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
