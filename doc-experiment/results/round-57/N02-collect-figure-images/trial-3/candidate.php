<?php

function collect_figure_images( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$images  = array();
	$figures = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( 'FIGURE' === $tag_name ) {
			if ( ! $processor->is_tag_closer() ) {
				$figures[] = $processor->get_current_depth();
			} elseif ( ! empty( $figures ) ) {
				array_pop( $figures );
			}
			continue;
		}

		if ( 'IMG' !== $tag_name || $processor->is_tag_closer() || empty( $figures ) ) {
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
