<?php
function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( 'img' ) ) {
		// Only process IMG tags in the HTML namespace, not SVG namespace
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src attribute doesn't exist or is empty
		if ( null === $src || '' === $src || true === $src ) {
			continue;
		}
		
		// Collect the src value (already decoded by the processor)
		$sources[] = $src;
	}
	
	return $sources;
}
