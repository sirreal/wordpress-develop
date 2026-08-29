<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag() ) {
		// Only process IMG tags in the HTML namespace, not SVG namespace
		if ( 'IMG' === $processor->get_tag() && 'html' === $processor->get_namespace() ) {
			$src = $processor->get_attribute( 'src' );
			
			// Include the src if it exists and is not empty
			// get_attribute returns null if attribute doesn't exist,
			// true for boolean attributes, or a string value (already decoded)
			if ( is_string( $src ) && '' !== $src ) {
				$sources[] = $src;
			}
		}
	}
	
	return $sources;
}
