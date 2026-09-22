<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = null;
	$table_depth = $processor->get_current_depth();
	$current_cell_text = '';
	
	// Walk through all tokens within the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		// Handle TR (table row) opening tag
		if ( 'TR' === $token_name && '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			// If we have a current row, save it
			if ( null !== $current_row ) {
				if ( '' !== $current_cell_text ) {
					$current_row[] = $current_cell_text;
				}
				$rows[] = $current_row;
			}
			$current_row = array();
			$current_cell_text = '';
		}
		
		// Handle TR closing tag
		if ( 'TR' === $token_name && '#tag' === $token_type && $processor->is_tag_closer() ) {
			// Save any pending cell
			if ( null !== $current_row && '' !== $current_cell_text ) {
				$current_row[] = $current_cell_text;
			}
			$current_cell_text = '';
		}
		
		// Handle TD/TH (table cell) opening tag
		if ( ( 'TD' === $token_name || 'TH' === $token_name ) && '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			// Reset for new cell
			$current_cell_text = '';
		}
		
		// Handle TD/TH closing tag
		if ( ( 'TD' === $token_name || 'TH' === $token_name ) && '#tag' === $token_type && $processor->is_tag_closer() ) {
			// Save the cell text
			if ( null !== $current_row ) {
				$current_row[] = $current_cell_text;
			}
			$current_cell_text = '';
		}
		
		// Accumulate text content
		if ( '#text' === $token_type ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Handle the last row if it wasn't closed properly
	if ( null !== $current_row ) {
		if ( '' !== $current_cell_text ) {
			$current_row[] = $current_cell_text;
		}
		if ( count( $current_row ) > 0 ) {
			$rows[] = $current_row;
		}
	}
	
	return $rows;
}
