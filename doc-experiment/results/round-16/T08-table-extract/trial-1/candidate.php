<?php
function table_to_array( string $html ): array {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Handle opening tags for TD and TH cells
		if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			if ( 'TD' === $token_name || 'TH' === $token_name ) {
				$in_cell = true;
				$current_cell_text = '';
			}
		}
		
		// Handle closing tags for TD and TH cells
		if ( '#tag' === $token_type && $processor->is_tag_closer() ) {
			if ( 'TD' === $token_name || 'TH' === $token_name ) {
				$in_cell = false;
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
			}
		}
		
		// Handle opening tag for TR rows
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			$current_row = array();
		}
		
		// Handle closing tag for TR rows
		if ( '#tag' === $token_type && 'TR' === $token_name && $processor->is_tag_closer() ) {
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
			}
			$current_row = array();
		}
		
		// Accumulate text content from text nodes inside cells
		if ( '#text' === $token_type && $in_cell ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	return $rows;
}
