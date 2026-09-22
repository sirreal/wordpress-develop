<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return null;
	}

	$in_h1      = false;
	$h1_depth   = null;
	$text       = '';

	while ( $processor->next_token() ) {
		if ( ! $in_h1 ) {
			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}

			if ( 'H1' !== $processor->get_tag() || $processor->is_tag_closer() ) {
				continue;
			}

			$in_h1    = true;
			$h1_depth = $processor->get_current_depth();
			continue;
		}

		if ( $processor->get_current_depth() < $h1_depth ) {
			return $text;
		}

		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	return $in_h1 ? $text : null;
}
