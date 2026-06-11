<?php

function table_to_array( string $html ): array {
	// Create a fragment processor from the HTML.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	// Find the first TABLE element.
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$in_cell = false;
	$cell_text = '';
	$cell_depth = 0;
	$table_depth = $processor->get_current_depth();
	
	// Walk through tokens inside the table.
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table element.
		if ( $current_depth <= $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();
		
		// Handle opening tags for TR.
		if ( '#tag' === $token_type && 'TR' === $tag_name && ! $is_closer ) {
			// Save previous row if it exists.
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		
		// Handle opening tags for TD and TH.
		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $is_closer ) {
			$in_cell = true;
			$cell_text = '';
			$cell_depth = $current_depth;
		}
		// Handle closing tags for TD and TH.
		else if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && $is_closer ) {
			$in_cell = false;
			$current_row[] = $cell_text;
		}
		// Collect text inside cells.
		else if ( $in_cell && '#text' === $token_type ) {
			$cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Add the last row if it has content.
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
