<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Use WP_HTML_Tag_Processor to iterate through all tokens
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		// Fallback if the class is not available
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$text = '';

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		// Only collect text from #text nodes
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints without cutting multi-byte characters
	// Use mb_substr to truncate by code points
	$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );

	return $text;
}
