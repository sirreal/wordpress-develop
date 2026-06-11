<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$images = array();

	// Search for IMG tags within FIGURE elements
	while ( $processor->next_tag( array( 'breadcrumbs' => array( 'FIGURE', 'IMG' ) ) ) ) {
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );

		// Skip if src is null, empty string, or boolean true
		if ( is_string( $src ) && '' !== $src ) {
			$images[] = $src;
		}
	}

	return $images;
}
