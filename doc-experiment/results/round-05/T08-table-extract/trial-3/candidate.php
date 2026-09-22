<?php

function table_to_array( string $html ): array {
	// Create a processor to parse the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$result = array();
	$current_row = array();
	$inside_cell = false;
	$cell_text = '';
	
	// Record the depth of the TABLE so we know when we've exited it
	$table_depth = $processor->get_current_depth();
	
	// Iterate through all tokens inside the TABLE
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		
		// Stop when we exit the TABLE
		if ( $current_depth < $table_depth ) {
			break;
		}
		
		// If we're on a text node and inside a cell, accumulate text
		if ( '#text' === $token_type && $inside_cell ) {
			$text = $processor->get_modifiable_text();
			// The text is already decoded by get_modifiable_text()
			$cell_text .= $text;
		}
		
		// Opening tag for TD or TH
		if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				$inside_cell = true;
				$cell_text = '';
			}
		}
		
		// Closing tag for TD or TH
		if ( '#tag' === $token_type && $processor->is_tag_closer() ) {
			if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				if ( $inside_cell ) {
					$current_row[] = $cell_text;
					$inside_cell = false;
					$cell_text = '';
				}
			}
			// TR closing tag - save the row
			elseif ( 'TR' === $tag_name ) {
				if ( ! empty( $current_row ) ) {
					$result[] = $current_row;
					$current_row = array();
				}
			}
		}
	}
	
	// Handle the last row if it exists
	if ( ! empty( $current_row ) ) {
		$result[] = $current_row;
	}
	
	return $result;
}
