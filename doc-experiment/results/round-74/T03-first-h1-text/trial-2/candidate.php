<?php
function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return null;
	}

	while ( $processor->next_tag( 'H1' ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
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

	return null;
}
