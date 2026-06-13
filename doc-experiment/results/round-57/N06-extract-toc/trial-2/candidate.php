<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$capturing       = false;
	$current_level   = null;
	$current_text    = '';
	$heading_depth   = null;
	$heading_tag_set = array(
		'H1' => 1,
		'H2' => 2,
		'H3' => 3,
		'H4' => 4,
		'H5' => 5,
		'H6' => 6,
	);

	while ( $processor->next_token() ) {
		if ( $capturing ) {
			if ( $processor->get_current_depth() < $heading_depth ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);

				$capturing     = false;
				$current_level = null;
				$current_text  = '';
				$heading_depth = null;
				continue;
			}

			if ( '#text' === $processor->get_token_type() ) {
				$current_text .= $processor->get_modifiable_text();
			}

			continue;
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || ! isset( $heading_tag_set[ $tag ] ) ) {
			continue;
		}

		$capturing     = true;
		$current_level = $heading_tag_set[ $tag ];
		$current_text  = '';
		$heading_depth = $processor->get_current_depth();
	}

	if ( $capturing ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
