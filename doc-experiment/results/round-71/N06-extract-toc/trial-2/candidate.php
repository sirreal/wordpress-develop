<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$current_heading_level = null;
	$current_heading_text  = '';
	$current_heading_depth = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( null !== $current_heading_level ) {
			if ( '#text' === $token_type ) {
				$current_heading_text .= $processor->get_modifiable_text();
				continue;
			}

			if (
				'#tag' === $token_type &&
				$processor->is_tag_closer() &&
				$processor->get_current_depth() < $current_heading_depth
			) {
				$toc[] = array(
					'level' => $current_heading_level,
					'text'  => $current_heading_text,
				);

				$current_heading_level = null;
				$current_heading_text  = '';
				$current_heading_depth = null;
			}
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() || null === $token_name ) {
			continue;
		}

		if ( 2 !== strlen( $token_name ) || 'H' !== $token_name[0] ) {
			continue;
		}

		$level = ord( $token_name[1] ) - 48;
		if ( $level < 1 || $level > 6 ) {
			continue;
		}

		$current_heading_level = $level;
		$current_heading_text  = '';
		$current_heading_depth = $processor->get_current_depth();
	}

	if ( null !== $current_heading_level ) {
		$toc[] = array(
			'level' => $current_heading_level,
			'text'  => $current_heading_text,
		);
	}

	return $toc;
}
