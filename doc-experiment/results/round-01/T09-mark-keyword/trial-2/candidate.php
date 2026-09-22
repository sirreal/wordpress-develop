<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output = '';

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$decoded_text = $processor->get_modifiable_text();
			if ( str_contains( $decoded_text, $keyword ) ) {
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				$output .= $processor->serialize_token();
			}
		} else {
			$output .= $processor->serialize_token();
		}
	}

	return $output;
}
