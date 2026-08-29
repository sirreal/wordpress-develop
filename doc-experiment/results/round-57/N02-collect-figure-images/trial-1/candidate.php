<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$images       = array();
	$figure_depths = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( 'FIGURE' === $tag_name ) {
			if ( $processor->is_tag_closer() ) {
				array_pop( $figure_depths );
			} else {
				$figure_depths[] = $processor->get_current_depth();
			}
			continue;
		}

		if ( 'IMG' !== $tag_name || $processor->is_tag_closer() ) {
			continue;
		}

		if ( empty( $figure_depths ) ) {
			continue;
		}

		$src = $processor->get_attribute( 'src' );
		if ( is_string( $src ) && '' !== $src ) {
			$images[] = $src;
		}
	}

	return $images;
}
