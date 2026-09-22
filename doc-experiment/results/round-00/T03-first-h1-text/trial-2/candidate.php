<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return null;
	}

	// Find the first H1 opener.
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}

	// Record the depth at which the H1 sits. Its children will be at greater depth.
	$h1_depth = $processor->get_current_depth();

	$text = '';

	// Iterate through subsequent tokens, collecting text inside the H1.
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();

		// If we're back at or above the H1's depth, we've exited the H1.
		if ( $current_depth <= $h1_depth ) {
			break;
		}

		// Collect text nodes inside the H1.
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	return $text;
}
