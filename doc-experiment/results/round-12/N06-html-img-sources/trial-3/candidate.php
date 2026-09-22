<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG tags in the HTML namespace, not SVG <image> elements
		if ( 'html' === $processor->get_namespace() ) {
			$src = $processor->get_attribute( 'src' );
			
			// Skip if src is null (attribute doesn't exist) or if src is boolean true
			// (which happens for boolean attributes with no value)
			if ( is_string( $src ) && '' !== $src ) {
				$sources[] = $src;
			}
		}
	}
	
	return $sources;
}
