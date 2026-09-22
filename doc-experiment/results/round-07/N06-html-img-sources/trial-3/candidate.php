<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only process HTML namespace img tags, not SVG image tags
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null (not present), true (boolean), or empty string
		if ( null === $src || true === $src || '' === $src ) {
			continue;
		}
		
		// src is a decoded string at this point
		$sources[] = $src;
	}
	
	return $sources;
}
