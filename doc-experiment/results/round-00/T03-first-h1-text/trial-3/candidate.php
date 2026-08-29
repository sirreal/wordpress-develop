<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return null;
	}

	// Find the first H1 opening tag.
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}

	// Record the depth of the H1 element.
	$h1_depth = $processor->get_current_depth();
	$text     = '';

	// Iterate through subsequent tokens, collecting text inside the H1.
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();

		// If depth has returned to H1's level or above, we've left the H1.
		if ( $current_depth <= $h1_depth ) {
			break;
		}

		// Collect text node content.
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	return $text;
}
