<?php

function table_to_array( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	
	// Scan through all tokens within the table
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Stop if we hit the closing TABLE tag
		if ( $token_type === '#tag' && strtoupper( $token_name ) === 'TABLE' && $processor->is_tag_closer() ) {
			// Save any pending cell and row
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$in_cell = false;
				$current_cell_text = '';
			}
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
			}
			break;
		}
		
		// Handle opening TR tag
		if ( $token_type === '#tag' && strtoupper( $token_name ) === 'TR' && ! $processor->is_tag_closer() ) {
			// Save any pending cell and row before starting a new one
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$in_cell = false;
				$current_cell_text = '';
			}
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
			}
			$current_row = array();
			continue;
		}
		
		// Handle closing TR tag
		if ( $token_type === '#tag' && strtoupper( $token_name ) === 'TR' && $processor->is_tag_closer() ) {
			// Save any pending cell before closing the row
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$in_cell = false;
				$current_cell_text = '';
			}
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
			continue;
		}
		
		// Handle opening TD or TH tag
		if ( $token_type === '#tag' && ( strtoupper( $token_name ) === 'TD' || strtoupper( $token_name ) === 'TH' ) && ! $processor->is_tag_closer() ) {
			// Close any previous cell before opening a new one
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
			}
			$in_cell = true;
			continue;
		}
		
		// Handle closing TD or TH tag
		if ( $token_type === '#tag' && ( strtoupper( $token_name ) === 'TD' || strtoupper( $token_name ) === 'TH' ) && $processor->is_tag_closer() ) {
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
			continue;
		}
		
		// Collect text content when inside a cell
		if ( $in_cell && $token_type === '#text' ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	return $rows;
}
