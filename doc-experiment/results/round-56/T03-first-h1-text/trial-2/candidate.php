<?php

function get_first_h1_text( string $html ): ?string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return null;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return null;
	}

	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}

	if ( $processor->is_tag_closer() ) {
		return null;
	}

	$depth = $processor->get_current_depth();
	$text  = '';

	while ( $processor->next_token() && $processor->get_current_depth() >= $depth ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	return $text;
}
