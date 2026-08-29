<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();
	
	// Walk through tokens inside the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $current_depth <= $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$is_closer = $processor->is_tag_closer();
		
		// Handle TR (table row) opening tag
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $is_closer ) {
			// If we have a previous row, save it
			if ( count( $current_row ) > 0 ) {
				$rows[] = $current_row;
				$current_row = array();
			}
			$current_cell_text = '';
			$in_cell = false;
		}
		// Handle TR closing tag
		elseif ( '#tag' === $token_type && 'TR' === $token_name && $is_closer ) {
			// Save the current cell if we're in one
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
		}
		// Handle TD/TH opening tag
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $is_closer ) {
			// Save previous cell if we're in one (handles omitted </td> / </th>)
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
			}
			$in_cell = true;
		}
		// Handle TD/TH closing tag
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $is_closer ) {
			// Save the cell text
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
		}
		// Handle text nodes
		elseif ( '#text' === $token_type ) {
			if ( $in_cell ) {
				$current_cell_text .= $processor->get_modifiable_text();
			}
		}
	}
	
	// Don't forget the last row if we have one
	if ( count( $current_row ) > 0 ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
