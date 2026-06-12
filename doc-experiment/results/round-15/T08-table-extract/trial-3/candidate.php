<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$table_depth = $processor->get_current_depth();
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		if ( '#text' === $token_type ) {
			// Accumulate text content
			$current_cell_text .= $processor->get_modifiable_text();
		} elseif ( '#tag' === $token_type ) {
			if ( $processor->is_tag_closer() ) {
				// Handle closing tags
				if ( 'TD' === $token_name || 'TH' === $token_name ) {
					// End of a cell - add the accumulated text to the current row
					$current_row[] = $current_cell_text;
					$current_cell_text = '';
				} elseif ( 'TR' === $token_name ) {
					// End of a row - add the row to rows
					$rows[] = $current_row;
					$current_row = array();
				}
			}
		}
	}
	
	return $rows;
}
