<?php
function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag( 'IMG' ) ) {
		// Only process IMG elements in the HTML namespace, not SVG namespace
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Only include if src attribute exists and has a value
		if ( null !== $src && '' !== $src && true !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
?>