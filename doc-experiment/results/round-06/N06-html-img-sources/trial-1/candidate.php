<?php
/**
 * Collect HTML image sources, not SVG ones.
 *
 * Given an HTML fragment (as found inside <body>), return a list (numeric array)
 * of the decoded `src` values of every HTML `img` element — as a browser would
 * understand the document — in document order. SVG `<image>` elements (inside `<svg>`)
 * are a different element in a different namespace and must be excluded.
 * Skip images that have no `src` attribute or whose `src` has no value.
 *
 * @param string $html HTML fragment to process.
 * @return array List of decoded src values from HTML img elements in document order.
 */
function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Create a fragment processor to properly parse HTML as a browser would
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $sources;
	}
	
	// Iterate through all tags in the document
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only process IMG tags in HTML namespace, not in foreign content (SVG)
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (already decoded by the API)
		$src = $processor->get_attribute( 'src' );
		
		// Only include if src exists and has a non-empty value
		if ( null !== $src && '' !== $src && true !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}