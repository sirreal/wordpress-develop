<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( null === $tag || 2 !== strlen( $tag ) || 'H' !== $tag[0] ) {
			continue;
		}

		$level = ord( $tag[1] ) - 48;
		if ( $level < 1 || $level > 6 || $processor->is_tag_closer() ) {
			continue;
		}

		$depth = $processor->get_current_depth();
		$text  = '';

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $depth ) {
				break;
			}

			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$toc[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}

	return $toc;
}
