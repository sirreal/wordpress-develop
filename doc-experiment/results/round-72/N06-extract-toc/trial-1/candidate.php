<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$current_level = null;
	$current_text  = '';
	$heading_depth = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( null !== $current_level ) {
			if ( '#text' === $token_type ) {
				$current_text .= $processor->get_modifiable_text();
			}

			if ( $processor->get_current_depth() < $heading_depth ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);

				$current_level = null;
				$current_text  = '';
				$heading_depth = null;
			}
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			continue;
		}

		if ( null !== $current_level ) {
			// HTML closes one heading when another heading starts; flush the prior one defensively.
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);

			$current_level = null;
			$current_text  = '';
			$heading_depth = null;
		}

		if ( in_array( $token_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			$current_level = (int) substr( $token_name, 1 );
			$current_text  = '';
			$heading_depth = $processor->get_current_depth();
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
