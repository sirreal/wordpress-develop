<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell = null;
	$table_depth = $processor->get_current_depth();
	
	// Walk through all tokens inside the table using a single loop
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		$token_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();
		$is_closer = $processor->is_tag_closer();
		
		// Stop when we exit the table
		if ( $depth <= $table_depth ) {
			break;
		}
		
		// Handle row opening
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $is_closer ) {
			$current_row = array();
		}
		
		// Handle row closing - save the row
		if ( '#tag' === $token_type && 'TR' === $token_name && $is_closer ) {
			$rows[] = $current_row;
			$current_row = array();
		}
		
		// Handle cell opening (TD or TH)
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $is_closer ) {
			$current_cell = '';
		}
		
		// Handle cell closing
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $is_closer ) {
			if ( null !== $current_cell ) {
				$current_row[] = $current_cell;
				$current_cell = null;
			}
		}
		
		// Collect text from #text nodes when inside a cell
		if ( '#text' === $token_type && null !== $current_cell ) {
			$current_cell .= $processor->get_modifiable_text();
		}
	}
	
	return $rows;
}
