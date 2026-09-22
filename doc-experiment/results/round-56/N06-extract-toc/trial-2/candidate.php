<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$current_level = null;
	$current_text  = '';
	$current_depth = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$depth      = $processor->get_current_depth();

		if ( null !== $current_level && $depth < $current_depth ) {
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);

			$current_level = null;
			$current_text  = '';
			$current_depth = null;
		}

		if ( '#tag' === $token_type && null !== $token_name && ! $processor->is_tag_closer() ) {
			if ( in_array( $token_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
				if ( null !== $current_level ) {
					$toc[] = array(
						'level' => $current_level,
						'text'  => $current_text,
					);
				}

				$current_level = (int) substr( $token_name, 1, 1 );
				$current_text  = '';
				$current_depth = $depth;
				continue;
			}
		}

		if ( null !== $current_level && '#text' === $token_type && $depth >= $current_depth ) {
			$current_text .= $processor->get_modifiable_text();
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
