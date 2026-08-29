<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag( 'img' ) ) {
		// Skip SVG <image> elements by checking namespace
		// SVG image elements will be in 'svg' namespace, HTML img in 'html'
		if ( 'svg' === $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute (returns decoded string or null)
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is missing, null, or is a boolean attribute (true)
		if ( null !== $src && true !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
