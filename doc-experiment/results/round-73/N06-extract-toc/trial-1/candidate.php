<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc            = array();
	$current_level  = null;
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
		$token_name = $processor->get_token_name();

		if ( null !== $current_level ) {
			if ( '#text' === $processor->get_token_type() ) {
				if ( $processor->get_current_depth() >= $current_depth ) {
					$current_text .= $processor->get_modifiable_text();
				}
			} elseif ( $token_name && isset( $heading_levels[ $token_name ] ) ) {
				if ( $processor->is_tag_closer() ) {
					if ( $token_name === array_search( $current_level, $heading_levels, true ) ) {
						$toc[] = array(
							'level' => $current_level,
							'text'  => $current_text,
						);
						$current_level = null;
						$current_depth  = null;
						$current_text   = '';
						continue;
					}
				} else {
					$toc[] = array(
						'level' => $current_level,
						'text'  => $current_text,
					);
					$current_level = null;
					$current_depth  = null;
					$current_text   = '';
				}
			}
		}

		if ( null === $current_level && $token_name && isset( $heading_levels[ $token_name ] ) && ! $processor->is_tag_closer() ) {
			$current_level = $heading_levels[ $token_name ];
			$current_depth = $processor->get_current_depth();
			$current_text  = '';
		}
	}

	if ( null !== $current_level ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
