<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG elements in the HTML namespace, not SVG image elements
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		$src = $processor->get_attribute( 'src' );
		
		// Only include if src exists and has a value (not null, not true, not empty string)
		if ( $src && is_string( $src ) && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
