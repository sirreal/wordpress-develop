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

		if ( null !== $current_level ) {
			if ( '#text' === $token_type ) {
				$current_text .= $processor->get_modifiable_text();
				continue;
			}

			if ( '#tag' === $token_type && 'H' === substr( (string) $processor->get_token_name(), 0, 1 ) ) {
				if ( $processor->is_tag_closer() ) {
					if ( (string) $current_level === substr( (string) $processor->get_token_name(), 1 ) ) {
						$toc[] = array(
							'level' => $current_level,
							'text'  => $current_text,
						);

						$current_level = null;
						$current_text  = '';
						$heading_depth = null;
					}
				}
			}
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || ! preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
			continue;
		}

		$current_level = (int) $matches[1];
		$current_text  = '';
		$heading_depth = $processor->get_current_depth();
	}

	return $toc;
}
