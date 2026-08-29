<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$result = array();

	while ( $processor->next_tag( 'IMG' ) ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE is in the breadcrumbs (at any depth)
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			$src = $processor->get_attribute( 'src' );
			
			// Only include if src exists and is not null or empty
			if ( null !== $src && '' !== $src ) {
				$result[] = $src;
			}
		}
	}

	return $result;
}
