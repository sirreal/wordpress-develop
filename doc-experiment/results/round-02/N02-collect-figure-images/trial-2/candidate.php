<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$images = array();

	// Find all IMG tags that are inside a FIGURE element
	while ( $processor->next_tag(
		array(
			'tag_name'   => 'img',
			'breadcrumbs' => array( 'figure', 'img' ),
		)
	) ) {
		$src = $processor->get_attribute( 'src' );

		// Skip if src attribute is missing or empty
		if ( null === $src || '' === $src ) {
			continue;
		}

		// Decode HTML entities in the src value
		$decoded_src = html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$images[]    = $decoded_src;
	}

	return $images;
}
