<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return null;
	}

	// Find the first H1 opener tag.
	$found_h1 = false;
	$h1_depth  = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type()
			&& 'H1' === $processor->get_tag()
			&& ! $processor->is_tag_closer()
		) {
			$found_h1 = true;
			$h1_depth = $processor->get_current_depth();
			break;
		}
	}

	if ( ! $found_h1 ) {
		return null;
	}

	// Collect all text nodes inside the H1.
	$text = '';

	while ( $processor->next_token() ) {
		// If we've returned to H1's depth, we've exited the H1.
		if ( $processor->get_current_depth() <= $h1_depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	return $text;
}
