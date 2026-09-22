<?php
/**
 * Collect HTML image sources, excluding SVG image elements.
 *
 * Given an HTML fragment, returns a list of decoded `src` values from every
 * HTML `img` element in document order, excluding SVG `<image>` elements.
 *
 * @param string $html HTML fragment to process.
 * @return array Numeric array of src attribute values from IMG elements.
 */
function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();

	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip SVG image elements by checking namespace.
		// HTML img elements are in the 'html' namespace.
		// SVG image elements are in the 'svg' namespace.
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		// Get the src attribute value.
		$src = $processor->get_attribute( 'src' );

		// Include only if src exists and is not empty/null.
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
