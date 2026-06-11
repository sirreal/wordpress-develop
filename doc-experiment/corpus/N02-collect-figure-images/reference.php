<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();
	while ( $processor->next_tag( 'IMG' ) ) {
		$ancestors = array_slice( $processor->get_breadcrumbs(), 0, -1 );
		if ( ! in_array( 'FIGURE', $ancestors, true ) ) {
			continue;
		}

		$src = $processor->get_attribute( 'src' );
		if ( is_string( $src ) && '' !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
