<?php

function extract_toc( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$heading_levels = array(
		'H1' => 1,
		'H2' => 2,
		'H3' => 3,
		'H4' => 4,
		'H5' => 5,
		'H6' => 6,
	);

	$toc             = array();
	$current_heading = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$depth      = $processor->get_current_depth();

		if ( null !== $current_heading && $depth < $current_heading['depth'] ) {
			$toc[] = array(
				'level' => $current_heading['level'],
				'text'  => $current_heading['text'],
			);
			$current_heading = null;
		}

		if ( '#text' === $token_type ) {
			if ( null !== $current_heading ) {
				$current_heading['text'] .= $processor->get_modifiable_text();
			}

			continue;
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || ! isset( $heading_levels[ $tag_name ] ) ) {
			continue;
		}

		if ( null !== $current_heading ) {
			$toc[] = array(
				'level' => $current_heading['level'],
				'text'  => $current_heading['text'],
			);
		}

		$current_heading = array(
			'level' => $heading_levels[ $tag_name ],
			'text'  => '',
			'depth' => $depth,
		);
	}

	if ( null !== $current_heading ) {
		$toc[] = array(
			'level' => $current_heading['level'],
			'text'  => $current_heading['text'],
		);
	}

	return $toc;
}
