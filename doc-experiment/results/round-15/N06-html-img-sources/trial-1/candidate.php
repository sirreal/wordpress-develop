<?php
/**
 * Collect HTML image sources, excluding SVG image elements.
 *
 * @param string $html HTML fragment to process.
 * @return array Numeric array of decoded src values from HTML img elements.
 */
function collect_html_img_sources( string $html ): array {
	$sources = array();
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag() ) {
		// Only process IMG tags in the HTML namespace
		if ( 'IMG' === $processor->get_tag() && 'html' === $processor->get_namespace() ) {
			// Get the src attribute value (already decoded)
			$src = $processor->get_attribute( 'src' );

			// Skip if src is null, true (boolean), or empty string
			if ( null !== $src && true !== $src && '' !== $src ) {
				$sources[] = $src;
			}
		}
	}

	return $sources;
}
