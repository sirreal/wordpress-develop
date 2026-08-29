<?php

/**
 * Collect images inside figures.
 *
 * Given an HTML fragment, return a list of the decoded src values
 * of every IMG element that is inside a FIGURE element — at any depth,
 * not only as a direct child — in document order. Images outside any
 * figure are excluded. Skip IMG tags that have no src attribute or
 * whose src has no value.
 *
 * @param string $html HTML fragment to parse.
 * @return array Numeric array of decoded src values for IMG elements inside FIGURE elements.
 */
function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$images = array();

	// Find all IMG tags and check if they're inside a FIGURE
	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		// Get the breadcrumbs to check if FIGURE is an ancestor
		$breadcrumbs = $processor->get_breadcrumbs();

		// Check if FIGURE appears anywhere in the breadcrumbs
		// (excluding implicit HTML and BODY at the start)
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			// Get the src attribute (already decoded)
			$src = $processor->get_attribute( 'src' );

			// Only include if src exists and is not empty
			if ( null !== $src && '' !== $src && true !== $src ) {
				$images[] = $src;
			}
		}
	}

	return $images;
}
