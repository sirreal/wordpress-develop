<?php
function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current_level = null;
	$current_depth  = null;
	$current_text   = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( null !== $current_level ) {
			if ( $processor->get_current_depth() < $current_depth ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);

				$current_level = null;
				$current_depth = null;
				$current_text  = '';
				continue;
			}

			if ( '#text' === $token_type ) {
				$current_text .= $processor->get_modifiable_text();
			}

			continue;
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || strlen( $tag ) !== 2 || 'H' !== $tag[0] ) {
			continue;
		}

		$level_char = $tag[1];
		if ( $level_char < '1' || $level_char > '6' ) {
			continue;
		}

		$current_level = (int) $level_char;
		$current_depth = $processor->get_current_depth();
		$current_text  = '';
	}

	if ( null !== $current_level ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
