<?php

function table_to_array( string $html ): array {
	// Create an HTML processor in fragment mode
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$table_depth = $processor->get_current_depth();
	$rows = array();
	$current_row = null;
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $current_depth < $table_depth ) {
			break;
		}
		
		$token_name = $processor->get_token_name();
		$is_opener = ! $processor->is_tag_closer();
		
		// Check for TR (table row) opening tag
		if ( 'TR' === $token_name && $is_opener && $current_depth === $table_depth + 1 ) {
			// If we have a previous row, save it
			if ( $current_row !== null ) {
				$rows[] = $current_row;
			}
			$current_row = array();
		}
		
		// Check for TD or TH (table cell) opening tags
		if ( ( 'TD' === $token_name || 'TH' === $token_name ) && $is_opener && $current_row !== null ) {
			$cell_depth = $current_depth;
			$cell_text = '';
			
			// Collect all text tokens inside this cell
			while ( $processor->next_token() ) {
				$token_depth = $processor->get_current_depth();
				
				// Exit cell when depth drops to cell level (cell closing tag)
				if ( $token_depth < $cell_depth ) {
					break;
				}
				
				// Only collect text nodes at appropriate depth
				if ( '#text' === $processor->get_token_type() && $token_depth > $cell_depth ) {
					$cell_text .= $processor->get_modifiable_text();
				}
			}
			
			$current_row[] = $cell_text;
		}
	}
	
	// Don't forget the last row
	if ( $current_row !== null ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
