<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current_heading = null;
	$heading_depth = null;
	$heading_text = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( null !== $current_heading ) {
			if ( '#text' === $token_type ) {
				$heading_text .= $processor->get_modifiable_text();
				continue;
			}

			if ( $token_name === $current_heading && $processor->is_tag_closer() ) {
				$toc[] = array(
					'level' => (int) substr( $current_heading, 1 ),
					'text'  => $heading_text,
				);

				$current_heading = null;
				$heading_depth   = null;
				$heading_text    = '';
			}

			continue;
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() || null === $token_name ) {
			continue;
		}

		if ( ! preg_match( '/^H[1-6]$/', $token_name ) ) {
			continue;
		}

		$current_heading = $token_name;
		$heading_depth   = $processor->get_current_depth();
		$heading_text    = '';
	}

	if ( null !== $current_heading ) {
		$toc[] = array(
			'level' => (int) substr( $current_heading, 1 ),
			'text'  => $heading_text,
		);
	}

	return $toc;
}
