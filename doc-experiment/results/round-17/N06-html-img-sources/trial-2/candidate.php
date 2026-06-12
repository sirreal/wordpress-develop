<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect img tags in the HTML namespace, not SVG image elements
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (decoded by the processor)
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null (attribute not present) or true (boolean attribute, shouldn't happen for src)
		if ( null === $src || true === $src ) {
			continue;
		}
		
		// Skip if src is empty string
		if ( '' === $src ) {
			continue;
		}
		
		$sources[] = $src;
	}
	
	return $sources;
}
