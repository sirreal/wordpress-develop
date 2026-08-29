<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip if this IMG is in the SVG namespace (it would be an SVG <image> element)
		if ( 'svg' === $processor->get_namespace() ) {
			continue;
		}
		
		$src = $processor->get_attribute( 'src' );
		
		// Include the src only if it's a non-empty string
		if ( is_string( $src ) && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
