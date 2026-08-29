<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$result = array();

	// Find all IMG tags
	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		// Check if IMG is inside a FIGURE at any depth
		$breadcrumbs = $processor->get_breadcrumbs();
		if ( ! in_array( 'FIGURE', $breadcrumbs, true ) ) {
			continue;
		}

		// Get the src attribute
		$src = $processor->get_attribute( 'src' );

		// Skip if src is null or empty
		if ( null === $src || '' === $src ) {
			continue;
		}

		// src is already decoded by the HTML Processor
		$result[] = $src;
	}

	return $result;
}
