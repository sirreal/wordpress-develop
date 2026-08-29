<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$table_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Handle TR (table row) opening tags
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			// Start a new row if we have a previous row, save it
			if ( ! empty( $current_row ) || $current_cell_text !== '' ) {
				if ( $current_cell_text !== '' ) {
					$current_row[] = $current_cell_text;
					$current_cell_text = '';
				}
				if ( ! empty( $current_row ) ) {
					$rows[] = $current_row;
				}
				$current_row = array();
			}
		}
		// Handle TR closing tags
		elseif ( '#tag' === $token_type && 'TR' === $token_name && $processor->is_tag_closer() ) {
			// Save the current cell text to current row
			if ( $current_cell_text !== '' ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
			}
		}
		// Handle TD and TH (table cell) opening tags
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			// Save any previous cell text first
			if ( $current_cell_text !== '' ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
			}
		}
		// Handle TD and TH closing tags
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $processor->is_tag_closer() ) {
			// Save the cell text
			$current_row[] = $current_cell_text;
			$current_cell_text = '';
		}
		// Handle text nodes
		elseif ( '#text' === $token_type ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Save any remaining row
	if ( $current_cell_text !== '' ) {
		$current_row[] = $current_cell_text;
	}
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
