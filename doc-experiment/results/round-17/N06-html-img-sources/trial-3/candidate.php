<?php
function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip SVG image elements - they are in the svg namespace
		if ( 'svg' === $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Only include if src exists and is not null or empty
		// get_attribute returns null if attribute doesn't exist
		// get_attribute returns true for boolean attributes
		// We want string values only, and skip empty strings
		if ( is_string( $src ) && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
