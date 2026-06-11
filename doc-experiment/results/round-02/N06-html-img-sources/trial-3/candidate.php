<?php

/**
 * Collect HTML image sources, excluding SVG images.
 *
 * Given an HTML fragment, returns a list of decoded src values from every HTML img element
 * in document order. SVG image elements are excluded. Images without a src attribute or
 * with an empty src value are skipped.
 *
 * @param string $html The HTML fragment to process.
 * @return array A numeric array of decoded src values.
 */
function collect_html_img_sources( string $html ): array {
	$sources = array();
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $sources;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect img elements in the HTML namespace, not SVG elements
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Only add if src is present and not empty
		if ( ! empty( $src ) ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
