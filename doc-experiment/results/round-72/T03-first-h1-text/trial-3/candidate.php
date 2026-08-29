<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return null;
	}

	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}

	$depth = $processor->get_current_depth();
	$text  = '';

	if ( '#text' === $processor->get_token_type() ) {
		$text .= $processor->get_modifiable_text();
	}

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return null;
	}

	return $text;
}
