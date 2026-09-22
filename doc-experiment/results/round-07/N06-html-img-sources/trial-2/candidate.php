<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag( 'IMG' ) ) {
		// Only collect HTML IMG elements, not SVG image elements
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (already decoded)
		$src = $processor->get_attribute( 'src' );
		
		// Only add if src exists and has a value
		if ( null !== $src && '' !== $src && true !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
