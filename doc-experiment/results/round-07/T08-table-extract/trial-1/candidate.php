<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$table_depth = $processor->get_current_depth();
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	
	// Iterate through all tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();
		
		// Handle closing tags for TD and TH
		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && $is_closer ) {
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
		}
		
		// Handle closing tags for TR
		if ( '#tag' === $token_type && 'TR' === $tag_name && $is_closer ) {
			// Save the current row when TR closes
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		
		// Handle opening tags for TD and TH
		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $is_closer ) {
			$in_cell = true;
			$current_cell_text = '';
		}
		
		// Collect text nodes inside cells
		if ( '#text' === $token_type && $in_cell ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	return $rows;
}
