<?php
function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip if this is a tag closer
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Skip if not in HTML namespace (e.g., in SVG foreign content)
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Only add if src exists and has a value
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
