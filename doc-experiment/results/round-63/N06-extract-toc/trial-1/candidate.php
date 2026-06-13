<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc            = array();
	$current_level  = 0;
	$current_tag    = null;
	$current_depth  = null;
	$current_text   = '';
	$heading_levels = array(
		'H1' => 1,
		'H2' => 2,
		'H3' => 3,
		'H4' => 4,
		'H5' => 5,
		'H6' => 6,
	);

	while ( $processor->next_token() ) {
		if ( null !== $current_tag && $processor->get_current_depth() < $current_depth ) {
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);
			$current_tag   = null;
			$current_level = 0;
			$current_depth = null;
			$current_text  = '';
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( null !== $current_tag && $tag_name === $current_tag ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
				$current_tag   = null;
				$current_level = 0;
				$current_depth = null;
				$current_text  = '';
			}
			continue;
		}

		if ( isset( $heading_levels[ $tag_name ] ) ) {
			if ( null !== $current_tag ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
			}

			$current_tag   = $tag_name;
			$current_level = $heading_levels[ $tag_name ];
			$current_depth = $processor->get_current_depth();
			$current_text  = '';
			continue;
		}

		if ( null !== $current_tag && '#text' === $processor->get_token_type() ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current_tag ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
