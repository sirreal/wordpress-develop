<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$output = '';
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			// Check if the decoded text contains the keyword (case-sensitive substring match)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Output the text node as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// Output all other tokens (tags, comments, etc.) as-is
			$output .= $processor->serialize_token();
		}
	}

	return $output;
}
