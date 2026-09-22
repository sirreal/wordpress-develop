<?php
/**
 * Collect HTML image sources, not SVG ones.
 *
 * Returns a list of decoded `src` values of every HTML `img` element
 * in document order, excluding SVG `<image>` elements.
 *
 * @param string $html HTML fragment as found inside `<body>`.
 * @return array Numeric array of decoded src values.
 */
function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();

	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect img tags that are in HTML namespace, not SVG namespace.
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		$src = $processor->get_attribute( 'src' );

		// Only include if src attribute exists and has a value.
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
