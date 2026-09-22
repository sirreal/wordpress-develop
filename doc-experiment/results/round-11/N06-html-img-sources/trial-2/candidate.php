<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip if this IMG is in SVG namespace (SVG <image> elements are different)
		if ( 'svg' === $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null or empty string
		if ( null === $src || '' === $src ) {
			continue;
		}
		
		// Add the decoded src value to the results
		$sources[] = $src;
	}
	
	return $sources;
}
