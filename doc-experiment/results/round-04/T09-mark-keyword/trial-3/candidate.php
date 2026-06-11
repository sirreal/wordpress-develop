<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$output = '';

	while ( $processor->next_token() ) {
		// For text nodes, check if they contain the keyword.
		if ( '#text' === $processor->get_token_type() ) {
			$text = $processor->get_modifiable_text();

			// Match the keyword in the decoded text (case-sensitive substring match).
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a mark element.
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// No match, serialize the text node as-is.
				$output .= $processor->serialize_token();
			}
		} else {
			// For non-text tokens, serialize them as-is.
			$output .= $processor->serialize_token();
		}
	}

	// Normalize the result to ensure proper formatting.
	$final = WP_HTML_Processor::normalize( $output );
	return null !== $final ? $final : $output;
}
