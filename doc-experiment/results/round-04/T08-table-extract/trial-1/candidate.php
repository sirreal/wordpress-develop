<?php

function table_to_array( string $html ): array {
	// Create an HTML processor to parse the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();
	
	// Step through tokens inside the table
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		$token_type = $processor->get_token_type();
		$is_closer = $processor->is_tag_closer();
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table (table closer)
		if ( $tag === 'TABLE' && $is_closer ) {
			// Save the last row if it has content
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
			}
			break;
		}
		
		// Handle opening TR tags (row opener)
		if ( $tag === 'TR' && ! $is_closer ) {
			// Save previous row if we're starting a new one
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
			}
			$current_row = array();
			continue;
		}
		
		// Handle opening TD and TH cells
		if ( ( $tag === 'TD' || $tag === 'TH' ) && ! $is_closer ) {
			$in_cell = true;
			$current_cell_text = '';
			continue;
		}
		
		// Handle closing TD and TH cells
		if ( ( $tag === 'TD' || $tag === 'TH' ) && $is_closer ) {
			$in_cell = false;
			$current_row[] = $current_cell_text;
			$current_cell_text = '';
			continue;
		}
		
		// Collect text inside cells
		if ( $in_cell && $token_type === '#text' ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	return $rows;
}
