<?php
/**
 * Extract plain text content from an HTML fragment with length limit.
 *
 * @param string $html The HTML fragment to extract text from.
 * @param int $max_codepoints Maximum number of Unicode code points to return.
 * @return string The extracted text, truncated to the specified code point limit.
 */
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Return empty string if max_codepoints is zero or negative
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Walk through all tokens in the document
	while ( $processor->next_token() ) {
		// Only collect text from #text tokens
		if ( '#text' === $processor->get_token_type() ) {
			$chunk = $processor->get_modifiable_text();
			
			// Check if adding this chunk would exceed the limit
			$chunk_codepoints = mb_strlen( $chunk, 'UTF-8' );
			$remaining = $max_codepoints - $codepoint_count;

			if ( $chunk_codepoints <= $remaining ) {
				// Chunk fits entirely
				$text .= $chunk;
				$codepoint_count += $chunk_codepoints;
			} else {
				// Chunk needs to be truncated
				$text .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}
		}
	}

	return $text;
}
