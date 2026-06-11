<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( 'img' ) ) {
		// Skip IMG elements in SVG or other foreign content namespaces.
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (already decoded by the API).
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is not present (null) or is an empty string.
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
