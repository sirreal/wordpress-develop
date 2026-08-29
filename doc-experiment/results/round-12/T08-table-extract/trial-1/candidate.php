<?php
function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$table_depth = $processor->get_current_depth();
	$cell_text = '';
	$in_cell = false;
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		$token_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();
		$is_closer = $processor->is_tag_closer();
		
		// TR opening tag
		if ( 'TR' === $token_name && ! $is_closer && $token_type === '#tag' ) {
			// Save previous row if any
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		
		// TR closing tag
		if ( 'TR' === $token_name && $is_closer ) {
			// Flush the current row if we were in a cell
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$cell_text = '';
				$in_cell = false;
			}
			
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		
		// TD or TH opening tag
		if ( ( 'TD' === $token_name || 'TH' === $token_name ) && ! $is_closer && $token_type === '#tag' ) {
			// End previous cell if any
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$cell_text = '';
			}
			
			// Start new cell
			$in_cell = true;
		}
		
		// TD or TH closing tag
		if ( ( 'TD' === $token_name || 'TH' === $token_name ) && $is_closer ) {
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$cell_text = '';
				$in_cell = false;
			}
		}
		
		// Collect text inside cells
		if ( $in_cell && '#text' === $token_type ) {
			$cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Handle the last row/cell if not properly closed
	if ( $in_cell ) {
		$current_row[] = $cell_text;
	}
	
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
