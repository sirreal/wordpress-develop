<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG elements in HTML namespace, not SVG <image> elements.
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (decoded).
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null or if it's an empty string.
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
