<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$images = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() || 'IMG' !== $processor->get_tag() ) {
			continue;
		}

		if ( ! in_array( 'FIGURE', $processor->get_breadcrumbs(), true ) ) {
			continue;
		}

		$src = $processor->get_attribute( 'src' );

		if ( null === $src || '' === $src ) {
			continue;
		}

		$images[] = $src;
	}

	return $images;
}
