<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$text      = '';
	$count     = 0;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Only extract text from text nodes
		if ( '#text' !== $token_type ) {
			continue;
		}

		$chunk = $processor->get_modifiable_text();
		if ( '' === $chunk ) {
			continue;
		}

		// Count code points in the chunk
		$chunk_codepoints = mb_strlen( $chunk, 'UTF-8' );

		if ( $count + $chunk_codepoints <= $max_codepoints ) {
			// Entire chunk fits
			$text  .= $chunk;
			$count += $chunk_codepoints;
		} else {
			// Need to truncate this chunk
			$remaining = $max_codepoints - $count;
			$text      .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
			break;
		}
	}

	return $text;
}
