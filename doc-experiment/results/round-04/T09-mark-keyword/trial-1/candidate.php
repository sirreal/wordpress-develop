<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Return empty string if processor creation failed
	if ( null === $processor ) {
		return '';
	}
	
	// Walk through all tokens and wrap matching text nodes in <mark>
	while ( $processor->next_token() ) {
		// Only process text nodes
		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}
		
		// Get the decoded text content
		$text = $processor->get_modifiable_text();
		
		// Check if the keyword is in this text node (case-sensitive)
		if ( false !== strpos( $text, $keyword ) ) {
			// We need to wrap this text node in a <mark> tag
			// Get the current depth to know where we are
			$depth = $processor->get_current_depth();
			
			// Set a bookmark before the text node so we can insert markup
			$bookmark = 'text_' . uniqid();
			$processor->set_bookmark( $bookmark );
			
			// Serialize this token to get its content
			$original_html = $processor->get_updated_html();
			
			// Release and re-process - we need a different approach
			// Let's use a different strategy: modify the text and use insert wrappers
			
			// Actually, we can't insert markup directly. We need to:
			// 1. Track which text nodes need wrapping
			// 2. Build the output using serialize_token() in a loop
			
			$processor->release_bookmark( $bookmark );
		}
	}
	
	// The above approach won't work. We need to use serialize_token() in a loop
	// Create a fresh processor
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		// Check if this is a text node that needs wrapping
		if ( '#text' === $processor->get_token_type() ) {
			$text = $processor->get_modifiable_text();
			
			// If the keyword is in this text node, wrap it in <mark>
			if ( false !== strpos( $text, $keyword ) ) {
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				$output .= $processor->serialize_token();
			}
		} else {
			// For all other tokens, just serialize them
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
