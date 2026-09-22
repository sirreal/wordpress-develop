<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( 'img' ) ) {
		// Only collect IMG elements in the HTML namespace, not in SVG
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Only include if src exists and has a non-empty value
		if ( null !== $src && true !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
