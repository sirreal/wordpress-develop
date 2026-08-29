<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$result = array();
	$current_row = array();
	$table_depth = $processor->get_current_depth();
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Stop when we exit the table
		if ( $current_depth <= $table_depth ) {
			break;
		}
		
		// Handle row openers
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			// Start a new row (flush any existing row first)
			if ( ! empty( $current_row ) ) {
				$result[] = $current_row;
				$current_row = array();
			}
		}
		
		// Handle cell openers (TD and TH)
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			// Collect text content inside the cell
			$cell_text = '';
			$cell_depth = $processor->get_current_depth();
			
			// Walk through tokens inside this cell
			while ( $processor->next_token() ) {
				$token_depth = $processor->get_current_depth();
				$token_type_inner = $processor->get_token_type();
				$token_name_inner = $processor->get_token_name();
				
				// Stop when we exit the cell
				if ( $token_depth <= $cell_depth ) {
					break;
				}
				
				// Collect text nodes (already decoded by get_modifiable_text)
				if ( '#text' === $token_type_inner ) {
					$cell_text .= $processor->get_modifiable_text();
				}
			}
			
			$current_row[] = $cell_text;
		}
		
		// Handle row closers
		if ( '#tag' === $token_type && 'TR' === $token_name && $processor->is_tag_closer() ) {
			// Row is closing, save it if we have content
			if ( ! empty( $current_row ) ) {
				$result[] = $current_row;
				$current_row = array();
			}
		}
	}
	
	// Flush any remaining row
	if ( ! empty( $current_row ) ) {
		$result[] = $current_row;
	}
	
	return $result;
}
