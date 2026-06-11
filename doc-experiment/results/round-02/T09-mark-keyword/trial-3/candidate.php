<?php

function mark_keyword( string $html, string $keyword ): string {
	if ( empty( $keyword ) ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( $processor === null ) {
			return $html;
		}
		$result = $processor->serialize();
		return $result !== null ? $result : $html;
	}

	// Normalize first to have consistent HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( $processor === null ) {
		return $html;
	}
	
	$normalized_html = $processor->serialize();
	if ( $normalized_html === null ) {
		return $html;
	}

	// Now parse the normalized HTML and identify matching text nodes
	$processor = WP_HTML_Processor::create_fragment( $normalized_html );
	if ( $processor === null ) {
		return $normalized_html;
	}

	$tokens = array();
	$matching_indices = array();
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$text_content = '';
		
		if ( '#text' === $token_type ) {
			$text_content = $processor->get_modifiable_text();
			if ( strpos( $text_content, $keyword ) !== false ) {
				$matching_indices[ count( $tokens ) ] = true;
			}
		}
		
		$tokens[] = array(
			'type' => $token_type,
			'text' => $text_content,
		);
	}

	if ( empty( $matching_indices ) ) {
		return $normalized_html;
	}

	// Rebuild normalized HTML with mark tags
	$result = '';
	$pos = 0;
	$token_index = 0;

	while ( $pos < strlen( $normalized_html ) && $token_index < count( $tokens ) ) {
		$token = $tokens[ $token_index ];
		
		if ( '#text' === $token['type'] ) {
			// Find the text in the normalized HTML
			// Skip whitespace or tags if needed
			
			// Text should be next (after any tags)
			while ( $pos < strlen( $normalized_html ) && $normalized_html[$pos] === '<' ) {
				$close = strpos( $normalized_html, '>', $pos );
				if ( $close === false ) {
					break;
				}
				$result .= substr( $normalized_html, $pos, $close - $pos + 1 );
				$pos = $close + 1;
			}
			
			// Now extract the text
			$text_start = $pos;
			$text_end = $pos;
			
			// Find where text ends (at next < or end of string)
			while ( $text_end < strlen( $normalized_html ) && $normalized_html[$text_end] !== '<' ) {
				$text_end++;
			}
			
			if ( $text_end > $text_start ) {
				$text_segment = substr( $normalized_html, $text_start, $text_end - $text_start );
				
				if ( isset( $matching_indices[ $token_index ] ) ) {
					$result .= '<mark>' . $text_segment . '</mark>';
				} else {
					$result .= $text_segment;
				}
				$pos = $text_end;
			}
		} else {
			// Non-text token, find and copy it
			if ( $pos < strlen( $normalized_html ) && $normalized_html[$pos] === '<' ) {
				$close = strpos( $normalized_html, '>', $pos );
				if ( $close !== false ) {
					$result .= substr( $normalized_html, $pos, $close - $pos + 1 );
					$pos = $close + 1;
				}
			}
		}
		
		$token_index++;
	}

	// Append any remaining content
	if ( $pos < strlen( $normalized_html ) ) {
		$result .= substr( $normalized_html, $pos );
	}

	// Final normalization
	$final_processor = WP_HTML_Processor::create_fragment( $result );
	if ( $final_processor === null ) {
		return $result;
	}

	$final_result = $final_processor->serialize();
	return $final_result !== null ? $final_result : $result;
}
