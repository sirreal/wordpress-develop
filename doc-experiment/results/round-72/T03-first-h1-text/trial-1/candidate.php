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

	$target_depth = $processor->get_current_depth();
	$text         = '';

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $target_depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	return $text;
}
