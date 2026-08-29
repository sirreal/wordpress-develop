<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$table_depth = $processor->get_current_depth();
	$rows = array();
	$current_row = null;
	$current_cell_text = null;
	
	// Walk through tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$is_closer = $processor->is_tag_closer();
		
		// Handle TR (table row) opening tags
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $is_closer ) {
			$current_row = array();
		}
		
		// Handle TR closing tags - flush the row
		if ( '#tag' === $token_type && 'TR' === $token_name && $is_closer ) {
			if ( null !== $current_row ) {
				$rows[] = $current_row;
				$current_row = null;
			}
		}
		
		// Handle TD and TH opening tags
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $is_closer ) {
			$current_cell_text = '';
		}
		
		// Handle TD and TH closing tags - save the cell
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $is_closer ) {
			if ( null !== $current_row && null !== $current_cell_text ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = null;
			}
		}
		
		// Collect text from #text tokens
		if ( '#text' === $token_type && null !== $current_cell_text ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Handle case where table might not be closed properly
	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
