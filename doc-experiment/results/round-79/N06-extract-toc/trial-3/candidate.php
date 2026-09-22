<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc          = array();
	$current      = null;
	$current_depth = null;
	$text         = '';
	$heading_tags = array(
		'H1' => 1,
		'H2' => 2,
		'H3' => 3,
		'H4' => 4,
		'H5' => 5,
		'H6' => 6,
	);

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( null === $current ) {
			if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
				continue;
			}

			$tag_name = $processor->get_tag();
			if ( null === $tag_name || ! isset( $heading_tags[ $tag_name ] ) ) {
				continue;
			}

			$current       = $heading_tags[ $tag_name ];
			$current_depth = $processor->get_current_depth();
			$text          = '';
			continue;
		}

		if ( '#text' === $token_type ) {
			$text .= $processor->get_modifiable_text();
			continue;
		}

		if ( '#tag' === $token_type && $processor->is_tag_closer() && $processor->get_current_depth() < $current_depth ) {
			$toc[] = array(
				'level' => $current,
				'text'  => $text,
			);

			$current       = null;
			$current_depth = null;
			$text          = '';
		}
	}

	if ( null !== $current ) {
		$toc[] = array(
			'level' => $current,
			'text'  => $text,
		);
	}

	return $toc;
}
