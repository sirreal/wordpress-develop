<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$table_depth = $processor->get_current_depth();
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	
	// Process all tokens inside the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table - depth equal to table_depth means we're at the table's close tag
		if ( $current_depth <= $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Accumulate text content from text nodes inside cells
		if ( '#text' === $token_type ) {
			if ( in_array( 'TD', $breadcrumbs, true ) || in_array( 'TH', $breadcrumbs, true ) ) {
				$current_cell_text .= $processor->get_modifiable_text();
			}
		}
		
		// Handle tag opens and closes
		if ( '#tag' === $token_type ) {
			if ( ! $processor->is_tag_closer() ) {
				// Opening tag
				if ( 'TD' === $token_name || 'TH' === $token_name ) {
					// Reset cell text for a new cell
					$current_cell_text = '';
				}
			} else {
				// Closing tag
				if ( 'TD' === $token_name || 'TH' === $token_name ) {
					// End of cell - add text to current row
					$current_row[] = $current_cell_text;
					$current_cell_text = '';
				} elseif ( 'TR' === $token_name ) {
					// End of row - add to rows array
					if ( ! empty( $current_row ) ) {
						$rows[] = $current_row;
					}
					$current_row = array();
				}
			}
		}
	}
	
	return $rows;
}
